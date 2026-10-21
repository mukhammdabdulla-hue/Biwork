"""HTTP plumbing shared by every router (docs/architecture.md §5.3–5.5).

- Authentication: `Authorization: Bearer <JWT access token>` for people, `Bearer bwk_...` for API
  clients. The company comes from `X-Company-Id` and is verified against the caller's memberships
  before the session is bound to it for row-level security.
- Errors are RFC 9457 problem+json; pagination is cursor-based; updates honour If-Match; creating
  POSTs honour Idempotency-Key.
"""

from __future__ import annotations

import base64
import hashlib
import json
import logging
import threading
import time
import uuid
from collections.abc import Iterator
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from typing import Annotated, Any

import jwt
from fastapi import Depends, Header, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session
from sqlalchemy.orm.exc import StaleDataError
from starlette.middleware.base import BaseHTTPMiddleware

from app.config import get_settings
from app.core.context import RequestContext
from app.core.errors import AppError, Conflict, PreconditionFailed, RateLimited, Unauthorized
from app.core.models import IdempotencyKey, User
from app.core.security import decode_access_token
from app.core.services import identity
from app.db.session import SessionLocal, bind_tenant

log = logging.getLogger("biwork.api")


# --------------------------------------------------------------------------- sessions


def get_session() -> Iterator[Session]:
    """One session per request. Routers commit explicitly after a successful write."""
    session = SessionLocal()
    try:
        yield session
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


DB = Annotated[Session, Depends(get_session)]


# --------------------------------------------------------------------------- authentication


def _bearer(request: Request) -> str | None:
    header = request.headers.get("authorization") or ""
    if header.lower().startswith("bearer "):
        return header[7:].strip() or None
    return None


def client_ip(request: Request) -> str | None:
    return request.client.host if request.client else None


def current_user(request: Request, db: DB) -> User:
    token = _bearer(request)
    if not token or token.startswith("bwk_"):
        raise Unauthorized("Sign in to continue")
    try:
        claims = decode_access_token(token)
    except jwt.ExpiredSignatureError as exc:
        raise Unauthorized("Session expired", code="token_expired") from exc
    except jwt.InvalidTokenError as exc:
        raise Unauthorized("Invalid access token", code="invalid_token") from exc
    user = db.get(User, uuid.UUID(claims["sub"]))
    if user is None or not user.is_active:
        raise Unauthorized("Account disabled", code="account_disabled")
    bind_tenant(db, None, user.id)
    return user


CurrentUser = Annotated[User, Depends(current_user)]


def request_context(request: Request, db: DB,
                    x_company_id: Annotated[str | None, Header(alias="X-Company-Id")] = None) -> RequestContext:
    token = _bearer(request)
    request_id = getattr(request.state, "request_id", "-")
    if token and token.startswith("bwk_"):
        ctx = identity.authenticate_api_key(db, token, request_id, client_ip(request))
        if x_company_id and x_company_id != str(ctx.company_id):
            raise Unauthorized("This API key belongs to a different company", code="company_mismatch")
    else:
        user = current_user(request, db)
        if not x_company_id:
            raise Unauthorized("Select a company (X-Company-Id header)", code="company_required")
        try:
            company_id = uuid.UUID(x_company_id)
        except ValueError as exc:
            raise Unauthorized("Invalid X-Company-Id", code="company_required") from exc
        ctx = identity.build_user_context(db, user, company_id, request_id, client_ip(request))
    bind_tenant(db, ctx.company_id, ctx.user_id)
    request.state.company_id = str(ctx.company_id)
    return ctx


Ctx = Annotated[RequestContext, Depends(request_context)]


def require(*permissions: str):
    def dependency(ctx: Ctx) -> RequestContext:
        ctx.require(*permissions)
        return ctx

    return Depends(dependency)


# --------------------------------------------------------------------------- pagination


class Page(BaseModel):
    items: list[Any]
    next_cursor: str | None = None


def encode_cursor(*values: Any) -> str:
    return base64.urlsafe_b64encode(json.dumps([str(v) for v in values]).encode()).decode().rstrip("=")


