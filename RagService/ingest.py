import os
import tempfile
from datetime import datetime, timezone
from pathlib import Path

import chromadb
import fitz

from chuncker import chunk_text
from embedding import embed_text

BASE_DIR = Path(__file__).resolve().parent
DOCUMENT_DIR = BASE_DIR / "documents"
CHROMA_PATH = Path(os.getenv("RAG_DATA_DIR") or str(BASE_DIR / "chroma_db")).resolve()
ACTIVE_COLLECTION_FILE = CHROMA_PATH / "active_policy_collection.txt"


def extract_text_from_pdf(pdf_path: Path) -> list[dict]:
    pages = []
    with fitz.open(pdf_path) as doc:
        for page_num, page in enumerate(doc, start=1):
            pages.append({
                "page_number": page_num,
                "text": page.get_text(),
                "source": pdf_path.name,
            })
    return pages


def reset_collection(client: chromadb.PersistentClient):
    collection_name = "policy_" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S%f")
    return client.create_collection(name=collection_name), collection_name


def extract_text_file(path: Path) -> list[dict]:
    return [{"page_number": None, "text": path.read_text(encoding="utf-8"), "source": path.name}]


def main():
    if not DOCUMENT_DIR.exists():
        raise FileNotFoundError(f"Document directory does not exist: {DOCUMENT_DIR}")

    documents = []
    for path in sorted(DOCUMENT_DIR.iterdir()):
        if path.name.lower() == "readme.md" or not path.is_file():
            continue
        suffix = path.suffix.lower()
        if suffix == ".pdf":
            print(f"Processing {path.name}...")
            documents.extend(extract_text_from_pdf(path))
        elif suffix in {".md", ".txt"}:
            documents.extend(extract_text_file(path))

    prepared = []
    for document in documents:
        for chunk in chunk_text(document["text"], chunk_size=650, overlap=80):
            prepared.append((chunk, {
                "source": document["source"],
                "page": document["page_number"] or 0,
                "doc_type": "policy",
            }))

    CHROMA_PATH.mkdir(parents=True, exist_ok=True)
    client = chromadb.PersistentClient(path=str(CHROMA_PATH))
    collection, collection_name = reset_collection(client)

    try:
        for start in range(0, len(prepared), 64):
            batch = prepared[start:start + 64]
            if not batch:
                continue
            texts = [item[0] for item in batch]
            collection.add(
                ids=[f"policy-{start + index}" for index in range(len(batch))],
                embeddings=[embed_text(text) for text in texts],
                documents=texts,
                metadatas=[item[1] for item in batch],
            )

        with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=CHROMA_PATH, delete=False) as pointer:
            pointer.write(collection_name)
            pointer_path = Path(pointer.name)
        pointer_path.replace(ACTIVE_COLLECTION_FILE)
    except Exception:
        client.delete_collection(collection_name)
        raise

    print(f"Ingestion complete. {len(prepared)} policy chunks stored in {collection_name}.")


if __name__ == "__main__":
    main()
