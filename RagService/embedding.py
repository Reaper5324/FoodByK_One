from functools import lru_cache

EMBEDDING_MODEL = "all-MiniLM-L6-v2"


@lru_cache(maxsize=1)
def get_model():
    from sentence_transformers import SentenceTransformer

    return SentenceTransformer(EMBEDDING_MODEL)


@lru_cache(maxsize=512)
def _cached_embedding(text: str) -> tuple[float, ...]:
    return tuple(float(value) for value in get_model().encode(text).tolist())


def embed_text(text: str) -> list[float]:
    if not text or not text.strip():
        raise ValueError("Cannot embed empty text")

    normalized = " ".join(text.split())
    return list(_cached_embedding(normalized))
