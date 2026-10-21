"""AI use cases: classify, extract, map_columns (docs/architecture.md §7.1–7.5).

Only the Interpret stage calls these. Everything returned here is a *proposal*: deterministic code
normalizes, validates, matches, and a person approves before anything is written.
"""

from __future__ import annotations

import uuid
from dataclasses import dataclass, field
from datetime import date
from functools import lru_cache
from typing import Any, Literal

from pydantic import BaseModel, Field, ValidationError, create_model

from app.ai import budget, prompts
from app.ai.policy import AIPolicy, redact
from app.ai.providers.base import (
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

# --------------------------------------------------------------------------- providers


def get_provider() -> LLMProvider:
    s = get_settings()
    if s.ai_provider == "anthropic":
        from app.ai.providers.anthropic import AnthropicProvider

        return AnthropicProvider()
    from app.ai.providers.mock import MockProvider

    return MockProvider()


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
    provider: LLMProvider | None = None
    calls: list[dict[str, Any]] = field(default_factory=list)


def _log_call(ctx: AIContext, provider: LLMProvider, prompt: prompts.Prompt, model: str, status: str, *,
              result: LLMResult | None = None, error: str | None = None, categories: list[str],
              request: dict[str, Any] | None = None, schema_version: str | None = None) -> None:
    """AI calls are logged in their own transaction so failed (and billed) attempts are never lost."""
    from app.agent.models import AICall
    from app.db.session import tenant_session

    cost = budget.estimate_cost(result.model if result else model, result.input_tokens if result else None,
                                result.output_tokens if result else None)
    keep = ctx.policy.retain_ai_payloads_days > 0
    row = dict(
        company_id=ctx.company_id, job_id=ctx.job_id, item_id=ctx.item_id, stage=ctx.stage, task=prompt.task,
        provider=provider.name, model=result.model if result else model, is_mock=provider.is_mock, prompt_id=prompt.task,
        prompt_version=prompt.version, schema_version=schema_version, input_tokens=result.input_tokens if result else None,
        output_tokens=result.output_tokens if result else None, cost_usd=cost, latency_ms=result.latency_ms if result else None,
        status=status, error=error, provider_request_id=result.request_id if result else None, data_categories=categories,
        request_redacted=request if keep else None,
        response=result.parsed.model_dump(mode="json") if (keep and result) else None,
    )
    ctx.calls.append({k: row[k] for k in ("task", "provider", "model", "status", "input_tokens", "output_tokens", "cost_usd")})
    with tenant_session(ctx.company_id) as session:
        session.add(AICall(**row))


def _call(ctx: AIContext, prompt: prompts.Prompt, model: str, instructions: str, content: list[ContentBlock],
          output_schema: type[BaseModel], params: dict[str, Any], categories: list[str],
          schema_version: str | None = None) -> BaseModel:
    provider = ctx.provider or get_provider()
    ctx.policy.check(provider.name, provider.external)
    if provider.external:
        budget.acquire(provider.name, str(ctx.company_id))
    task = PromptTask(prompt.task, prompt.version, prompt.system, instructions, model, params)
    request_summary = {"prompt": prompt.ref, "model": model,
                       "content": [b.text[:20000] if isinstance(b, TextBlock) else f"<{b.type}>" for b in content]}
    last_error: Exception | None = None
    for attempt in (1, 2):  # one repair attempt for schema-invalid output
        try:
            result = provider.generate(task=task, content=content, output_schema=output_schema,
                                       timeout_s=get_settings().ai_timeout_s)
            parsed = output_schema.model_validate(result.parsed.model_dump())
            result.parsed = parsed
            _log_call(ctx, provider, prompt, model, "ok", result=result, categories=categories, request=request_summary,
                      schema_version=schema_version)
            return parsed
        except ValidationError as exc:
            last_error = exc
            _log_call(ctx, provider, prompt, model, "invalid_output", error=f"attempt {attempt}: {exc.error_count()} schema errors",
                      categories=categories, request=request_summary, schema_version=schema_version)
        except TransientAIError as exc:
            _log_call(ctx, provider, prompt, model, "error", error=str(exc), categories=categories, request=request_summary)
            raise
        except PermanentAIError as exc:
            status = "refused" if "declined" in str(exc) else "error"
            _log_call(ctx, provider, prompt, model, status, error=str(exc), categories=categories, request=request_summary)
            raise
    raise PermanentAIError(f"AI output failed schema validation twice: {last_error}")


def _prepare_text(ctx: AIContext, text: str) -> tuple[str, dict[str, int]]:
    s = get_settings()
    redacted, counts = redact(text, ctx.policy.redaction_rules)
    if len(redacted) > s.ai_max_input_chars:
        raise PermanentAIError(
            f"The document text is too long for one extraction ({len(redacted):,} characters, limit "
            f"{s.ai_max_input_chars:,}); split it into smaller files"
        )
    return redacted, counts


# --------------------------------------------------------------------------- use cases


@dataclass
class Classification:
    record_types: list[dict[str, Any]]
    contains_instructions: bool
    language: str


def classify(ctx: AIContext, text: str, candidates: list[RecordTypeSpec]) -> Classification:
    prompt = prompts.load("classify")
    redacted, _ = _prepare_text(ctx, text)
    codes = tuple(rt.code for rt in candidates)
    listing = "\n".join(f"- {rt.code}: {rt.label}. {rt.description}" for rt in candidates)
    out = _call(ctx, prompt, get_settings().ai_model_classify, prompt.instructions.substitute(record_types=listing),
                [TextBlock(redacted)], classification_model(codes),
                {"record_types": [{"code": rt.code, "label": rt.label, "description": rt.description} for rt in candidates]},
                ["text"])
    data = out.model_dump()
    return Classification(sorted(data["record_types"], key=lambda r: -r["confidence"]), data["contains_instructions"],
                          data["language"])


def extract(ctx: AIContext, rt: RecordTypeSpec, text: str, extra_blocks: list[ContentBlock] | None = None) -> list[dict[str, Any]]:
    prompt = prompts.load("extract")
    redacted, _ = _prepare_text(ctx, text)
    lines_note = ""
    if rt.line_fields:
        lines_note = f"\n{rt.lines_label} (repeat for every row/item, in the field `lines`):\n{_field_list(rt.line_fields)}\n"
    instructions = prompt.instructions.substitute(label=rt.label, code=rt.code, description=rt.description,
                                                  fields=_field_list(rt.fields), lines_note=lines_note)
    blocks: list[ContentBlock] = [*(extra_blocks or []), TextBlock(redacted)]
    categories = sorted({"text", *(b.type for b in extra_blocks or [])})
    out = _call(ctx, prompt, get_settings().ai_model_extract, instructions, blocks, envelope_for(rt),
                {"record_type": {"code": rt.code, "label": rt.label, "fields": _field_params(rt.fields),
                                 "line_fields": _field_params(rt.line_fields)}},
                categories, schema_version=rt.schema_version)
    return out.model_dump()["records"]


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
    out = _call(ctx, prompt, get_settings().ai_model_classify, prompt.instructions.substitute(schemas=schemas, columns=listing),
                [TextBlock(listing, label="spreadsheet_columns")], mapping_model(tuple(rt.code for rt in candidates), names),
                {"columns": cols, "record_types": [{"code": rt.code, "fields": _field_params(rt.fields),
                                                    "line_fields": _field_params(rt.line_fields)} for rt in candidates]},
                ["table_sample"])
    return out.model_dump()


def provider_info() -> dict[str, Any]:
    p = get_provider()
    s = get_settings()
    return {"provider": p.name, "is_mock": p.is_mock, "external": p.external, "supports_vision": p.supports_vision,
            "model_extract": "mock-rules-v1" if p.is_mock else s.ai_model_extract,
            "model_classify": "mock-rules-v1" if p.is_mock else s.ai_model_classify,
            "prompt_version": prompts.pipeline_prompt_version()}


