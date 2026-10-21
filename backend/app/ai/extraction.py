"""AI use cases: classify, extract, map_columns (docs/architecture.md §7, docs/ai-engine.md).

Only the Interpret stage calls these. Everything returned here is a *proposal*: deterministic code
normalizes, validates, matches, and a person approves before anything is written. The provider and
model for each call come from `app.ai.router`; this module adds retries with backoff, provider
fallback, escalation to a stronger model for uncertain results, the company-scoped result cache,
and a usage record for every attempt.
"""

from __future__ import annotations

import functools
import random
import time
import uuid
from collections.abc import Callable
from dataclasses import dataclass, field, replace
from datetime import date
from decimal import Decimal
from functools import lru_cache
from typing import Any, Literal

from pydantic import BaseModel, Field, ValidationError, create_model

from app.ai import budget, cache, health, prompts, router
from app.ai.policy import AIPolicy, redact
from app.ai.providers.base import (
    FALLBACK_KINDS,
    AIError,
    ContentBlock,
    LLMProvider,
    LLMResult,
    PermanentAIError,
    PromptTask,
    TextBlock,
    TransientAIError,
)
from app.config import get_settings
from app.core.manifest import FieldSpec, RecordTypeSpec

MIN_CLASSIFY_CONFIDENCE = 0.5
DEMO_NOTICE = ("Demo mode: no AI provider credentials are configured, so extraction uses deterministic rules, "
               "not real AI analysis")

# --------------------------------------------------------------------------- output envelopes


class FieldValue(BaseModel):
    value: str | None = Field(description="The value exactly as written in the source, or null")
    confidence: float = Field(ge=0, le=1, description="Probability the value is correct for this field")
    source_quote: str | None = Field(description="Shortest exact excerpt containing the value, or null")
    not_found: bool = Field(description="True when the field is not present in the data")
    alternatives: list[str] = Field(description="Other, conflicting values stated in the source for this field")


def _field_doc(f: FieldSpec) -> str:
    parts = [f"{f.label}"]
    if f.description:
        parts.append(f.description)
    if f.enum:
        parts.append("one of: " + ", ".join(f.enum) + " (copy the source wording; it is mapped later)")
    return ". ".join(parts)


@lru_cache
def envelope_for(rt: RecordTypeSpec) -> type[BaseModel]:
    fields: dict[str, Any] = {f.name: (FieldValue, Field(description=_field_doc(f))) for f in rt.fields}
    if rt.line_fields:
        line = create_model(f"{rt.code}_line", **{f.name: (FieldValue, Field(description=_field_doc(f))) for f in rt.line_fields})
        fields["lines"] = (list[line], Field(description=f"{rt.lines_label}: one entry per row/item"))
    record = create_model(f"{rt.code}_record", **fields)
    return create_model(f"{rt.code}_extraction", records=(list[record], Field(description=f"Every {rt.label} in the data")))


def classification_model(codes: tuple[str, ...]) -> type[BaseModel]:
    code_type = Literal[codes]  # type: ignore[valid-type]
    item = create_model("ClassifiedType", record_type=(code_type, ...), count_estimate=(int, ...),
                        confidence=(float, Field(ge=0, le=1)), reason=(str, ...))
    return create_model("Classification", record_types=(list[item], ...), language=(str, ...),
                        contains_instructions=(bool, ...))


def mapping_model(codes: tuple[str, ...], field_names: tuple[str, ...]) -> type[BaseModel]:
    column = create_model("ColumnMap", column_index=(int, ...),
                          field=(Literal[field_names] | None, ...),  # type: ignore[valid-type]
                          confidence=(float, Field(ge=0, le=1)))
    return create_model("ColumnMapping", record_type=(Literal[codes], ...), columns=(list[column], ...))  # type: ignore[valid-type]


def _field_params(fields: tuple[FieldSpec, ...]) -> list[dict[str, Any]]:
    return [{"name": f.name, "label": f.label, "type": f.type, "aliases": list(f.aliases), "enum": list(f.enum),
             "reference": f.reference, "required": f.required} for f in fields]


def _field_list(fields: tuple[FieldSpec, ...]) -> str:
    return "\n".join(f"- {f.name} ({f.type}{', required' if f.required else ''}): {_field_doc(f)}" for f in fields)


# --------------------------------------------------------------------------- call plumbing

