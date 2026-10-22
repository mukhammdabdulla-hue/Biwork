"""AI rate limiting and cost estimation (docs/architecture.md §7.3)."""

from __future__ import annotations

import json
import threading
import time
from decimal import Decimal
from functools import lru_cache

from app.ai.providers.base import TransientAIError
from app.config import get_settings

# USD per million tokens (input, output). Only Anthropic list prices ship with the code; OpenAI and
# Gemini prices change often and depend on the account, so they are configured with AI_PRICING_JSON
# (see docs/ai-engine.md §Costs). A model without a price is logged with cost_usd = null, never 0.
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


@lru_cache
def _configured_prices(raw: str | None) -> dict[str, tuple[Decimal, Decimal]]:
    if not raw:
        return {}
    data = json.loads(raw)
    return {model: (Decimal(str(p[0])), Decimal(str(p[1]))) for model, p in data.items()}


def price_for(model: str) -> tuple[Decimal, Decimal] | None:
    prices = {**PRICES, **_configured_prices(get_settings().ai_pricing_json)}
    if model in prices:
        return prices[model]
    # Providers may answer with a dated snapshot of a configured alias ("model-2026-08-01").
    candidates = [m for m in prices if model.startswith(m + "-")]
    return prices[max(candidates, key=len)] if candidates else None


def estimate_cost(model: str, input_tokens: int | None, output_tokens: int | None) -> Decimal | None:
    price = price_for(model)
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
