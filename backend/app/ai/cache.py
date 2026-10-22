"""Company-scoped cache for reusable AI results (docs/ai-engine.md §Cost).

Only tasks marked `cacheable` in the routing table use it (classification and spreadsheet column
mapping): re-submitting the same content, or another spreadsheet with the same headers and sample
values, does not pay for the same answer twice.

The key always includes the company id, so a result computed from one company's data is never
served to another company. It also includes the task, prompt version, provider, model and a hash of
exactly what would have been sent, so a prompt or model change invalidates it. Redis is used when
configured (entries expire after AI_CACHE_TTL_S), otherwise a bounded per-process dictionary.
"""

from __future__ import annotations

import hashlib
import json
import threading
import time
from typing import Any

from app.config import get_settings

_MAX_LOCAL = 2000
_lock = threading.Lock()
_local: dict[str, tuple[float, dict[str, Any]]] = {}


def make_key(company_id: Any, task: str, prompt_ref: str, provider: str, model: str, payload: Any) -> str:
    digest = hashlib.sha256(json.dumps(payload, sort_keys=True, default=str, ensure_ascii=False).encode()).hexdigest()
    return f"biwork:ai-cache:{company_id}:{task}:{prompt_ref}:{provider}:{model}:{digest}"


def _redis():
    s = get_settings()
    if not s.redis_url:
        return None
    try:
        import redis

        return redis.Redis.from_url(s.redis_url, socket_timeout=1)
    except Exception:  # pragma: no cover - redis missing
        return None


def get(key: str) -> dict[str, Any] | None:
    ttl = get_settings().ai_cache_ttl_s
    if ttl <= 0:
        return None
    client = _redis()
    if client is not None:
        try:
            raw = client.get(key)
            return json.loads(raw) if raw else None
        except Exception:
            pass
    with _lock:
        hit = _local.get(key)
        if hit and hit[0] > time.time():
            return hit[1]
        _local.pop(key, None)
    return None


def put(key: str, value: dict[str, Any]) -> None:
    ttl = get_settings().ai_cache_ttl_s
    if ttl <= 0:
        return
    client = _redis()
    if client is not None:
        try:
            client.setex(key, ttl, json.dumps(value, default=str, ensure_ascii=False))
            return
        except Exception:
            pass
    with _lock:
        if len(_local) >= _MAX_LOCAL:
            for old in sorted(_local, key=lambda k: _local[k][0])[: _MAX_LOCAL // 10]:
                _local.pop(old, None)
        _local[key] = (time.time() + ttl, value)


def clear() -> None:
    with _lock:
        _local.clear()
