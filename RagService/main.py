import hmac
import logging
import os
import re
from typing import Annotated

import requests
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from llm import generate_answer
from product_catalog import fetch_products, product_to_document, select_products
from prompt_builder import FALLBACK_ANSWER, build_prompt
from retriever import retrieve

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("foodbyk-rag")

app = FastAPI(title="Food by K Customer FAQ", docs_url=None, redoc_url=None)

TRANSACTIONAL_PATTERN = re.compile(
    r"\b(payment|payfast|refund|charge|charged|card|checkout|"
    r"collection|collect|tracking|track|status|time slot|delivery fee|"
    r"delivery area|delivery availability|delivery eligibility|delivery time|"
    r"account|my details)\b|"
    r"\b(my|the)\s+orders?\b|\borders?\s+(status|tracking|update|progress|history|number)\b|"
    r"\b(where is|track|cancel|change|check)\s+(my|the)\s+orders?\b|"
    r"\b(do you deliver|deliver to (me|my|this)|is delivery available)\b|"
    r"\b(how do|how can) i (place|submit|make) an? order\b",
    re.IGNORECASE,
)
PRODUCT_PATTERN = re.compile(
    r"\b(menu|menus|food|product|products|item|items|price|prices|cost|"
    r"sell|serve|offer|offers|burger|kota|fries|wings|chicken|chips|drink|platter|"
    r"ingredients?|allergens?|contains?|how much|recommend(?:ation|ed)?|suggest(?:ion|ed)?|"
    r"filling|hearty|satisfying|hungry|meal|something to eat)\b|"
    r"\bwhat (?:would you|do you) recommend\b",
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
        if not source or source in seen:
            continue
        seen.add(source)
        sources.append({"source": source})
        if len(sources) == 5:
            break
    return sources


@app.post("/ask")
def ask(
    request: AskRequest,
    provided_key: Annotated[str | None, Header(alias="X-RAG-Service-Key")] = None,
):
    _require_backend_key(provided_key)
    question = request.question.strip()
    if not question:
        raise HTTPException(status_code=422, detail="Question cannot be empty")

    if PERSONAL_DATA_PATTERN.search(question):
        return {"answer": PRIVACY_REPLY, "sources": []}

    if TRANSACTIONAL_PATTERN.search(question):
        return {"answer": TRANSACTIONAL_REPLY, "sources": []}

    is_policy_question = bool(POLICY_PATTERN.search(question))
    is_product_question = bool(PRODUCT_PATTERN.search(question)) or (
        not is_policy_question and bool(re.search(r"\bwhat is|what are|do you have|what do you have\b", question, re.IGNORECASE))
    )
    chunks = []

    if is_product_question:
        try:
            products = fetch_products()
        except (requests.RequestException, RuntimeError, ValueError) as exc:
            logger.warning("Food by K product API unavailable (%s)", type(exc).__name__)
            raise HTTPException(status_code=503, detail="Menu information is temporarily unavailable") from None
        for product in select_products(question, products):
            text, metadata = product_to_document(product)
            chunks.append({"text": text, **metadata})

    if is_policy_question:
        chunks.extend(retrieve(question))

    if not chunks:
        return {"answer": FALLBACK_ANSWER, "sources": []}

    try:
        answer = generate_answer(build_prompt(question, chunks)).strip()
    except Exception as exc:
        logger.error("Food by K answer generation failed (%s)", type(exc).__name__)
        raise HTTPException(status_code=503, detail="Assistant is temporarily unavailable") from None

    if not answer or len(answer) > 3000:
        return {"answer": FALLBACK_ANSWER, "sources": []}

    return {"answer": answer, "sources": _source_list(chunks)}