@dataclass
class AIContext:
    company_id: uuid.UUID
    job_id: uuid.UUID | None
    item_id: uuid.UUID | None
    policy: AIPolicy
    stage: str = "interpret"
    reference_date: date | None = None
    provider: LLMProvider | None = None  # forces one provider (tests, evaluation runs); normally the router decides
    calls: list[dict[str, Any]] = field(default_factory=list)
    # Persists one AI call record; supplied by the caller (the Agent writes agent.ai_calls).
    log: Callable[[dict[str, Any]], None] | None = None
    sleep: Callable[[float], None] = time.sleep  # backoff between retries (replaced in tests)


@dataclass
class CallOutcome:
    parsed: BaseModel
    candidate: router.Candidate
    is_mock: bool
    cached: bool = False


def _log_call(ctx: AIContext, provider_name: str, is_mock: bool, prompt: prompts.Prompt, cand: router.Candidate, status: str, *,
              attempt: int, result: LLMResult | None = None, error: str | None = None, categories: list[str],
              request: dict[str, Any] | None = None, schema_version: str | None = None) -> None:
    """Every attempt is reported to the caller's logger, including failed (and billed) ones."""
    model = result.model if result else cand.model
    cost = budget.estimate_cost(model, result.input_tokens if result else None, result.output_tokens if result else None)
    if status == "cached" or is_mock:
        cost = Decimal(0)
    keep = ctx.policy.retain_ai_payloads_days > 0
    row = dict(
        company_id=ctx.company_id, job_id=ctx.job_id, item_id=ctx.item_id, stage=ctx.stage, task=prompt.task,
        provider=provider_name, model=model[:60], is_mock=is_mock, prompt_id=prompt.task,
        prompt_version=prompt.version, schema_version=schema_version, input_tokens=result.input_tokens if result else None,
        output_tokens=result.output_tokens if result else None, cost_usd=cost, latency_ms=result.latency_ms if result else None,
        status=status, error=error, provider_request_id=result.request_id if result else None, data_categories=categories,
        request_redacted=request if keep else None,
        response=result.parsed.model_dump(mode="json") if (keep and result) else None,
        tier=cand.tier, routing_reason=cand.reason[:300], attempt=attempt, fallback_from=cand.fallback_from,
    )
    ctx.calls.append({k: row[k] for k in ("task", "provider", "model", "tier", "status", "attempt", "input_tokens", "output_tokens",
                                          "cost_usd", "latency_ms")})
    if ctx.log is not None:
        ctx.log(row)


def _backoff(attempt: int, retry_after: float | None) -> float:
    s = get_settings()
    if retry_after:
        return retry_after
    delay = min(s.ai_retry_max_delay_s, s.ai_retry_base_delay_s * (2 ** (attempt - 1)))
    return delay * random.uniform(0.5, 1.0)


