import logging
import math
import os
import re
import time
from functools import lru_cache
from pathlib import Path

import chromadb

from embedding import embed_text

logger = logging.getLogger("foodbyk-rag")
BASE_DIR = Path(__file__).resolve().parent
CHROMA_PATH = Path(os.getenv("RAG_DATA_DIR") or str(BASE_DIR / "chroma_db")).resolve()
ACTIVE_COLLECTION_FILE = CHROMA_PATH / "active_policy_collection.txt"
client = chromadb.PersistentClient(path=str(CHROMA_PATH))
_ALIASES = {
    "open": {"opening", "hours", "trading", "close", "closing"},
    "close": {"closing", "hours", "trading", "open", "opening"},
    "when": {"hours", "opening", "closing", "trading", "open"},
    "where": {"location", "address", "branch", "find", "located"},
    "location": {"where", "address", "branch", "find", "located"},
    "visit": {"location", "address", "find", "where", "directions"},
    "branch": {"location", "address", "find", "where"},
    "located": {"location", "address", "find", "where"},
    "hungry": {"menu", "food", "meal", "filling", "recommend"},
    "filling": {"hearty", "substantial", "satisfying"},
    "lekker": {"tasty", "good", "food", "menu"},
    "tasty": {"lekker", "good", "food", "menu"},
    "deal": {"deals", "special", "specials", "promotion", "promotions", "discount", "offer"},
    "deals": {"deal", "special", "specials", "promotion", "promotions", "discount", "offer"},
    "special": {"deal", "deals", "specials", "promotion", "promotions", "discount", "offer"},
    "specials": {"deal", "deals", "special", "promotion", "promotions", "discount", "offer"},
    "got": {"menu", "food", "items", "products", "offers", "specials"},
    "promo": {"promotion", "promotions", "deal", "special", "discount", "offer"},
    "points": {"loyalty", "rewards", "earn", "redeem"},
    "reward": {"loyalty", "points", "earn", "redeem"},
    "loyalty": {"points", "rewards", "earn", "redeem"},
    "pay": {"payment", "payfast", "card", "charge"},
    "paying": {"payment", "payfast", "card", "charge"},
    "payment": {"pay", "payfast", "card", "charged"},
    "deliver": {"delivery", "deliveries", "dropoff", "address"},
    "delivery": {"deliver", "deliveries", "dropoff", "address"},
    "pickup": {"collection", "collect", "pick"},
    "collect": {"collection", "pickup", "pick"},
    "takeaway": {"collection", "pickup", "collect"},
    "contact": {"phone", "whatsapp", "reach", "call", "message"},
    "call": {"contact", "phone", "whatsapp", "reach"},
}
_STOP = {"a", "an", "and", "are", "at", "by", "do", "for", "get", "got", "guys", "how", "i", "in", "is", "it", "me", "my", "of", "on", "the", "there", "to", "u", "we", "what", "when", "where", "work", "you", "your"}
_TOKEN = re.compile(r"[a-z0-9]+")


def expand_query(question: str) -> list[str]:
    normalized = question.lower()
    for pattern, replacement in (
        (r"\by'all\b|\byall\b|\bu\b", "you"),
        (r"\bur\b", "your"),
        (r"\bwat\b", "what"),
        (r"\bwen\b", "when"),
        (r"\bwher\b", "where"),
        (r"\bwhats\b", "what is"),
        (r"\bwheres\b", "where is"),
        (r"\bwhens\b", "when is"),
        (r"\bwanna\b", "want to"),
    ):
        normalized = re.sub(pattern, replacement, normalized)
    terms = set(_TOKEN.findall(normalized))
    expanded = set(terms)
    for term in terms:
        expanded.update(_ALIASES.get(term, set()))
    # Preserve the original query as the dense query; the expansion is only for sparse recall.
    return sorted(expanded - _STOP)


def _sparse_rank(query_terms: list[str], ids: list[str], docs: list[str]) -> dict[str, int]:
    if not query_terms:
        return {}
    query = set(query_terms)
    tokenized = [[t for t in _TOKEN.findall((document or "").lower()) if t not in _STOP] for document in docs]
    document_frequency = {term: sum(term in set(terms) for terms in tokenized) for term in query}
    average_length = (sum(map(len, tokenized)) / len(tokenized)) if tokenized else 1.0
    average_length = max(1.0, average_length)
    k1, b = 1.5, 0.75
    scored = []
    for identity, terms in zip(ids, tokenized):
        frequencies = {t: terms.count(t) for t in query if t in terms}
        length_factor = k1 * (1 - b + b * len(terms) / average_length)
        score = sum(
            math.log(1 + (len(docs) - document_frequency[term] + 0.5) / (document_frequency[term] + 0.5))
            * frequency * (k1 + 1) / (frequency + length_factor)
            for term, frequency in frequencies.items()
        )
        if score:
            scored.append((score, identity))
    scored.sort(key=lambda item: (-item[0], item[1]))
    return {identity: rank for rank, (_, identity) in enumerate(scored, start=1)}


