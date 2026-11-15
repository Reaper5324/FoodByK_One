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
    r"\b(my|the)\s+(payment|payfast|refund|charge|charged|card|checkout)\b|"
    r"\b(payment|payfast|refund|charge|charged|card|checkout)\s+(status|issue|problem|confirmation|failed|went through)\b|"
    r"\b(did|has|was)\s+(my\s+)?(payment|card|charge)\b|"
    r"\b(can|could|may)\s+i\s+(get|have|request)\s+(a\s+)?refund\b|"
    r"\b(tracking|track|status|time slot|delivery fee|"
    r"delivery area|delivery availability|delivery eligibility|delivery time|"
    r"account|my details)\b|"
    r"\b(my|the)\s+orders?\b|\borders?\s+(status|tracking|update|progress|history|number)\b|"
    r"\b(where is|track|cancel|change|check)\s+(my|the)\s+orders?\b|"
    r"\b(collection|collect)\s+(?:slot|availability|eligibility|time)\b|\bwhen (?:is|will) my collection\b|"
    r"\b(deliver to (me|my|this)|is delivery available)\b",
    re.IGNORECASE,
)
PRODUCT_PATTERN = re.compile(
    r"\b(menu|menus|food|product|products|item|items|price|prices|cost|"
    r"sell|serve|offer|offers|burger|kota|fries|wings|chicken|chips|drink|platter|"
    r"ingredients?|allergens?|contains?|how much|recommend(?:ation|ed)?|suggest(?:ion|ed)?|"
    r"filling|hearty|satisfying|substantial|hungry|meal|something to eat|fill me up|big appetite|good to eat|cheap|cheapest|affordable|budget)\b|"
    r"\bwhat (?:would you|do you) recommend\b|"
    r"\bwhat (?:do you|you|u|y'all|yall) (?:have|got)\b|\bwhat have you got\b|"
    r"\bwhat(?:'s| is) good\b|\bwhat should i (?:get|try|eat)\b|"
    r"\b(?:i am|i'm|im) (?:hungry|starving)\b|\blooking for something to eat\b",
    re.IGNORECASE,
)
MENU_LIST_PATTERN = re.compile(
    r"\bwhat(?:'s|s| is)\s+(?:on|in)\s+(?:(?:the|your)\s+)?menu\b|"
    r"\bwhat\s+(?:do you have|have you got|do you serve)\s+(?:on\s+)?(?:the\s+)?menu\b|"
    r"\b(?:show|list|browse)\s+(?:me\s+)?(?:the\s+)?(?:full\s+)?menu\b|"
    r"\b(?:full|current)\s+menu\b|"
    r"\bwhat\s+(?:menu\s+)?(?:items|food|meals)\s+(?:do you have|do you sell|do you serve|are available)\b",
    re.IGNORECASE,
)
POLICY_PATTERN = re.compile(
    r"\b(open|opening|hours?|close[sd]?|closing|trading|location|located|address|contact|"
    r"phone|whatsapp|instagram|facebook|tiktok|about|business|restaurant|shop|reach|"
    r"promotion|promotions|specials?|deals?|discounts?|loyalty|rewards?|points?|"
    r"payment|pay|paid|paying|cash|payfast|delivery|deliver(?:y|ies)?|collection|collect|pickup|takeaway|ordering)\b|"
    r"\b(what time|which days|when do you open|when do you close|what times do they close)\b|"
    r"where are you|where is the shop|where (?:you|u|y'all|yall) (?:at|located)",
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
    r"\bwhat\s+can\s+(?:you|u|this assistant|the assistant|this|the bot)\s+do\b|"
    r"\bwhat\s+do\s+(?:you|this assistant|the assistant)\s+do\b|"
    r"\bwhat\s+(?:can|do)\s+(?:you|this assistant|the assistant)\s+(?:answer|help with|know)\b|"
    r"\bwhat questions?\s+(?:can|should)\s+(?:i|we)\s+ask\b|"
    r"\bwhat can\s+(?:i|we)\s+ask(?:\s+about)?\b|"
    r"\bhow can\s+(?:you|this assistant|the assistant)\s+help\b|"
    r"\bwhat do\s+(?:you|this assistant|the assistant)\s+know\b|"
    r"\bwhat can (?:you|u) help (?:me )?with\b|\bwhat are you able to help with\b",
    re.IGNORECASE,
)
CAPABILITY_REPLY = (
    "I can answer questions about the current menu, item descriptions, prices "
    "and availability; suggest meals; explain published promotions, loyalty "
    "rewards, payment steps, and general collection and delivery rules; and share "
    "Food by K's published hours, location and contact details. I can't access or "
    "change your account or order, verify a payment, or decide whether a specific "
    "address or time slot is eligible. Try asking about a filling meal, how points "
    "work, or when Food by K is open."
)
TRANSACTIONAL_REPLY = (
    "I can explain Food by K's general menu and service information, but I can't "
    "access or change a specific order/account, verify a payment, or decide "
    "delivery or collection eligibility. Please contact the Food by K team for help with that."
)
PRIVACY_REPLY = (
    "For your privacy, please don't share personal, order, or payment details here. "
    "I can answer menu and general Food by K FAQ questions; contact the team for account-specific help."
)


class AskRequest(BaseModel):
    question: str = Field(min_length=1, max_length=500)


def _format_live_menu(products: list[dict]) -> str:
    available = [product for product in products if product.get("available", True)]
    if not available:
        return "There are no menu items listed as available right now. Please check the menu page again shortly."

    grouped: dict[str, list[dict]] = {}
    for product in available:
        category = str(product.get("category") or "Other")
        grouped.setdefault(category, []).append(product)

    lines = ["Here is the current menu and prices:"]
    shown = 0
    total = len(available)
    for category, items in grouped.items():
        heading = f"\n{category}:"
        if len("\n".join(lines)) + len(heading) > 2700:
            break
        lines.append(heading)
        for product in items:
            row = f"- {product.get('name', 'Menu item')} — {product.get('price', 'Price unavailable')}"
            if len("\n".join(lines)) + len(row) + 1 > 2700:
                break
            lines.append(row)
            shown += 1
        if shown < total and len("\n".join(lines)) > 2600:
            break

    if shown < total:
        lines.append(f"\nShowing {shown} of {total} available items. Browse the menu page for the full list and descriptions.")
    else:
        lines.append("\nBrowse the menu page for item descriptions and images.")
    return "\n".join(lines)


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

    if MENU_LIST_PATTERN.search(question):
        try:
            answer = _format_live_menu(fetch_products())
        except (requests.RequestException, RuntimeError, ValueError) as exc:
            logger.warning("Live menu unavailable (%s)", type(exc).__name__)
            raise HTTPException(status_code=503, detail="Menu information is temporarily unavailable") from None
        elapsed_ms = (time.perf_counter() - request_started) * 1000
        logger.info("ask_ms=%.1f route=live_menu", elapsed_ms)
        return {
            "answer": answer,
            "sources": [{"source": "Live Food by K menu", "url": "https://foodbyk.co.za/pages/customer/menu.html"}],
        }

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
    # Menu-only requests use live catalogue facts and do not need FAQ vector
    # retrieval. Mixed product/policy questions retain both evidence sources.
    chunks = retrieve(question) if not is_product_question or is_policy_question else []

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