def _call(ctx: AIContext, task_name: str, prompt: prompts.Prompt, instructions: str, content: list[ContentBlock],
          output_schema: type[BaseModel], params: dict[str, Any], categories: list[str], *, schema_version: str | None = None,
          escalate: bool = False, reason: str | None = None, cache_payload: Any = None,
          candidates: list[router.Candidate] | None = None) -> CallOutcome:
    """Run one task through the routed candidates: retries with backoff for transient errors, one
    repair attempt for schema-invalid output, then the next provider (when fallback is enabled).
    Raises TransientAIError when every candidate failed transiently (the job is kept for a safe
    retry) and PermanentAIError otherwise. Never returns unvalidated output."""
    s = get_settings()
    rule = router.task_rule(task_name)
    if candidates is None:
        candidates = router.route(task_name, ctx.policy, escalate=escalate, reason=reason, forced=ctx.provider)
    request_summary = {"prompt": prompt.ref, "task": task_name,
                       "content": [b.text[:20000] if isinstance(b, TextBlock) else f"<{b.type}>" for b in content]}
    last_error: AIError | None = None
    for cand in candidates:
        provider = ctx.provider or router.get_provider(cand.provider)
        if provider.external:
            budget.acquire(provider.name, str(ctx.company_id))
        key = None
        if rule.cacheable and cache_payload is not None:
            key = cache.make_key(ctx.company_id, task_name, prompt.ref, provider.name, cand.model, cache_payload)
            hit = cache.get(key)
            if hit is not None:
                try:
                    parsed = output_schema.model_validate(hit)
                except ValidationError:
                    parsed = None
                if parsed is not None:
                    _log_call(ctx, provider.name, provider.is_mock, prompt, cand, "cached", attempt=0, categories=[],
                              schema_version=schema_version)
                    return CallOutcome(parsed, cand, provider.is_mock, cached=True)
        task = PromptTask(prompt.task, prompt.version, prompt.system, instructions, cand.model, params, rule.max_output_tokens)
        log = functools.partial(_log_call, ctx, provider.name, provider.is_mock, prompt, cand, categories=categories,
                                request=request_summary, schema_version=schema_version)
        attempt, repaired = 0, False
        while True:
            attempt += 1
            try:
                result = provider.generate(task=task, content=content, output_schema=output_schema, timeout_s=s.ai_timeout_s)
                try:
                    parsed = output_schema.model_validate(result.parsed.model_dump())
                except ValidationError as exc:
                    raise PermanentAIError(f"AI output did not match the schema: {exc.error_count()} error(s)",
                                           "invalid_output") from exc
            except TransientAIError as exc:
                log("error", attempt=attempt, error=str(exc))
                health.record_failure(provider.name, str(exc))
                last_error = exc
                if attempt <= s.ai_max_retries and (exc.retry_after is None or exc.retry_after <= s.ai_retry_max_delay_s):
                    ctx.sleep(_backoff(attempt, exc.retry_after))
                    continue
                break
            except PermanentAIError as exc:
                status = {"refused": "refused", "invalid_output": "invalid_output"}.get(exc.kind, "error")
                log(status, attempt=attempt, error=str(exc))
                last_error = exc
                if exc.kind == "invalid_output" and not repaired:
                    repaired = True  # one repair attempt on the same model
                    continue
                if exc.kind in ("auth", "not_configured"):
                    health.record_failure(provider.name, str(exc))
                if exc.kind in FALLBACK_KINDS:
                    break
                raise
            result.parsed = parsed
            log("ok", attempt=attempt, result=result)
            health.record_success(provider.name, result.latency_ms)
            if key is not None:
                cache.put(key, parsed.model_dump(mode="json"))
            return CallOutcome(parsed, replace(cand, model=result.model or cand.model), provider.is_mock)
    if isinstance(last_error, TransientAIError):
        raise TransientAIError(f"All configured AI providers are unavailable right now ({last_error}); the job is kept "
                               "and can be retried safely", last_error.retry_after) from last_error
    if isinstance(last_error, PermanentAIError):
        if last_error.kind == "invalid_output":
            raise PermanentAIError(f"AI output failed schema validation on every attempt: {last_error}", "invalid_output") \
                from last_error
        raise last_error
    raise PermanentAIError("No AI provider could run this task", "not_configured")  # pragma: no cover


def estimate_tokens(text: str) -> int:
    """Conservative estimate without a provider tokenizer: Cyrillic text tokenizes denser than Latin."""
    cyrillic = sum(1 for ch in text if "Ѐ" <= ch <= "ӿ")
    return int((len(text) - cyrillic) / 4 + cyrillic / 2.5) + 1

def _prepare_text(ctx: AIContext, text: str) -> tuple[str, dict[str, int]]:
    s = get_settings()
    redacted, counts = redact(text, ctx.policy.redaction_rules)
    if len(redacted) > s.ai_max_input_chars:
        raise PermanentAIError(
            f"The document text is too long for one extraction ({len(redacted):,} characters, limit "
            f"{s.ai_max_input_chars:,}); split it into smaller files", "too_large"
        )
    tokens = estimate_tokens(redacted)
    if tokens > s.ai_max_input_tokens:
        raise PermanentAIError(
            f"The document text is too long for one extraction (about {tokens:,} tokens, limit "
            f"{s.ai_max_input_tokens:,}); split it into smaller files", "too_large"
        )
    return redacted, counts


# --------------------------------------------------------------------------- use cases


@dataclass
class Classification:
    record_types: list[dict[str, Any]]
    contains_instructions: bool
    language: str
    candidate: router.Candidate | None = None


def classify(ctx: AIContext, text: str, candidates: list[RecordTypeSpec]) -> Classification:
    """Economy tier; escalates once to the default tier when nothing is recognized with confidence."""
    prompt = prompts.load("classify")
    redacted, _ = _prepare_text(ctx, text)
    codes = tuple(rt.code for rt in candidates)
    listing = "\n".join(f"- {rt.code}: {rt.label}. {rt.description}" for rt in candidates)
    args = (ctx, "classify", prompt, prompt.instructions.substitute(record_types=listing), [TextBlock(redacted)],
            classification_model(codes),
            {"record_types": [{"code": rt.code, "label": rt.label, "description": rt.description} for rt in candidates]},
            ["text"])
    out = _call(*args, cache_payload={"text": redacted, "types": codes})
    data = out.parsed.model_dump()
    if not any(r["confidence"] >= MIN_CLASSIFY_CONFIDENCE for r in data["record_types"]) and _may_escalate(ctx, "classify", out):
        reason = "no record type recognized with confidence"
        escalated = _escalated_candidates(ctx, "classify", out, reason)
        if escalated:
            out = _call(*args, cache_payload={"text": redacted, "types": codes}, candidates=escalated)
            data = out.parsed.model_dump()
    return Classification(sorted(data["record_types"], key=lambda r: -r["confidence"]), data["contains_instructions"],
                          data["language"], out.candidate)


