"""Application errors rendered as RFC 9457 problem+json (docs/architecture.md §5.4)."""

from __future__ import annotations

from typing import Any


class AppError(Exception):
    status = 400
    code = "bad_request"
    title = "Bad request"

    def __init__(self, detail: str | None = None, *, code: str | None = None, extra: dict[str, Any] | None = None):
        super().__init__(detail or self.title)
        self.detail = detail or self.title
        if code:
            self.code = code
        self.extra = extra or {}

    def to_problem(self, instance: str | None = None) -> dict[str, Any]:
        body = {
            "type": f"https://biwork.dev/problems/{self.code.replace('_', '-')}",
            "title": self.title,
            "status": self.status,
            "detail": self.detail,
            "code": self.code,
        }
        if instance:
            body["instance"] = instance
        body.update(self.extra)
        return body


class Unauthorized(AppError):
    status, code, title = 401, "unauthorized", "Authentication required"


class PermissionDenied(AppError):
    status, code, title = 403, "permission_denied", "Permission denied"


class NotFound(AppError):
    status, code, title = 404, "not_found", "Not found"


class Conflict(AppError):
    status, code, title = 409, "conflict", "Conflict"


class PreconditionFailed(AppError):
    status, code, title = 412, "precondition_failed", "Precondition failed"


class PreconditionRequired(AppError):
    status, code, title = 428, "precondition_required", "Precondition required"


class PayloadTooLarge(AppError):
    status, code, title = 413, "payload_too_large", "Payload too large"


class UnsupportedMediaType(AppError):
    status, code, title = 415, "unsupported_media_type", "Unsupported media type"


class ValidationFailed(AppError):
    status, code, title = 422, "validation_failed", "Validation failed"


class RateLimited(AppError):
    status, code, title = 429, "rate_limited", "Too many requests"


class ServiceUnavailable(AppError):
    status, code, title = 503, "service_unavailable", "Service unavailable"
