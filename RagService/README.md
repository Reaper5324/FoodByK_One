# Food by K customer FAQ service

This private FastAPI service answers only public menu and approved general FAQ
questions. It does not access customer accounts, orders, payments, delivery
decisions, or collection decisions. Customer browsers must call the PHP backend;
the backend proxies requests to this service.

## Data sources and answer limits

- Current product name, description, category, price, and public availability
  are fetched from Food by K's public PHP API. The short in-memory cache lasts
  30 seconds. Product data is not stored in Chroma.
- Chroma contains public FAQ documents from `documents/` (`.pdf`, `.md`, and
  `.txt`). Keep them grounded in Food by K's own public statements; flag
  third-party sources and never use them for dynamic menu or policy decisions.
- If no relevant source is found, the service returns a fixed fallback without
  calling the language model. Responses are limited to 260 generated tokens.
- Email addresses, phone numbers, card-like number sequences, and explicit
  personal-address details are rejected before retrieval or model calls.
- Orders, payments, refunds, delivery, collection, and account requests receive
  a deterministic handoff response without a model call.

`documents/foodbyk_public_faq.md` contains public facts from the customer
website and citations for corroborating online references. Recheck those facts
when the website changes. The live menu API remains authoritative for menu
details and prices.

## Configuration

Copy `.env.example` to `.env` for local development. Configure these variables
on the Railway RAG service:

```text
GROQ_API_KEY=your Groq API key
RAG_SERVICE_API_KEY=a long random shared secret
FOODBYK_API_URL=https://your-foodbyk-backend-domain
RAG_DATA_DIR=/data
```

Set the same `RAG_SERVICE_API_KEY` on the PHP backend, and set its
`RAG_SERVICE_URL` to the private/public Railway service URL. The secret is sent
only in a server-to-server header. Keep the RAG endpoint off the customer
frontend; `/ask` rejects requests without that key. `/health` is intentionally
public for Railway health checks.

`FOODBYK_API_URL` is the base URL of the PHP API and must not end in `/products`.
The RAG service reads `/products` and `/categories`. It has no direct database
fallback and needs no database credentials.

## Local run

Install dependencies with Python 3.11 and ingest the FAQ documents:

```bash
pip install -r requirements.txt
python ingest.py
uvicorn main:app --reload --host 127.0.0.1 --port 8001
```

Test through the PHP proxy, or call the private service with the shared header:

```bash
curl -X POST http://127.0.0.1:8001/ask \
  -H 'Content-Type: application/json' \
  -H 'X-RAG-Service-Key: your-local-shared-secret' \
  -d '{"question":"What are your opening hours?"}'
```

## Railway deployment and index updates

Set the Railway service root directory to `RagService`, mount a persistent
volume at `/data`, and set `RAG_DATA_DIR=/data`. The index pointer is switched
only after a new Chroma collection has been built successfully. This avoids
serving a half-built index and allows the previous collection to remain
available if ingestion fails.

After deploy, and after changing FAQ documents, run `python ingest.py` in the
RAG service environment. Product facts remain live and do not require ingestion.

## Backend/frontend connection

The PHP API provides `POST /assistant/ask` with an allowed-origin check and a
10-request-per-five-minute per-IP limit. PHP validates the question (maximum 500 characters) and calls
`POST /ask` with `X-RAG-Service-Key`. Customer pages load a small FAQ panel from
the shared `navigation.js`; it calls the PHP API client and never receives the
Groq or RAG service key.
