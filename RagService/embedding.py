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


def embed_texts(texts: list[str], batch_size: int = 8) -> list[list[float]]:
    """Encode a batch of documents together to avoid repeated model setup cost."""
    normalized = [" ".join(text.split()) for text in texts]
    if not normalized or any(not text for text in normalized):
        raise ValueError("Cannot embed an empty batch or empty text")

    vectors = get_model().encode(
        normalized,
        batch_size=max(1, int(batch_size)),
        show_progress_bar=True,
        convert_to_numpy=True,
    )
    return [[float(value) for value in vector] for vector in vectors]
