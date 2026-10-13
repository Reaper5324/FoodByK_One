import re
import time
from difflib import SequenceMatcher
from concurrent.futures import ThreadPoolExecutor
from decimal import Decimal, InvalidOperation
from typing import Any

import requests
from dotenv import load_dotenv
import os

load_dotenv()

_catalog_cache: tuple[float, list[dict[str, Any]]] | None = None
_CACHE_SECONDS = 30
_MAX_PRODUCTS_IN_CONTEXT = 4
_PREFERENCE_ALIASES = {
    "hearty": {"filling", "generous"},
    "substantial": {"filling", "generous"},
    "satisfying": {"filling"},
    "proper": {"filling"},
    "big": {"generous", "sharing"},
    "large": {"generous", "sharing"},
    "filing": {"filling"},
    "fillin": {"filling"},
    "fulfilling": {"filling"},
}
_STOP_WORDS = {
    "a", "an", "and", "are", "by", "do", "for", "have", "i", "in", "is",
    "it", "me", "of", "on", "or", "our", "please", "the", "to", "we", "what",
    "which", "with", "you", "your",
}


def _clean(value: Any, default: str = "Not specified", limit: int = 500) -> str:
    if value is None:
        return default
    text = " ".join(str(value).split())[:limit]
    return text if text else default


def _money(value: Any) -> str:
    try:
        amount = Decimal(str(value))
        if not amount.is_finite() or amount < 0:
            return "Price unavailable"
        return f"R{amount:.2f}"
    except (InvalidOperation, ValueError, TypeError):
        return "Price unavailable"


def _data(payload: Any) -> list[dict[str, Any]]:
    if isinstance(payload, dict):
        payload = payload.get("data")
    if not isinstance(payload, list):
        raise ValueError("Food by K API returned an invalid list")
    return [item for item in payload if isinstance(item, dict)]


def fetch_products(force_refresh: bool = False) -> list[dict[str, Any]]:
    global _catalog_cache
    now = time.monotonic()
    if not force_refresh and _catalog_cache and now - _catalog_cache[0] < _CACHE_SECONDS:
        return _catalog_cache[1]

    base_url = (os.getenv("FOODBYK_API_URL") or os.getenv("PRODUCT_API_URL") or "").rstrip("/")
    if not base_url:
        raise RuntimeError("FOODBYK_API_URL is not configured")

    with ThreadPoolExecutor(max_workers=2) as executor:
        products_future = executor.submit(requests.get, f"{base_url}/products", timeout=(3, 5))
        categories_future = executor.submit(requests.get, f"{base_url}/categories", timeout=(3, 5))
        products_response = products_future.result()
        categories_response = categories_future.result()
    products_response.raise_for_status()
    products = _data(products_response.json())
    categories_response.raise_for_status()
    categories = _data(categories_response.json())
    category_names = {
        str(category.get("id")): _clean(category.get("name"), "Uncategorised", 100)
        for category in categories
    }

    normalized = []
    for product in products:
        product_id = product.get("id")
        name = _clean(product.get("name"), "", 150)
        if product_id is None or not name:
            continue
        normalized.append({
            "id": str(product_id),
            "name": name,
            "description": _clean(product.get("description"), "No description provided", 500),
            "category": category_names.get(str(product.get("category_id")), "Uncategorised"),
            "price": _money(product.get("price")),
            "available": bool(product.get("is_available", False)),
        })

    _catalog_cache = (now, normalized)
    return normalized


def _tokens(text: str) -> set[str]:
    return {
        token for token in re.findall(r"[a-z0-9]+", text.lower())
        if len(token) > 1 and token not in _STOP_WORDS
    }


def select_products(question: str, products: list[dict[str, Any]]) -> list[dict[str, Any]]:
    query_tokens = _tokens(question)
    expanded_tokens = set(query_tokens)
    for token in query_tokens:
        expanded_tokens.update(_PREFERENCE_ALIASES.get(token, set()))

    wants_affordable = bool(re.search(r"\b(cheap|cheapest|affordable|inexpensive|low[ -]cost|budget)\b", question, re.IGNORECASE))
    if wants_affordable:
        priced = []
        for product in products:
            try:
                amount = Decimal(str(product["price"]).replace("R", "").strip())
            except (InvalidOperation, ValueError, TypeError):
                continue
            if amount.is_finite() and amount >= 0:
                priced.append((amount, product))
        if priced:
            priced.sort(key=lambda item: (item[0], item[1]["name"].casefold()))
            return [product for _, product in priced[:_MAX_PRODUCTS_IN_CONTEXT]]

    scored = []
    for product in products:
        searchable = f"{product['name']} {product['category']} {product['description']}"
        product_tokens = _tokens(searchable)
        score = len(expanded_tokens & product_tokens)
        # Forgive common one-character typos in meaningful words (e.g. "filing"
        # for "filling") without fuzzy-matching short words or the whole query.
        for query_token in query_tokens:
            if query_token in product_tokens or len(query_token) < 4:
                continue
            if any(SequenceMatcher(None, query_token, item_token).ratio() >= 0.84 for item_token in product_tokens if len(item_token) >= 4):
                score += 1
        if score:
            scored.append((score, product))

    if scored:
        scored.sort(key=lambda item: (-item[0], item[1]["name"].casefold()))
        return [product for _, product in scored[:_MAX_PRODUCTS_IN_CONTEXT]]

    broad_menu_question = bool(re.search(
        r"\b(menu|menus|offer|offers|sell|serve|available|food|prices?|items?|recommend|suggest|hungry|filling|hearty|meal|eat|good)\b|"
        r"what do you have|what do you serve|what should i (?:get|try|eat)|what's good|what is good|"
        r"(?:looking|in the mood) for (?:something|food)",
        question,
        re.IGNORECASE,
    ))
    return products[:_MAX_PRODUCTS_IN_CONTEXT] if broad_menu_question else []


def product_to_document(product: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    product_id = product["id"]
    name = product["name"]
    text = "\n".join([
        f"Menu item: {name}",
        f"Category: {product['category']}",
        f"Price: {product['price']}",
        f"Description: {product['description']}",
        f"Currently listed as available: {'yes' if product['available'] else 'no'}",
    ])
    return text, {
        "doc_type": "product",
        "source": f"Food by K menu: {name}",
        "product_id": product_id,
        "url": "/pages/customer/menu.html",
    }
