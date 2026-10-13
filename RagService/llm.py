import os

from dotenv import load_dotenv
from groq import Groq

from prompt_builder import SYSTEM_INSTRUCTIONS

load_dotenv()

_client = None


def get_client() -> Groq:
    global _client
    if _client is None:
        api_key = os.getenv("GROQ_API_KEY")
        if not api_key:
            raise RuntimeError("GROQ_API_KEY is not set. Add it to your .env file or environment.")
        _client = Groq(api_key=api_key, timeout=18.0, max_retries=0)
    return _client


def generate_answer(prompt: str) -> str:
    response = get_client().chat.completions.create(
        model=os.getenv("GROQ_MODEL", "llama-3.1-8b-instant"),
        messages=[
            {"role": "system", "content": SYSTEM_INSTRUCTIONS},
            {"role": "user", "content": prompt},
        ],
        temperature=0.2,
        max_tokens=260,
    )
    return response.choices[0].message.content or ""
