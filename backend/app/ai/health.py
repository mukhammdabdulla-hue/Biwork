"""Provider health monitoring (docs/ai-engine.md §Reliability).

A small circuit breaker per provider, per process: after AI_HEALTH_FAILURE_THRESHOLD consecutive
transient or authentication failures a provider is marked `down` for AI_HEALTH_COOLDOWN_S seconds,
and the router tries the next configured provider first. After the cooldown one request is let
through (`half_open`); success closes the circuit. Content-specific errors (refusals, invalid
output, oversized input) say nothing about provider health and are not counted.
"""

from __future__ import annotations

import threading
import time
from dataclasses import dataclass, field
from typing import Any

from app.config import get_settings


@dataclass
class ProviderHealth:
    consecutive_failures: int = 0
    total_calls: int = 0
    total_failures: int = 0
    last_error: str | None = None
    last_failure_at: float | None = None
    last_success_at: float | None = None
    down_until: float | None = None
    latencies_ms: list[int] = field(default_factory=list)

    def state(self, now: float) -> str:
        if self.down_until is None:
            return "up"
        return "down" if now < self.down_until else "half_open"


_lock = threading.Lock()
_health: dict[str, ProviderHealth] = {}


def _get(provider: str) -> ProviderHealth:
    return _health.setdefault(provider, ProviderHealth())


def record_success(provider: str, latency_ms: int | None = None) -> None:
    with _lock:
        h = _get(provider)
        h.total_calls += 1
        h.consecutive_failures = 0
        h.down_until = None
        h.last_success_at = time.time()
        if latency_ms is not None:
            h.latencies_ms = [*h.latencies_ms[-49:], latency_ms]


def record_failure(provider: str, error: str) -> None:
    s = get_settings()
    with _lock:
        h = _get(provider)
        h.total_calls += 1
        h.total_failures += 1
        h.consecutive_failures += 1
        h.last_error = error[:300]
        h.last_failure_at = time.time()
        if h.consecutive_failures >= s.ai_health_failure_threshold:
            h.down_until = time.time() + s.ai_health_cooldown_s


def is_available(provider: str) -> bool:
    with _lock:
        h = _health.get(provider)
        return h is None or h.state(time.time()) != "down"


def snapshot() -> dict[str, dict[str, Any]]:
    now = time.time()
    with _lock:
        out = {}
        for name, h in _health.items():
            lat = sorted(h.latencies_ms)
            out[name] = {
                "state": h.state(now), "consecutive_failures": h.consecutive_failures, "calls": h.total_calls,
                "failures": h.total_failures, "last_error": h.last_error, "last_failure_at": h.last_failure_at,
                "last_success_at": h.last_success_at, "down_for_s": max(0, int(h.down_until - now)) if h.down_until else 0,
                "p50_latency_ms": lat[len(lat) // 2] if lat else None,
            }
        return out


def reset() -> None:
    with _lock:
        _health.clear()
