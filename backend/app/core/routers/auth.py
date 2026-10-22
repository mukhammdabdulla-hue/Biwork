"""Authentication: short-lived JWT access token (kept in memory by the SPA) + rotating refresh token
in an HttpOnly, SameSite=Strict cookie scoped to the auth endpoints (docs/architecture.md §8)."""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Request, Response
from pydantic import BaseModel, Field

from app.config import get_settings
from app.core.api import DB, CurrentUser, client_ip
from app.core.errors import Unauthorized
from app.core.services import identity

router = APIRouter(prefix="/auth", tags=["auth"])
COOKIE_PATH = "/api/v1/auth"


class LoginIn(BaseModel):
    email: str = Field(max_length=320)
    password: str = Field(max_length=200)
    otp: str | None = Field(default=None, max_length=10)


class TokenOut(BaseModel):
    access_token: str
    token_type: str = "bearer"
    expires_in: int
    user: dict[str, Any]


def _set_cookie(response: Response, token: str) -> None:
    s = get_settings()
    response.set_cookie(s.refresh_cookie_name, token, max_age=s.refresh_ttl_days * 86400, httponly=True, secure=s.cookie_secure,
                        samesite="strict", path=COOKIE_PATH)


def _csrf_guard(request: Request) -> None:
    # Cookie-authenticated endpoints additionally require a custom header that cross-site forms cannot send.
    if request.headers.get("x-requested-with") != "biwork":
        raise Unauthorized("Missing X-Requested-With header", code="csrf_check_failed")


@router.post("/login", response_model=TokenOut)
def login(data: LoginIn, request: Request, response: Response, db: DB) -> TokenOut:
    try:
        user = identity.authenticate(db, data.email, data.password, data.otp, client_ip(request))
    except Unauthorized:
        db.commit()  # persist failed-attempt counters and the audit event
        raise
    pair = identity.issue_tokens(db, user, client_ip(request), request.headers.get("user-agent"))
    db.commit()
    _set_cookie(response, pair.refresh_token)
    return TokenOut(access_token=pair.access_token, expires_in=pair.expires_in, user=identity.describe_user(db, user))


@router.post("/refresh", response_model=TokenOut)
def refresh(request: Request, response: Response, db: DB) -> TokenOut:
    _csrf_guard(request)
    token = request.cookies.get(get_settings().refresh_cookie_name)
    if not token:
        raise Unauthorized("Not signed in", code="session_expired")
    try:
        pair = identity.rotate_refresh(db, token, client_ip(request), request.headers.get("user-agent"))
    except Unauthorized:
        db.commit()  # keep family revocation on reuse detection
        response.delete_cookie(get_settings().refresh_cookie_name, path=COOKIE_PATH)
        raise
    db.commit()
    _set_cookie(response, pair.refresh_token)
    return TokenOut(access_token=pair.access_token, expires_in=pair.expires_in, user=identity.describe_user(db, pair.user))


@router.post("/logout", status_code=204)
def logout(request: Request, response: Response, db: DB) -> Response:
    _csrf_guard(request)
    identity.logout(db, request.cookies.get(get_settings().refresh_cookie_name))
    db.commit()
    response = Response(status_code=204)
    response.delete_cookie(get_settings().refresh_cookie_name, path=COOKIE_PATH)
    return response


@router.get("/me")
def me(user: CurrentUser, db: DB) -> dict[str, Any]:
    return identity.describe_user(db, user)


class PasswordIn(BaseModel):
    current_password: str = Field(max_length=200)
    new_password: str = Field(max_length=200)


class SessionOut(BaseModel):
    access_token: str
    token_type: str = "bearer"
    expires_in: int


@router.post("/password", response_model=SessionOut)
def change_password(data: PasswordIn, request: Request, response: Response, user: CurrentUser, db: DB) -> SessionOut:
    """Change the password and sign out every session; the caller continues with the access token returned
    here and a new refresh cookie."""
    pair = identity.change_password(db, user, data.current_password, data.new_password, client_ip(request),
                                    request.headers.get("user-agent"))
    db.commit()
    _set_cookie(response, pair.refresh_token)
    return SessionOut(access_token=pair.access_token, expires_in=pair.expires_in)


class MfaSetupIn(BaseModel):
    """Replacing an authenticator that is already active needs its current code or the account password."""

    code: str | None = Field(default=None, max_length=8)
    password: str | None = Field(default=None, max_length=200)


@router.post("/mfa/setup")
def mfa_setup(user: CurrentUser, db: DB, data: MfaSetupIn | None = None) -> dict[str, str]:
    uri = identity.mfa_begin(db, user, data.code if data else None, data.password if data else None)
    db.commit()
    return {"otpauth_uri": uri}


class MfaConfirmIn(BaseModel):
    code: str = Field(min_length=6, max_length=8)


@router.post("/mfa/confirm", status_code=204)
def mfa_confirm(data: MfaConfirmIn, user: CurrentUser, db: DB) -> Response:
    identity.mfa_confirm(db, user, data.code)
    db.commit()
    return Response(status_code=204)
