import hmac
import logging
import os
import re
import time
from datetime import datetime, timezone
from typing import Annotated

import requests
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from llm import generate_answer
from product_catalog import fetch_products, is_semantic_product_question, product_to_document, select_products
from prompt_builder import FALLBACK_ANSWER, build_prompt
from retriever import retrieve
from web_search import search_public_web

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("foodbyk-rag")

app = FastAPI(title="Food by K Customer FAQ", docs_url=None, redoc_url=None)

TRANSACTIONAL_PATTERN = re.compile(
    r"\b(payment|payfast|refund|charge|charged|card|checkout|"
    r"tracking|track|status|time slot|delivery fee|"
    r"delivery area|delivery availability|delivery eligibility|delivery time|"
    r"account|my details)\b|"
    r"\b(my|the)\s+orders?\b|\borders?\s+(status|tracking|update|progress|history|number)\b|"
    r"\b(where is|track|cancel|change|check)\s+(my|the)\s+orders?\b|"
    r"\b(collection|collect)\s+(?:slot|availability|eligibility|time)\b|\bwhen (?:is|will) my collection\b|"
    r"\b(do you deliver|deliver to (me|my|this)|is delivery available)\b|"
    r"\b(how do|how can) i (place|submit|make) an? order\b",
    re.IGNORECASE,
)
PRODUCT_PATTERN = re.compile(
    r"\b(menu|menus|food|product|products|item|items|price|prices|cost|"
    r"sell|serve|offer|offers|burger|kota|fries|wings|chicken|chips|drink|platter|"
    r"ingredients?|allergens?|contains?|how much|recommend(?:ation|ed)?|suggest(?:ion|ed)?|"
    r"filling|hearty|satisfying|substantial|hungry|meal|something to eat|fill me up|big appetite|good to eat|cheap|cheapest|affordable|budget)\b|"
    r"\bwhat (?:would you|do you) recommend\b|"
    r"\bwhat(?:'s| is) good\b|\bwhat should i (?:get|try|eat)\b|"
    r"\b(?:i am|i'm|im) (?:hungry|starving)\b|\blooking for something to eat\b",
    re.IGNORECASE,
)
POLICY_PATTERN = re.compile(
    r"\b(open|opening|hours?|close[sd]?|closing|trading|location|located|address|contact|"
    r"phone|whatsapp|instagram|facebook|tiktok|about|business|restaurant|shop|reach)\b|"
    r"\b(what time|which days|when do you open|when do you close|what times do they close)\b|"
    r"where are you|where is the shop",
    re.IGNORECASE,
)
PERSONAL_DATA_PATTERN = re.compile(
    r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b|"
    r"(?:\+27|0)\s?(?:\d[\s-]?){9}\b|"
    r"\b\d{13,19}\b|\b(?:my|home|delivery)\s+address\b|"
    r"\b\d{1,5}\s+[A-Z0-9.'-]+\s+(?:street|st|road|rd|avenue|ave|drive|dr|lane|ln|close|crescent|way)\b",
    re.IGNORECASE,
)
CAPABILITY_PATTERN = re.compile(
    r"\bwhat\s+(?:can|do)\s+(?:you|this assistant|the assistant)\s+(?:answer|help with|know)\b|"
    r"\bwhat questions?\s+(?:can|should)\s+(?:i|we)\s+ask\b|"
    r"\bwhat can\s+(?:i|we)\s+ask(?:\s+about)?\b|"
    r"\bhow can\s+(?:you|this assistant|the assistant)\s+help\b|"
    r"\bwhat do\s+(?:you|this assistant|the assistant)\s+know\b|"
    r"\bwhat can (?:you|u) help (?:me )?with\b|\bwhat are you able to help with\b",
    re.IGNORECASE,
)
CAPABILITY_REPLY = (
    "I can answer questions about the current menu, including item descriptions, "
    "prices and availability, suggest a meal based on its description, and share "
    "Food by K's published hours, location and contact details. I can't check or "
    "change orders, payments, delivery or collection decisions, or customer accounts. "
    "Try asking: ‘What can I get for a filling meal?’ or ‘What time do you close?’"
)
TRANSACTIONAL_REPLY = (
    "I can help with Food by K's menu and approved general FAQs. "
    "For orders, payments, delivery, collection, or account questions, please contact the Food by K team."
)
PRIVACY_REPLY = (
    "For your privacy, please don't share personal, order, or payment details here. "
    "I can answer menu and general Food by K FAQ questions; contact the team for account-specific help."
)


class AskRequest(BaseModel):
    question: str = Field(min_length=1, max_length=500)


@app.get("/health")
def health():
    return {"status": "ok"}


def _require_backend_key(provided_key: Annotated[str | None, Header(alias="X-RAG-Service-Key")] = None) -> None:
    expected_key = os.getenv("RAG_SERVICE_API_KEY", "")
    if not expected_key:
        raise HTTPException(status_code=503, detail="Assistant is not configured")
    if not provided_key or not hmac.compare_digest(provided_key, expected_key):
        raise HTTPException(status_code=401, detail="Unauthorized")


