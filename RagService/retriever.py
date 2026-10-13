import os
from pathlib import Path

import chromadb

from embedding import embed_text

BASE_DIR = Path(__file__).resolve().parent
CHROMA_PATH = Path(os.getenv("RAG_DATA_DIR") or str(BASE_DIR / "chroma_db")).resolve()
ACTIVE_COLLECTION_FILE = CHROMA_PATH / "active_policy_collection.txt"
client = chromadb.PersistentClient(path=str(CHROMA_PATH))


def retrieve(question: str, n_results: int = 3) -> list[dict]:
    if not question or not question.strip() or not ACTIVE_COLLECTION_FILE.is_file():
        return []

    collection_name = ACTIVE_COLLECTION_FILE.read_text(encoding="utf-8").strip()
    if not collection_name:
        return []

    try:
        collection = client.get_collection(name=collection_name)
        count = collection.count(where={"doc_type": "policy"})
        if count == 0:
            return []

        result = collection.query(
            query_embeddings=[embed_text(question)],
            n_results=min(n_results, count),
            where={"doc_type": "policy"},
            include=["documents", "metadatas", "distances"],
        )
    except Exception:
        # Empty/unbuilt indexes should produce the safe no-context response.
        return []

    documents = (result.get("documents") or [[]])[0]
    metadatas = (result.get("metadatas") or [[]])[0]
    distances = (result.get("distances") or [[]])[0]

    chunks = []
    for text, metadata, distance in zip(documents, metadatas, distances):
        metadata = metadata or {}
        # Keep a wider candidate range for natural-language paraphrases. The
        # model still has to ground every answer in the retrieved source text.
        if distance is not None and float(distance) > 1.6:
            continue
        chunks.append({
            "text": str(text)[:700],
            "source": str(metadata.get("source", "approved policy"))[:160],
            "page": metadata.get("page") or None,
            "doc_type": "policy",
        })
    return chunks
