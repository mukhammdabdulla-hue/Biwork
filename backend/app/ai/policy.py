"""Per-company AI data-handling policy (docs/architecture.md §7.5) and prompt-injection detection (§7.7)."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from app.ai.providers.base import PermanentAIError


class PolicyBlocked(PermanentAIError):
    """The company's AI policy does not allow this provider / this data to be sent."""


@dataclass(frozen=True)
class AIPolicy:
    external_ai_allowed: bool = True
    allowed_providers: tuple[str, ...] = ("anthropic", "mock")
    send_images_to_ai: bool = False
    redaction_rules: tuple[str, ...] = ("phone", "email", "passport", "card", "bank_account", "personal_id")
    minimize_context: bool = True
    retain_ai_payloads_days: int = 0
    extra: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_settings(cls, settings: dict[str, Any] | None) -> AIPolicy:
        p = (settings or {}).get("ai_policy") or {}
        return cls(
            external_ai_allowed=bool(p.get("external_ai_allowed", True)),
            allowed_providers=tuple(p.get("allowed_providers") or ("anthropic", "mock")),
            send_images_to_ai=bool(p.get("send_images_to_ai", False)),
            redaction_rules=tuple(p.get("redaction_rules") if p.get("redaction_rules") is not None else cls.redaction_rules),
            minimize_context=bool(p.get("minimize_context", True)),
            retain_ai_payloads_days=int(p.get("retain_ai_payloads_days", 0) or 0),
        )

    def check(self, provider_name: str, external: bool) -> None:
        if provider_name not in self.allowed_providers:
            raise PolicyBlocked(f"AI provider '{provider_name}' is not allowed by this company's AI policy")
        if external and not self.external_ai_allowed:
            raise PolicyBlocked(
                "External AI processing is disabled by this company's AI policy. Spreadsheet imports still work "
                "with rule-based column mapping; text and document extraction needs an allowed AI provider."
            )


REDACTION_PATTERNS: dict[str, re.Pattern[str]] = {
    "email": re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.-]+\b"),
    "phone": re.compile(r"(?<!\d)(?:\+?998[\s\-]?)?\(?\d{2}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?!\d)|\+\d[\d\s\-()]{9,}\d"),
    "passport": re.compile(r"\b[A-Z]{2}\s?\d{7}\b"),
    "card": re.compile(r"\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{4}\b"),
    "bank_account": re.compile(r"\b\d{20}\b"),
    "personal_id": re.compile(r"\b\d{14}\b"),
}


def redact(text: str, rules: tuple[str, ...] | list[str]) -> tuple[str, dict[str, int]]:
    counts: dict[str, int] = {}
    # Longer identifiers first so a 20-digit account is not partially matched as something else.
    for rule in ("email", "bank_account", "card", "personal_id", "passport", "phone"):
        if rule not in rules:
            continue
        text, n = REDACTION_PATTERNS[rule].subn(f"[REDACTED:{rule}]", text)
        if n:
            counts[rule] = n
    return text, counts


_INJECTION = re.compile(
    r"(ignore|disregard|forget)\s+(all\s+|any\s+|the\s+)?(previous|prior|above|earlier)?\s*(instructions|rules|prompts?)"
    r"|system\s+prompt|you\s+are\s+now|act\s+as\s+(an?\s+)?(admin|administrator|system)"
    r"|(approve|auto-?approve|commit)\s+(this|all|every)\s+(record|row|document)s?"
    r"|set\s+(the\s+)?confidence"
    r"|игнорир\w*\s+(все\s+|предыдущ\w+\s+)?(инструкц|правил)|забудь\s+(все|предыдущ)|ты\s+теперь|одобри\s+(все|эт)"
    r"|(ko'rsatma|ko‘rsatma|yo'riqnoma)\w*\s+(e'tiborsiz|e’tiborsiz|unut)|barchasini\s+tasdiqla",
    re.IGNORECASE,
)


def detect_injection(text: str) -> list[str]:
    """Instruction-like phrases addressed to an AI. Content is still processed strictly as data;
    the finding is surfaced to reviewers as a warning."""
    return sorted({m.group(0).strip()[:80] for m in _INJECTION.finditer(text or "")})[:5]
