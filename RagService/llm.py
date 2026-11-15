import os
import logging
import time

from dotenv import load_dotenv
from groq import Groq

from prompt_builder import SYSTEM_INSTRUCTIONS

load_dotenv()

_client = None
logger = logging.getLogger("foodbyk-rag")


def get_client() -> Groq:
    global _client
    if _client is None:
        api_key = os.getenv("GROQ_API_KEY")
        if not api_key:
            raise RuntimeError("GROQ_API_KEY is not set. Add it to your .env file or environment.")
        _client = Groq(api_key=api_key, timeout=18.0, max_retries=0)
    return _client


def generate_answer(prompt: str) -> str:
    started = time.perf_counter()
    response = get_client().chat.completions.create(
        model=os.getenv("GROQ_MODEL", "openai/gpt-oss-20b"),
        messages=[
            {"role": "system", "content": SYSTEM_INSTRUCTIONS},
            {"role": "user", "content": prompt},
        ],
        temperature=0.2,
        max_completion_tokens=max(128, min(1200, int(os.getenv("GROQ_MAX_COMPLETION_TOKENS", "600")))),
        reasoning_effort=os.getenv("GROQ_REASONING_EFFORT", "low"),
    )
    logger.info("groq_generation_ms=%.1f model=%s", (time.perf_counter() - started) * 1000, os.getenv("GROQ_MODEL", "openai/gpt-oss-20b"))
    return response.choices[0].message.content or ""
