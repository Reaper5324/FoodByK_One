# FAQ knowledge documents

Place approved Food by K customer FAQ documents in this folder as `.pdf`, `.md`,
or `.txt` files. Only add content the business has approved for customers.

The bundled `customer_faq_draft.md` is test content based on the current public
homepage. It is explicitly unapproved and must be reviewed or replaced before
the assistant is used as a production source of policy answers.

After editing or adding a document, run `python ingest.py` in the RAG service
environment. The new collection becomes active only after ingestion succeeds.
Product facts are fetched separately from the live Food by K API.
