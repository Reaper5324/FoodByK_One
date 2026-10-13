# FAQ knowledge documents

Place public Food by K customer FAQ documents in this folder as `.pdf`, `.md`,
or `.txt` files. Prefer the company's own public website. Label third-party
sources clearly and do not use them for dynamic menu facts, prices, business
policies, or operational decisions.

The bundled `foodbyk_public_faq.md` contains publicly posted business facts and
source links. Recheck those facts when the site changes. Menu data is fetched
from the live Food by K API instead of being stored in these documents.

After editing or adding a document, run `python ingest.py` in the RAG service
environment. The new collection becomes active only after ingestion succeeds.
Product facts are fetched separately from the live Food by K API.