def _source_list(chunks: list[dict]) -> list[dict]:
    sources = []
    seen = set()
    for chunk in chunks:
        source = str(chunk.get("source", "")).strip()[:160]
        url = str(chunk.get("url", "")).strip()
        identity = (source, url)
        if not source or identity in seen:
            continue
        seen.add(identity)
        item = {"source": source}
        if url.startswith("https://"):
            item["url"] = url[:500]
        if chunk.get("verified") is False:
            item["verified"] = False
        sources.append(item)
        if len(sources) == 5:
            break
    return sources


def _fuse_context(chunks: list[dict]) -> list[dict]:
    """Deduplicate evidence and apply source authority/freshness before prompting."""
    unique = {}
    now = datetime.now(timezone.utc)
    for chunk in chunks:
        text = " ".join(str(chunk.get("text", "")).lower().split())
        if not text:
            continue
        identity = re.sub(r"[^a-z0-9]+", " ", text).strip()
        previous = unique.get(identity)
        if previous is None or chunk.get("url"):
            unique[identity] = chunk

    def rank(chunk: dict) -> tuple[float, float]:
        if chunk.get("doc_type") == "product":
            authority = 4.0
        elif str(chunk.get("url", "")).startswith(("https://foodbyk.co.za/", "https://foodbyks.netlify.app/")):
            authority = 3.0
        elif chunk.get("doc_type") == "external":
            authority = 1.0
        else:
            authority = 2.0
        freshness = 1.0
        stamp = str(chunk.get("fetched_at", ""))
        if stamp:
            try:
                age_days = (now - datetime.fromisoformat(stamp.replace("Z", "+00:00"))).days
                freshness = 1.0 if age_days <= 180 else 0.85
            except ValueError:
                pass
        return authority * freshness, float(chunk.get("relevance", 0.0))

    return sorted(unique.values(), key=rank, reverse=True)[:6]


@app.post("/ask")
def ask(
    request: AskRequest,
    provided_key: Annotated[str | None, Header(alias="X-RAG-Service-Key")] = None,
):
    request_started = time.perf_counter()
    _require_backend_key(provided_key)
    question = request.question.strip()
    if not question:
        raise HTTPException(status_code=422, detail="Question cannot be empty")

    if PERSONAL_DATA_PATTERN.search(question):
        logger.info("ask_ms=%.1f route=privacy", (time.perf_counter() - request_started) * 1000)
        return {"answer": PRIVACY_REPLY, "sources": []}

    if CAPABILITY_PATTERN.search(question):
        logger.info("ask_ms=%.1f route=capability", (time.perf_counter() - request_started) * 1000)
        return {"answer": CAPABILITY_REPLY, "sources": []}

    if TRANSACTIONAL_PATTERN.search(question):
        logger.info("ask_ms=%.1f route=handoff", (time.perf_counter() - request_started) * 1000)
        return {"answer": TRANSACTIONAL_REPLY, "sources": []}

    is_policy_question = bool(POLICY_PATTERN.search(question))
    is_product_question = bool(PRODUCT_PATTERN.search(question)) or (
        not is_policy_question and bool(re.search(r"\bwhat is|what are|do you have|what do you have\b", question, re.IGNORECASE))
    )
    if not is_product_question and not is_policy_question:
        intent_started = time.perf_counter()
        try:
            is_product_question = is_semantic_product_question(question)
        except Exception as exc:
            logger.warning("Semantic intent classification unavailable (%s)", type(exc).__name__)
        logger.info("intent_classification_ms=%.1f product=%s", (time.perf_counter() - intent_started) * 1000, is_product_question)
    chunks = retrieve(question)

    if is_product_question:
        try:
            products = fetch_products()
        except (requests.RequestException, RuntimeError, ValueError) as exc:
            logger.warning("Food by K product API unavailable (%s)", type(exc).__name__)
            if not chunks:
                raise HTTPException(status_code=503, detail="Menu information is temporarily unavailable") from None
        else:
            for product in select_products(question, products):
                text, metadata = product_to_document(product)
                chunks.append({"text": text, **metadata})

    if not chunks and not is_product_question:
        chunks.extend(search_public_web(question))

    chunks = _fuse_context(chunks)

    if not chunks:
        logger.info("ask_ms=%.1f route=fallback", (time.perf_counter() - request_started) * 1000)
        return {"answer": FALLBACK_ANSWER, "sources": []}

    try:
        context_started = time.perf_counter()
        prompt = build_prompt(question, chunks)
        logger.info("context_build_ms=%.1f evidence=%d", (time.perf_counter() - context_started) * 1000, len(chunks))
        answer = generate_answer(prompt).strip()
    except Exception as exc:
        logger.error("Food by K answer generation failed (%s); ask_ms=%.1f", type(exc).__name__, (time.perf_counter() - request_started) * 1000)
        raise HTTPException(status_code=503, detail="Assistant is temporarily unavailable") from None

    if not answer or len(answer) > 3000:
        logger.info("ask_ms=%.1f route=invalid_answer", (time.perf_counter() - request_started) * 1000)
        return {"answer": FALLBACK_ANSWER, "sources": []}

    logger.info("ask_ms=%.1f route=generated sources=%d", (time.perf_counter() - request_started) * 1000, len(chunks))
    return {"answer": answer, "sources": _source_list(chunks)}
