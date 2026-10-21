"""AI rate limiting and cost estimation (docs/architecture.md §7.3)."""

from __future__ import annotations

import threading
import time
from decimal import Decimal

from app.ai.providers.base import TransientAIError
from app.config import get_settings

# USD per million tokens (input, output), Anthropic first-party list prices.
PRICES: dict[str, tuple[Decimal, Decimal]] = {
    "claude-opus-5-5": (Decimal("4"), Decimal("20")),
    "claude-opus-5": (Decimal("5"), Decimal("25")),
    "claude-opus-4-8": (Decimal("5"), Decimal("25")),
    "claude-sonnet-5-5": (Decimal("2"), Decimal("10")),
    "claude-sonnet-5": (Decimal("2"), Decimal("10")),
    "claude-haiku-5-5": (Decimal("0.10"), Decimal("0.50")),
    "claude-haiku-4-5": (Decimal("1"), Decimal("5")),
    "claude-fable-5-1": (Decimal("10"), Decimal("50")),
}


def estimate_cost(model: str, input_tokens: int | None, output_tokens: int | None) -> Decimal | None:
    price = PRICES.get(model)
    if price is None or input_tokens is None or output_tokens is None:
        return None
    return (price[0] * input_tokens + price[1] * output_tokens) / Decimal(1_000_000)


_local_lock = threading.Lock()
_local_windows: dict[str, int] = {}


def acquire(provider: str, company_id: str) -> None:
    """Fixed-window limiter per provider+company (Redis when available, else per process)."""
    s = get_settings()
    limit = s.ai_max_calls_per_minute
    window = int(time.time() // 60)
    key = f"biwork:ai:{provider}:{company_id}:{window}"
    count: int
    try:
        if not s.redis_url:
            raise RuntimeError("no redis")
        import redis

        client = redis.Redis.from_url(s.redis_url, socket_timeout=2)
        pipe = client.pipeline()
        pipe.incr(key)
        pipe.expire(key, 120)
        count = int(pipe.execute()[0])
    except Exception:
        with _local_lock:
            _local_windows[key] = count = _local_windows.get(key, 0) + 1
    if count > limit:
        raise TransientAIError("AI rate limit for this company reached; retrying shortly", retry_after=60 - time.time() % 60)
