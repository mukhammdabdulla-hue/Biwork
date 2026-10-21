"""Stage 4 — Normalize: raw values -> canonical values, using company locale settings (§6.1)."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal
from typing import Any

from app.agent import normalization as n
from app.agent.models import ExtractedField, ExtractedRecord
from app.core.manifest import FieldSpec, Issue, RecordTypeSpec
from app.core.text import fmt_qty


@dataclass
class Env:
    """Per-run context shared by the refresh stages (built once per job/batch)."""

    company_id: Any
    settings: dict[str, Any]
    reference_date: date
    today: date
    unit_map: dict[str, dict[str, Any]]
    threshold: float
    session: Any = None
    resolve_cache: dict[tuple[str, str], Any] = field(default_factory=dict)

    @property
    def date_order(self) -> str:
        return self.settings.get("date_order", "DMY")

    @property
    def comma_decimal(self) -> bool:
        return self.settings.get("locale", "uz") in ("uz", "ru")


def spec_for(rt: RecordTypeSpec, path: str) -> FieldSpec | None:
    return rt.field(path.split(".")[-1])


def json_value(value: Any) -> Any:
    if isinstance(value, Decimal):
        return fmt_qty(value)
    if isinstance(value, date):
        return value.isoformat()
    return value


def normalize_value(spec: FieldSpec, raw: Any, env: Env) -> n.Normalized:
    if spec.type == "date":
        return n.parse_date(raw, date_order=env.date_order, reference=env.reference_date)
    if spec.type == "decimal":
        return n.parse_decimal(raw, comma_decimal=env.comma_decimal)
    if spec.type == "integer":
        return n.normalize_integer(raw)
    if spec.type == "unit":
        return n.normalize_unit(raw, env.unit_map)
    if spec.type == "currency":
        return n.normalize_currency(raw)
    if spec.type == "enum":
        return n.normalize_enum(raw, spec.enum)
    if spec.type == "boolean":
        text = str(raw).strip().lower() if raw is not None else ""
        if not text:
            return n.Normalized()
        if text in ("1", "true", "yes", "ha", "да", "y"):
            return n.Normalized(True)
        if text in ("0", "false", "no", "yo'q", "нет", "n"):
            return n.Normalized(False)
        return n.Normalized(error=f"'{raw}' is not yes/no")
    return n.normalize_text(raw, spec.max_length or (300 if spec.type in ("string", "reference") else 5000))


def _derive_units(record: ExtractedRecord, rt: RecordTypeSpec, by_name: dict[str, ExtractedField], env: Env) -> None:
    """'500 dona' in a quantity field also states the unit; use it when the unit field is empty."""
    for f in list(record.fields):
        spec = spec_for(rt, f.field)
        if not spec or not spec.quantity_unit_field or not f.raw_value:
            continue
        unit_text = n.parse_decimal(f.raw_value).extras.get("unit_text")
        prefix = f.field.rsplit(".", 1)[0] + "." if "." in f.field else ""
        unit_field = by_name.get(prefix + spec.quantity_unit_field)
        if unit_text and unit_field is not None and not unit_field.raw_value and unit_field.edited_by is None:
            if n.normalize_unit(unit_text, env.unit_map).value:
                unit_field.raw_value = unit_text
                unit_field.extracted_by = "derived"
                unit_field.confidence = f.confidence
                unit_field.model_value = {"value": unit_text, "derived_from": f.field}


def normalize_record(record: ExtractedRecord, rt: RecordTypeSpec, env: Env) -> list[Issue]:
    issues: list[Issue] = []
    by_name = {f.field: f for f in record.fields}
    _derive_units(record, rt, by_name, env)
    payload: dict[str, Any] = {}
    lines: dict[int, dict[str, Any]] = {}
    for f in record.fields:
        spec = spec_for(rt, f.field)
        if spec is None:
            continue
        result = normalize_value(spec, f.raw_value, env)
        f.normalized_value = json_value(result.value)
        if result.error:
            issues.append(Issue("invalid_value", "error", result.error, f.field))
        for note in result.notes:
            issues.append(Issue("normalization_note", "warning" if result.low_confidence else "info", note, f.field))
        if f.extracted_by == "derived" and f.raw_value and (f.model_value or {}).get("derived_from"):
            issues.append(Issue("value_derived", "info", f"Unit taken from '{f.model_value['derived_from']}'", f.field))
        if f.field.startswith("lines."):
            _, idx, name = f.field.split(".", 2)
            lines.setdefault(int(idx), {})[name] = f.normalized_value
        else:
            payload[f.field] = f.normalized_value
    if rt.has_lines:
        payload["lines"] = [lines[i] for i in sorted(lines)]
    record.payload = payload
    return issues
