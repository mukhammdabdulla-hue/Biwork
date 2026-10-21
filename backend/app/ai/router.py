"""Configurable task-based model router (docs/ai-engine.md §Routing).

The router turns a *task* (classify, map_columns, extract, extract_vision) and an optional
escalation request into an ordered list of candidates (provider, model, tier). Rules come from
`routing.default.json` (or AI_ROUTING_FILE); model ids come only from settings
(<PROVIDER>_MODEL_<TIER>), so switching models or providers is a configuration change.

- Tiers: economy (classification, column mapping), default (routine structured extraction),
  complex (escalation for ambiguous / conflicting / low-confidence results), vision (images, scans).
- Provider order: AI_PROVIDER when it names one provider, else AI_PRIMARY_PROVIDER, then
  AI_FALLBACK_PROVIDERS (only when AI_FALLBACK_ENABLED). Providers without credentials, not allowed
  by the company's AI policy, or currently marked down by the health monitor are skipped or tried
  last.
- With AI_ROUTING_ENABLED=false every task uses the default tier and nothing escalates.
- With no credentials at all, the only candidate is the labelled demo provider. The demo provider is
  never used as a fallback for a real provider.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any

from app.ai import health
from app.ai.policy import AIPolicy, PolicyBlocked
from app.ai.providers.base import LLMProvider, PermanentAIError
from app.config import get_settings

REAL_PROVIDERS = ("openai", "gemini", "anthropic")
DEMO = "demo"
TIERS = ("economy", "default", "complex", "vision")
_DEFAULT_FILE = Path(__file__).with_name("routing.default.json")


@dataclass(frozen=True)
class Candidate:
    provider: str
    model: str
    tier: str
    reason: str
    fallback_from: str | None = None  # the provider this candidate stands in for


@dataclass(frozen=True)
class TaskRule:
    tier: str
    escalate_to: str | None
    cacheable: bool
    max_output_tokens: int


@lru_cache
def routing_table() -> dict[str, Any]:
    s = get_settings()
    path = s.ai_routing_file or _DEFAULT_FILE
    table = json.loads(Path(path).read_text(encoding="utf-8"))
    for task, rule in table["tasks"].items():
        for key in ("tier", "escalate_to"):
            if rule.get(key) is not None and rule[key] not in TIERS:
                raise ValueError(f"routing table: task {task} has unknown {key} '{rule[key]}' (tiers: {', '.join(TIERS)})")
    return table


def task_rule(task: str) -> TaskRule:
    rule = routing_table()["tasks"][task]
    return TaskRule(rule["tier"], rule.get("escalate_to"), bool(rule.get("cacheable")),
                    int(rule.get("max_output_tokens") or get_settings().ai_max_output_tokens))


def escalation_rules() -> dict[str, Any]:
    return routing_table().get("escalation", {})


# --------------------------------------------------------------------------- providers


_instances: dict[str, LLMProvider] = {}


def get_provider(name: str) -> LLMProvider:
    """Provider instances are created lazily and reused (SDK clients keep connection pools)."""
    if name in ("mock", DEMO):
        name = DEMO
    if name not in _instances:
        if name == "openai":
            from app.ai.providers.openai import OpenAIProvider

            _instances[name] = OpenAIProvider()
        elif name == "gemini":
            from app.ai.providers.gemini import GeminiProvider

            _instances[name] = GeminiProvider()
        elif name == "anthropic":
            from app.ai.providers.anthropic import AnthropicProvider

            _instances[name] = AnthropicProvider()
        elif name == DEMO:
            from app.ai.providers.demo import DemoProvider

            _instances[name] = DemoProvider()
        else:
            raise PermanentAIError(f"Unknown AI provider '{name}'", "not_configured")
    return _instances[name]


def reset() -> None:
    _instances.clear()
    routing_table.cache_clear()


def demo_mode() -> bool:
    s = get_settings()
    return s.ai_provider in ("demo", "mock") or (s.ai_provider == "auto" and not s.configured_ai_providers())


def provider_order() -> list[str]:
    """Configured providers in the order they are tried."""
    s = get_settings()
    if demo_mode():
        return [DEMO]
    configured = s.configured_ai_providers()
    first = s.ai_provider if s.ai_provider in REAL_PROVIDERS else s.ai_primary_provider
    order = [first, *s.ai_fallback_providers] if s.ai_fallback_enabled else [first]
    if s.ai_provider == "auto" and first not in configured:
        # Primary has no key: the first configured provider takes its place.
        order = [*order, *configured] if s.ai_fallback_enabled else [configured[0]]
    return [p for p in dict.fromkeys(order) if p in configured]


def model_for(provider: str, tier: str) -> str | None:
    if provider == DEMO:
        return "demo-rules-v1"
    return get_settings().provider_model(provider, tier)


def _allowed(policy: AIPolicy, name: str, external: bool) -> str | None:
    try:
        policy.check(name, external)
    except PolicyBlocked as exc:
        return str(exc)
    return None


def route(task: str, policy: AIPolicy, *, escalate: bool = False, reason: str | None = None,
          forced: LLMProvider | None = None) -> list[Candidate]:
    """Ordered candidates for a task. Raises PolicyBlocked when the company's policy excludes every provider."""
    s = get_settings()
    rule = task_rule(task)
    tier = rule.tier
    why = reason or f"{task} → {tier} tier"
    if not s.ai_routing_enabled:
        tier, why = "default", "routing disabled → default tier"
    elif escalate:
        if not rule.escalate_to:
            return []
        tier = rule.escalate_to
        why = f"escalated to {tier} tier: {reason}" if reason else f"escalated to {tier} tier"

    if forced is not None:  # an injected provider (tests, evaluation runs)
        blocked = _allowed(policy, forced.name, forced.external)
        if blocked:
            raise PolicyBlocked(blocked)
        model = getattr(forced, "model", None) or model_for(forced.name, tier) or f"{forced.name}-{tier}"
        return [Candidate(forced.name, model, tier, why)]

    needs_vision = task == "extract_vision"
    candidates: list[Candidate] = []
    unhealthy: list[Candidate] = []
    blocked_reasons: list[str] = []
    order = provider_order()
    for name in order:
        external = name != DEMO
        blocked = _allowed(policy, name, external)
        if blocked:
            blocked_reasons.append(blocked)
            continue
        if needs_vision and name != DEMO and not get_provider(name).supports_vision:
            continue
        model = model_for(name, tier) or (model_for(name, "default") if tier == "complex" else None)
        if not model:
            continue
        fallback_from = order[0] if name != order[0] else None
        cand = Candidate(name, model, tier, why if fallback_from is None else f"{why}; fallback for {order[0]}", fallback_from)
        (candidates if health.is_available(name) else unhealthy).append(cand)
    result = candidates + unhealthy  # a provider marked down is still tried when nothing else is left
    if not result and blocked_reasons:
        raise PolicyBlocked(blocked_reasons[0])
    if not result:
        raise PermanentAIError("No AI provider is configured for this task; check the AI_* and *_MODEL_* settings",
                               "not_configured")
    return result


def describe() -> dict[str, Any]:
    """Effective routing for diagnostics (no secrets)."""
    s = get_settings()
    order = provider_order()
    return {
        "mode": "demo" if demo_mode() else "live",
        "provider_order": order,
        "routing_enabled": s.ai_routing_enabled,
        "fallback_enabled": s.ai_fallback_enabled,
        "review_required": s.ai_review_required,
        "tasks": {task: {"tier": r["tier"], "escalate_to": r.get("escalate_to")} for task, r in routing_table()["tasks"].items()},
        "models": {p: {t: model_for(p, t) for t in TIERS} for p in order},
        "configured": {p: p in s.configured_ai_providers() for p in REAL_PROVIDERS},
    }


def health_report() -> dict[str, Any]:
    """Per-provider configuration and circuit state for the admin and health endpoints."""
    s = get_settings()
    snap = health.snapshot()
    order = provider_order()
    return {
        name: {"configured": name in s.configured_ai_providers(), "in_rotation": name in order,
               "models": {t: model_for(name, t) for t in TIERS}, **snap.get(name, {"state": "up", "calls": 0, "failures": 0})}
        for name in (*REAL_PROVIDERS, *( [DEMO] if demo_mode() else []))
    }