def reciprocal_rank_fusion(dense_ids: list[str], sparse_ranks: dict[str, int], constant: int = 60) -> dict[str, float]:
    scores = {}
    for rank, identity in enumerate(dense_ids, start=1):
        scores[identity] = scores.get(identity, 0.0) + 1 / (constant + rank)
    for identity, rank in sparse_ranks.items():
        scores[identity] = scores.get(identity, 0.0) + 1 / (constant + rank)
    return scores


@lru_cache(maxsize=4)
def _sparse_corpus(collection_name: str) -> tuple[tuple[str, ...], tuple[str, ...], tuple[dict, ...]]:
    collection = client.get_collection(name=collection_name)
    rows = collection.get(where={"doc_type": "policy"}, include=["documents", "metadatas"])
    return (
        tuple(rows.get("ids") or []),
        tuple(rows.get("documents") or []),
        tuple(rows.get("metadatas") or []),
    )


@lru_cache(maxsize=256)
def _retrieve_cached(collection_name: str, normalized_question: str, limit: int) -> tuple[dict, ...]:
    started = time.perf_counter()
    collection = client.get_collection(name=collection_name)
    count = collection.count()
    if count == 0:
        return ()
    candidate_count = min(count, max(12, limit * 4))
    expanded_query = " ".join([normalized_question, *expand_query(normalized_question)])
    dense = collection.query(
        query_embeddings=[embed_text(normalized_question), embed_text(expanded_query)],
        n_results=candidate_count,
        where={"doc_type": "policy"},
        include=["documents", "metadatas", "distances"],
    )
    all_ids, all_documents, all_metadatas = _sparse_corpus(collection_name)
    sparse = _sparse_rank(expand_query(normalized_question), list(all_ids), list(all_documents))
    query_ids = dense.get("ids") or [[]]
    query_docs = dense.get("documents") or [[]]
    query_meta = dense.get("metadatas") or [[]]
    query_distances = dense.get("distances") or [[]]
    max_distance = float(os.getenv("RAG_MAX_VECTOR_DISTANCE", "1.6"))
    dense_rows = {}
    dense_ids = []
    for ids, docs, metadatas, distances in zip(query_ids, query_docs, query_meta, query_distances):
        for rank, (identity, document, metadata, distance) in enumerate(zip(ids, docs, metadatas, distances), start=1):
            if distance is not None and float(distance) > max_distance:
                continue
            dense_ids.append(identity)
            previous = dense_rows.get(identity)
            if previous is None or rank < previous[2]:
                dense_rows[identity] = (document, metadata or {}, rank, distance)
    fused = reciprocal_rank_fusion(dense_ids, sparse)
    dense_docs = {identity: row[0] for identity, row in dense_rows.items()}
    dense_meta = {identity: row[1] for identity, row in dense_rows.items()}
    dense_distance = {identity: row[3] for identity, row in dense_rows.items()}
    all_docs = dict(zip(all_ids, all_documents))
    all_meta = dict(zip(all_ids, all_metadatas))
    ordered = sorted(fused, key=lambda identity: (-fused[identity], identity))[:limit]
    minimum_score = max(0.0, float(os.getenv("RAG_MIN_FUSION_SCORE", "0.012")))
    results = []
    for identity in ordered:
        if fused[identity] < minimum_score:
            continue
        metadata = dense_meta.get(identity) or all_meta.get(identity) or {}
        text = dense_docs.get(identity) or all_docs.get(identity) or ""
        distance = dense_distance.get(identity)
        # Relative fusion ranking avoids throwing away paraphrases at a model-specific fixed distance.
        if not text:
            continue
        results.append({
            "text": str(text)[:1100],
            "source": str(metadata.get("source", "approved policy"))[:160],
            "url": str(metadata.get("url", ""))[:500],
            "page": metadata.get("page") or None,
            "doc_type": "policy",
            "relevance": round(fused[identity], 5),
            "distance": distance,
        })
    logger.info("retrieval_ms=%.1f dense=%d sparse=%d fused=%d", (time.perf_counter() - started) * 1000, len(dense_ids), len(sparse), len(results))
    return tuple(results)


def retrieve(question: str, n_results: int = 4) -> list[dict]:
    if not question or not question.strip():
        return []
    if not ACTIVE_COLLECTION_FILE.is_file():
        logger.warning("Policy retrieval skipped: no active collection pointer")
        return []
    collection_name = ACTIVE_COLLECTION_FILE.read_text(encoding="utf-8").strip()
    if not collection_name:
        return []
    try:
        return [dict(row) for row in _retrieve_cached(collection_name, " ".join(question.lower().split()), max(1, n_results))]
    except Exception as exc:
        logger.exception("Policy retrieval failed (%s)", type(exc).__name__)
        return []
