"""Bounded public-site discovery for an explicitly configured FoodByK domain."""
from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse, urldefrag
from urllib.robotparser import RobotFileParser
import logging
import os
import time
import xml.etree.ElementTree as ET

import requests

logger = logging.getLogger("foodbyk-rag")
_EXCLUDED = ("/account", "/checkout", "/cart", "/payment", "/orders", "/admin", "/api")


class _PageParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.skip = 0
        self.title = False
        self.in_title = False
        self.title_parts = []
        self.parts = []
        self.links = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag in {"script", "style", "noscript", "nav", "footer", "header", "form", "svg"}:
            self.skip += 1
        if tag == "title":
            self.in_title = True
        if tag == "a" and attrs.get("href"):
            self.links.append(attrs["href"])
        if tag in {"p", "li", "h1", "h2", "h3", "br", "section", "article"}:
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in {"script", "style", "noscript", "nav", "footer", "header", "form", "svg"} and self.skip:
            self.skip -= 1
        if tag == "title":
            self.in_title = False
        if tag in {"p", "li", "h1", "h2", "h3", "br", "section", "article"}:
            self.parts.append("\n")

    def handle_data(self, data):
        if self.in_title:
            self.title_parts.append(data)
        if not self.skip and data.strip():
            self.parts.append(data.strip())


def _allowed(url: str, host: str) -> bool:
    parsed = urlparse(url)
    path = parsed.path.lower()
    return parsed.scheme == "https" and parsed.hostname == host and not any(path.startswith(prefix) for prefix in _EXCLUDED)


def crawl_public_site() -> list[dict]:
    base = os.getenv("FOODBYK_WEBSITE_URL", "https://foodbyks.netlify.app/").strip()
    parsed_base = urlparse(base)
    host = parsed_base.hostname
    if parsed_base.scheme != "https" or not host:
        raise ValueError("FOODBYK_WEBSITE_URL must be an https URL")
    approved_hosts = {item.strip().lower() for item in os.getenv("FOODBYK_WEBSITE_ALLOWED_HOSTS", "foodbyks.netlify.app").split(",") if item.strip()}
    if host.lower() not in approved_hosts:
        raise ValueError("FOODBYK_WEBSITE_URL host is not in FOODBYK_WEBSITE_ALLOWED_HOSTS")
    session = requests.Session()
    session.headers.update({"User-Agent": "FoodByKKnowledgeBot/1.0 (+public-site indexing)"})
    robots_url = f"{parsed_base.scheme}://{host}/robots.txt"
    robots = RobotFileParser(robots_url)
    try:
        robots_response = session.get(robots_url, timeout=(3, 5))
        if robots_response.status_code == 404:
            robots.parse([])
        elif robots_response.ok:
            robots.parse(robots_response.text.splitlines())
        else:
            logger.warning("Website robots.txt returned HTTP %d; skipping website crawl", robots_response.status_code)
            return []
    except Exception as exc:
        logger.warning("Website robots.txt unavailable (%s); skipping website crawl", type(exc).__name__)
        return []
    if not robots.can_fetch(session.headers["User-Agent"], base):
        return []

    urls = [base]
    sitemap_url = f"{parsed_base.scheme}://{host}/sitemap.xml"
    try:
        sitemap = session.get(sitemap_url, timeout=(3, 8))
        if sitemap.ok:
            root = ET.fromstring(sitemap.content)
            discovered = [node.text for node in root.iter() if node.tag.endswith("loc") and node.text]
            urls.extend(url for url in discovered if _allowed(url, host))
    except Exception as exc:
        logger.info("Website sitemap unavailable (%s); using homepage links", type(exc).__name__)

    pages = []
    seen = set()
    cursor = 0
    max_pages = max(1, int(os.getenv("FOODBYK_WEBSITE_MAX_PAGES", "30")))
    while cursor < len(urls) and len(seen) < max_pages:
        url = urls[cursor]
        cursor += 1
        url = urldefrag(url)[0]
        if url in seen or not _allowed(url, host) or not robots.can_fetch(session.headers["User-Agent"], url):
            continue
        seen.add(url)
        try:
            response = session.get(url, timeout=(3, 8), allow_redirects=False)
            if response.status_code != 200 or "text/html" not in response.headers.get("Content-Type", ""):
                continue
            parser = _PageParser()
            parser.feed(response.text[:1_000_000])
            for href in parser.links:
                candidate = urldefrag(urljoin(url, href))[0]
                if candidate not in seen and candidate not in urls and _allowed(candidate, host):
                    urls.append(candidate)
            text = "\n".join(" ".join(part.split()) for part in parser.parts if part.strip())
            if len(text) >= 80:
                pages.append({"text": text, "source": f"Food by K website: {urlparse(url).path or '/'}", "url": url,
                              "title": " ".join(parser.title_parts).strip()[:200], "page_number": None,
                              "doc_type": "policy", "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                              "last_modified": response.headers.get("Last-Modified", "")})
            time.sleep(max(0.25, float(os.getenv("FOODBYK_CRAWL_DELAY_SECONDS", "1"))))
        except requests.RequestException as exc:
            logger.warning("Website page fetch failed (%s)", type(exc).__name__)
    return pages
