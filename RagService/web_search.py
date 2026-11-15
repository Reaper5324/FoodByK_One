"""Optional public corroboration via Tavily. Never used for menu or policy facts."""
import logging
import os
import time

import requests

logger = logging.getLogger("foodbyk-rag")
_session = requests.Session()


def search_public_web(question: str, max_results: int = 3) -> list[dict]:
    api_key = os.getenv("TAVILY_API_KEY", "").strip()
    if not api_key:
        return []
    started = time.perf_counter()
    try:
        response = _session.post(
            os.getenv("TAVILY_API_URL", "https://api.tavily.com/search"),
            json={"api_key": api_key, "query": question[:500], "search_depth": "basic", "max_results": max_results},
            timeout=(3, 8),
        )
        response.raise_for_status()
        results = response.json().get("results", [])
        chunks = []
        for result in results[:max_results]:
            if not isinstance(result, dict):
                continue
            text = " ".join(str(result.get("content", "")).split())[:1200]
            url = str(result.get("url", ""))[:500]
            if text and url.startswith("https://"):
                chunks.append({"text": text, "source": "External public source", "url": url, "doc_type": "external", "verified": False})
        logger.info("external_search_ms=%.1f results=%d", (time.perf_counter() - started) * 1000, len(chunks))
        return chunks
    except (requests.RequestException, ValueError, TypeError) as exc:
        logger.warning("External search unavailable (%s); continuing without it", type(exc).__name__)
        return []