def decode_cursor(cursor: str | None) -> list[str] | None:
    if not cursor:
        return None
    try:
        padded = cursor + "=" * (-len(cursor) % 4)
        return json.loads(base64.urlsafe_b64decode(padded.encode()))
    except Exception as exc:  # noqa: BLE001
        raise AppError("Invalid cursor", code="invalid_cursor") from exc


def paginate(db: Session, stmt, model, cursor: str | None, limit: int, order: str = "created_at") -> tuple[list[Any], str | None]:
    limit = max(1, min(limit, 500))
    col = getattr(model, order)
    values = decode_cursor(cursor)
    if values:
        after_value: Any = values[0]
        if order.endswith("_at"):
            after_value = datetime.fromisoformat(values[0])
        elif order.endswith("_date"):
            after_value = datetime.fromisoformat(values[0]).date()
        after_id: Any = int(values[1]) if values[1].isdigit() else uuid.UUID(values[1])
        stmt = stmt.where((col < after_value) | ((col == after_value) & (model.id < after_id)))
    rows = list(db.execute(stmt.order_by(col.desc(), model.id.desc()).limit(limit + 1)).unique().scalars())
    next_cursor = None
    if len(rows) > limit:
        rows = rows[:limit]
        last = rows[-1]
        next_cursor = encode_cursor(getattr(last, order).isoformat() if hasattr(getattr(last, order), "isoformat") else getattr(last, order), last.id)
    return rows, next_cursor


# --------------------------------------------------------------------------- optimistic locking


def etag_for(version: int) -> str:
    return f'W/"{version}"'


def if_match(request: Request) -> int | None:
    value = request.headers.get("if-match")
    if not value:
        return None
    try:
        return int(value.replace("W/", "").strip('" '))
    except ValueError as exc:
        raise PreconditionFailed("Malformed If-Match header") from exc


IfMatch = Annotated[int | None, Depends(if_match)]


# --------------------------------------------------------------------------- idempotency


@dataclass
class Idempotency:
    key: str | None
    request_hash: str | None = None

    def replay(self, db: Session, ctx: RequestContext, body: Any) -> JSONResponse | None:
        if not self.key:
            return None
        self.request_hash = hashlib.sha256(json.dumps(body, sort_keys=True, default=str).encode()).hexdigest()
        row = db.execute(select(IdempotencyKey).where(IdempotencyKey.company_id == ctx.company_id,
                                                      IdempotencyKey.key == self.key)).scalar_one_or_none()
        if row is None:
            return None
        if row.request_hash != self.request_hash:
            raise Conflict("This Idempotency-Key was already used with a different request body", code="idempotency_key_reused")
        return JSONResponse(row.response, status_code=row.status_code or 200, headers={"Idempotent-Replay": "true"})

    def store(self, db: Session, ctx: RequestContext, status_code: int, response: Any) -> None:
        if not self.key:
            return
        db.add(IdempotencyKey(company_id=ctx.company_id, key=self.key, request_hash=self.request_hash or "",
                              status_code=status_code, response=json.loads(json.dumps(response, default=str)),
                              expires_at=datetime.now(UTC) + timedelta(days=2)))


def idempotency(key: Annotated[str | None, Header(alias="Idempotency-Key", max_length=120)] = None) -> Idempotency:
    return Idempotency(key)


Idem = Annotated[Idempotency, Depends(idempotency)]


# --------------------------------------------------------------------------- errors


def _problem(request: Request, status: int, body: dict[str, Any]) -> JSONResponse:
    body.setdefault("instance", str(request.url.path))
    body.setdefault("request_id", getattr(request.state, "request_id", None))
    return JSONResponse(body, status_code=status, media_type="application/problem+json")


