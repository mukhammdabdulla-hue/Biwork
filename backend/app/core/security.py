"""Password hashing (argon2id), JWT access tokens, opaque token hashing, field encryption."""

from __future__ import annotations

import base64
import hashlib
import secrets
import uuid
from datetime import UTC, datetime, timedelta
from typing import Any

import jwt
from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerificationError
from cryptography.fernet import Fernet

from app.config import get_settings, is_placeholder

_hasher = PasswordHasher()  # argon2id with library-recommended parameters
_JWT_ALG = "HS256"
# Burn time on unknown-user logins so response timing does not reveal which emails exist.
_DUMMY_HASH = _hasher.hash("dummy-password-for-timing")


def hash_password(password: str) -> str:
    return _hasher.hash(password)


def verify_password(password_hash: str | None, password: str) -> bool:
    try:
        return _hasher.verify(password_hash or _DUMMY_HASH, password) and password_hash is not None
    except (VerificationError, InvalidHashError):
        return False


def password_problems(password: str) -> list[str]:
    settings = get_settings()
    problems = []
    if len(password) < settings.password_min_length:
        problems.append(f"at least {settings.password_min_length} characters")
    if password.lower() == password or password.upper() == password:
        problems.append("mixed upper and lower case")
    if not any(c.isdigit() for c in password):
        problems.append("at least one digit")
    return problems


def create_access_token(user_id: uuid.UUID, *, extra: dict[str, Any] | None = None) -> tuple[str, int]:
    settings = get_settings()
    ttl = settings.jwt_access_ttl_minutes * 60
    now = datetime.now(UTC)
    claims = {
        "sub": str(user_id),
        "iat": now.timestamp(),  # sub-second, so a token issued right after a password change outlives it
        "exp": now + timedelta(seconds=ttl),
        "typ": "access",
        "jti": secrets.token_hex(8),
        **(extra or {}),
    }
    return jwt.encode(claims, settings.jwt_signing_key, algorithm=_JWT_ALG), ttl


def decode_access_token(token: str) -> dict[str, Any]:
    claims = jwt.decode(token, get_settings().jwt_signing_key, algorithms=[_JWT_ALG], options={"require": ["exp", "iat", "sub"]})
    if claims.get("typ") != "access":
        raise jwt.InvalidTokenError("wrong token type")
    return claims


def new_opaque_token(prefix: str = "") -> str:
    return prefix + secrets.token_urlsafe(32)


def token_hash(token: str) -> str:
    return hashlib.sha256(token.encode()).hexdigest()


def _fernet() -> Fernet:
    settings = get_settings()
    key = settings.field_encryption_key
    if not key or (settings.app_env in ("dev", "test") and is_placeholder(key)):
        # Development fallback derived from the JWT key, also used for the "change-me" value a local trial
        # keeps from .env.example; staging and production refuse to start without a real key.
        key = base64.urlsafe_b64encode(hashlib.sha256(settings.jwt_signing_key.encode()).digest()).decode()
    return Fernet(key.encode() if isinstance(key, str) else key)


def encrypt_field(value: str) -> bytes:
    return _fernet().encrypt(value.encode())


def decrypt_field(value: bytes) -> str:
    return _fernet().decrypt(value).decode()