@dataclass
class Extraction:
    records: list[dict[str, Any]]
    candidate: router.Candidate
    is_mock: bool
    mean_confidence: float | None
    concerns: list[str]  # why the result is uncertain (empty = no concern)
    escalated: bool = False
    escalation_reason: str | None = None
    notes: list[str] = field(default_factory=list)


def _found_values(records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    values = []
    for rec in records:
        for name, fv in rec.items():
            if name == "lines":
                for line in fv or []:
                    values.extend(v for v in line.values() if isinstance(v, dict) and not v.get("not_found"))
            elif isinstance(fv, dict) and not fv.get("not_found"):
                values.append(fv)
    return values


def assess_extraction(records: list[dict[str, Any]], rt: RecordTypeSpec) -> tuple[float | None, list[str]]:
    """Evidence-based quality signals for an extraction (deterministic, no model call)."""
    rules = router.escalation_rules()
    concerns: list[str] = []
    if not records:
        if rules.get("on_no_records", True):
            concerns.append("no records extracted")
        return None, concerns
    values = _found_values(records)
    mean = round(sum(float(v.get("confidence") or 0) for v in values) / len(values), 3) if values else None
    threshold = get_settings().ai_escalation_confidence
    if rules.get("on_low_confidence", True) and (mean is None or mean < threshold):
        concerns.append(f"mean field confidence {mean if mean is not None else 0:.2f} below {threshold:.2f}")
    if rules.get("on_conflicting_values", True):
        conflicted = sorted({name for rec in records for name, fv in rec.items()
                             if name != "lines" and isinstance(fv, dict) and fv.get("alternatives")})
        if conflicted:
            concerns.append(f"conflicting values for {', '.join(conflicted)}")
    required = [f.name for f in rt.fields if f.required]
    if rules.get("on_missing_required", True) and required:
        missing = [name for name in required if all((rec.get(name) or {}).get("not_found", True) for rec in records)]
        if len(missing) / len(required) >= float(rules.get("missing_required_share", 0.5)):
            concerns.append(f"required field(s) missing: {', '.join(missing)}")
    return mean, concerns


def _may_escalate(ctx: AIContext, task: str, out: CallOutcome) -> bool:
    return get_settings().ai_routing_enabled and not out.is_mock and router.task_rule(task).escalate_to is not None


def _escalated_candidates(ctx: AIContext, task: str, out: CallOutcome, reason: str) -> list[router.Candidate]:
    """Escalation candidates, without the model that already answered (re-asking it would not help)."""
    try:
        cands = router.route(task, ctx.policy, escalate=True, reason=reason, forced=ctx.provider)
    except PermanentAIError:
        return []
    return [c for c in cands if (c.provider, c.model) != (out.candidate.provider, out.candidate.model)]


def _better(first: Extraction, second: Extraction) -> bool:
    if len(second.concerns) != len(first.concerns):
        return len(second.concerns) < len(first.concerns)
    return (second.mean_confidence or 0) > (first.mean_confidence or 0)  # a tie keeps the first, cheaper answer


def extract(ctx: AIContext, rt: RecordTypeSpec, text: str, extra_blocks: list[ContentBlock] | None = None) -> Extraction:
    """Routine extraction on the default (or vision) tier; escalates to the complex tier when the
    result is uncertain, conflicting, incomplete, or schema-invalid. The better-supported result wins;
    remaining concerns are passed on so the record is routed to human review."""
    prompt = prompts.load("extract")
    redacted, _ = _prepare_text(ctx, text)
    lines_note = ""
    if rt.line_fields:
        lines_note = f"\n{rt.lines_label} (repeat for every row/item, in the field `lines`):\n{_field_list(rt.line_fields)}\n"
    instructions = prompt.instructions.substitute(label=rt.label, code=rt.code, description=rt.description,
                                                  fields=_field_list(rt.fields), lines_note=lines_note)
    blocks: list[ContentBlock] = [*(extra_blocks or []), TextBlock(redacted)]
    categories = sorted({"text", *(b.type for b in extra_blocks or [])})
    task = "extract_vision" if extra_blocks else "extract"
    args = (ctx, task, prompt, instructions, blocks, envelope_for(rt),
            {"record_type": {"code": rt.code, "label": rt.label, "fields": _field_params(rt.fields),
                             "line_fields": _field_params(rt.line_fields)}},
            categories)
    rules = router.escalation_rules()

    try:
        out = _call(*args, schema_version=rt.schema_version)
    except PermanentAIError as exc:
        if exc.kind != "invalid_output" or not rules.get("on_invalid_output", True) or not get_settings().ai_routing_enabled:
            raise
        # Alternative strategy: a stronger model, before failing the item.
        escalated = router.route(task, ctx.policy, escalate=True, reason="schema-invalid output", forced=ctx.provider)
        if not escalated:
            raise
        out = _call(*args, schema_version=rt.schema_version, candidates=escalated)
        records = out.parsed.model_dump()["records"]
        mean, concerns = assess_extraction(records, rt)
        return Extraction(records, out.candidate, out.is_mock, mean, concerns, True, "schema-invalid output")

    records = out.parsed.model_dump()["records"]
    mean, concerns = assess_extraction(records, rt)
    first = Extraction(records, out.candidate, out.is_mock, mean, concerns)
    if not concerns or not _may_escalate(ctx, task, out):
        return first
    reason = "; ".join(concerns)
    escalated = _escalated_candidates(ctx, task, out, reason)
    if not escalated:
        first.notes.append(f"Uncertain extraction ({reason}); no stronger model is configured, so it goes to review")
        return first
    try:
        out2 = _call(*args, schema_version=rt.schema_version, candidates=escalated)
    except (TransientAIError, PermanentAIError) as exc:
        first.notes.append(f"Escalation to a stronger model failed ({exc}); the first result goes to review")
        return first
    records2 = out2.parsed.model_dump()["records"]
    mean2, concerns2 = assess_extraction(records2, rt)
    second = Extraction(records2, out2.candidate, out2.is_mock, mean2, concerns2, True, reason)
    if _better(first, second):
        return second
    first.escalated, first.escalation_reason = True, reason
    first.notes.append(f"Escalated ({reason}) but {out2.candidate.model} was not better supported; kept the first result")
    return first


def map_columns(ctx: AIContext, columns: list[dict[str, Any]], candidates: list[RecordTypeSpec]) -> dict[str, Any]:
    """Columns: [{index, header, samples:[...]}]. Only headers and a few sample rows are sent."""
    prompt = prompts.load("map_columns")
    sample_rows = 3 if ctx.policy.minimize_context else 10
    cols = [{**c, "samples": [redact(str(v), ctx.policy.redaction_rules)[0] for v in c["samples"][:sample_rows]]} for c in columns]
    schemas = "\n".join(
        f"- {rt.code} ({rt.label}): " + "; ".join(f"{f.name} = {f.label}" for f in (*rt.fields, *rt.line_fields))
        for rt in candidates
    )
    listing = "\n".join(f"{c['index']}: {c['header']!r} samples={c['samples']}" for c in cols)
    names = tuple(sorted({f.name for rt in candidates for f in (*rt.fields, *rt.line_fields)}))
    codes = tuple(rt.code for rt in candidates)
    out = _call(ctx, "map_columns", prompt, prompt.instructions.substitute(schemas=schemas, columns=listing),
                [TextBlock(listing, label="spreadsheet_columns")], mapping_model(codes, names),
                {"columns": cols, "record_types": [{"code": rt.code, "fields": _field_params(rt.fields),
                                                    "line_fields": _field_params(rt.line_fields)} for rt in candidates]},
                ["table_sample"], cache_payload={"columns": cols, "types": codes})
    return out.parsed.model_dump()


def provider_info() -> dict[str, Any]:
    """What a new job will most likely use (the router may still fall back or escalate per item)."""
    order = router.provider_order()
    primary = order[0] if order else None
    demo = router.demo_mode()
    return {"provider": primary, "is_mock": demo, "mode": "demo" if demo else "live",
            "external": bool(primary and primary != router.DEMO),
            "supports_vision": bool(primary and primary != router.DEMO),
            "model_extract": router.model_for(primary, "default") if primary else None,
            "model_classify": router.model_for(primary, "economy") if primary else None,
            "provider_order": order, "prompt_version": prompts.pipeline_prompt_version(),
            "demo_notice": DEMO_NOTICE if demo else None}