def install_error_handlers(app) -> None:
    @app.exception_handler(AppError)
    async def app_error(request: Request, exc: AppError):
        return _problem(request, exc.status, exc.to_problem())

    @app.exception_handler(RequestValidationError)
    async def validation_error(request: Request, exc: RequestValidationError):
        errors = [{"loc": [str(p) for p in e["loc"]], "message": e["msg"], "type": e["type"]} for e in exc.errors()]
        detail = "; ".join(f"{'.'.join(e['loc'][1:]) or e['loc'][0]}: {e['message']}" for e in errors[:3])
        return _problem(request, 422, {"type": "https://biwork.dev/problems/validation-failed", "title": "Validation failed",
                                       "status": 422, "detail": detail, "code": "validation_failed", "errors": errors})

    @app.exception_handler(StaleDataError)
    async def stale(request: Request, exc: StaleDataError):
        return _problem(request, 412, PreconditionFailed("The record was changed by someone else; reload and try again").to_problem())

    @app.exception_handler(IntegrityError)
    async def integrity(request: Request, exc: IntegrityError):
        log.info("integrity error: %s", exc.orig)
        return _problem(request, 409, Conflict("The change conflicts with existing data (a unique or reference rule was violated)",
                                               code="integrity_conflict").to_problem())

    @app.exception_handler(Exception)
    async def unexpected(request: Request, exc: Exception):
        log.exception("unhandled error on %s", request.url.path)
        return _problem(request, 500, {"type": "about:blank", "title": "Internal error", "status": 500,
                                       "detail": "An unexpected error occurred. The request id helps support find it.",
                                       "code": "internal_error"})


# --------------------------------------------------------------------------- middleware


class RequestIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        incoming = request.headers.get("x-request-id", "")
        request.state.request_id = incoming[:64] if incoming.replace("-", "").isalnum() else uuid.uuid4().hex
        started = time.monotonic()
        response = await call_next(request)
        response.headers["X-Request-ID"] = request.state.request_id
        log.info("%s %s %s %.0fms", request.method, request.url.path, response.status_code, (time.monotonic() - started) * 1000,
                 extra={"request_id": request.state.request_id, "company_id": getattr(request.state, "company_id", None)})
        return response


class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        response = await call_next(request)
        response.headers.setdefault("X-Content-Type-Options", "nosniff")
        response.headers.setdefault("X-Frame-Options", "DENY")
        response.headers.setdefault("Referrer-Policy", "no-referrer")
        response.headers.setdefault("Cross-Origin-Resource-Policy", "same-origin")
        if not request.url.path.startswith(("/docs", "/redoc")):
            response.headers.setdefault("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'")
        if get_settings().cookie_secure:
            response.headers.setdefault("Strict-Transport-Security", "max-age=31536000; includeSubDomains")
        return response


class _Window:
    def __init__(self):
        self.lock = threading.Lock()
        self.counts: dict[str, int] = {}

    def hit(self, key: str) -> int:
        with self.lock:
            if len(self.counts) > 50_000:
                self.counts.clear()
            self.counts[key] = self.counts.get(key, 0) + 1
            return self.counts[key]


_local = _Window()


def rate_limit_hit(key: str) -> int:
    """Fixed one-minute window; Redis when configured (shared by all API replicas), else per process."""
    window = int(time.time() // 60)
    full = f"biwork:rl:{key}:{window}"
    url = get_settings().redis_url
    if url:
        try:
            import redis

            client = redis.Redis.from_url(url, socket_timeout=0.5)
            pipe = client.pipeline()
            pipe.incr(full)
            pipe.expire(full, 90)
            return int(pipe.execute()[0])
        except Exception:  # noqa: BLE001  fall back rather than fail open entirely
            pass
    return _local.hit(full)


class RateLimitMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        s = get_settings()
        ip = request.client.host if request.client else "unknown"
        path = request.url.path
        if path.startswith("/api/v1/auth/login") or path.startswith("/api/v1/auth/refresh"):
            limit, bucket = s.auth_rate_limit_per_minute, "auth"
        elif path.startswith("/api/v1/health") or path.startswith("/api/v1/events"):
            return await call_next(request)
        else:
            limit, bucket = s.rate_limit_per_minute, "api"
        if rate_limit_hit(f"{bucket}:{ip}") > limit:
            err = RateLimited("Too many requests; slow down and retry shortly")
            return JSONResponse(err.to_problem(path), status_code=429, media_type="application/problem+json",
                                headers={"Retry-After": str(60 - int(time.time()) % 60)})
        return await call_next(request)
