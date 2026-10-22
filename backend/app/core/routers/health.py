"""Liveness, readiness and Prometheus metrics (docs/architecture.md §10.4, §11)."""

from __future__ import annotations

import time

from fastapi import APIRouter, Request, Response
from prometheus_client import CONTENT_TYPE_LATEST, Counter, Histogram, generate_latest
from sqlalchemy import text
from starlette.middleware.base import BaseHTTPMiddleware

from app.config import get_settings
from app.db.session import engine

router = APIRouter(prefix="/health", tags=["operations"])

REQUESTS = Counter("biwork_http_requests_total", "HTTP requests", ["method", "route", "status"])
LATENCY = Histogram("biwork_http_request_seconds", "HTTP request latency", ["method", "route"])


class MetricsMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        started = time.monotonic()
        response = await call_next(request)
        route = getattr(request.scope.get("route"), "path", "unmatched")
        REQUESTS.labels(request.method, route, response.status_code).inc()
        LATENCY.labels(request.method, route).observe(time.monotonic() - started)
        return response


@router.get("/live")
def live() -> dict[str, str]:
    return {"status": "ok"}


@router.get("/ready")
def ready(response: Response) -> dict[str, object]:
    checks: dict[str, str] = {}
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        checks["database"] = "ok"
    except Exception as exc:  # noqa: BLE001
        checks["database"] = f"error: {exc.__class__.__name__}"
    s = get_settings()
    if s.redis_url:
        try:
            import redis

            redis.Redis.from_url(s.redis_url, socket_timeout=1).ping()
            checks["redis"] = "ok"
        except Exception as exc:  # noqa: BLE001
            checks["redis"] = f"error: {exc.__class__.__name__}"
    if s.storage_backend == "local":
        checks["storage"] = "ok" if s.storage_dir.exists() or _mk(s.storage_dir) else "error"
    ok = all(v == "ok" for v in checks.values())
    response.status_code = 200 if ok else 503
    return {"status": "ok" if ok else "degraded", "checks": checks}


def _mk(path) -> bool:
    try:
        path.mkdir(parents=True, exist_ok=True)
        return True
    except OSError:
        return False


@router.get("/metrics", include_in_schema=False)
def metrics() -> Response:
    return Response(generate_latest(), media_type=CONTENT_TYPE_LATEST)
