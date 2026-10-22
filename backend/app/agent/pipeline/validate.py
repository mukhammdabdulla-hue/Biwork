"""Stage 5 — Validate: required fields, types, structure (§6.1). Business rules that need resolved
references run after matching (module validators)."""

from __future__ import annotations

from decimal import Decimal

from app.agent.models import ExtractedRecord
from app.core.manifest import Issue, RecordTypeSpec

# Units counted in whole pieces: a fractional quantity of them is almost always a misread number.
COUNT_UNITS = frozenset({"pcs", "box", "pack", "set", "pair", "roll", "bag"})


def validate_record(record: ExtractedRecord, rt: RecordTypeSpec, invalid_fields: set[str]) -> list[Issue]:
    issues: list[Issue] = []
    payload = record.payload
    for spec in rt.fields:
        if spec.required and payload.get(spec.name) in (None, "") and spec.name not in invalid_fields:
            issues.append(Issue("required_missing", "error", f"{spec.label} is required but was not found in the source", spec.name))
    if rt.has_lines:
        lines = payload.get("lines") or []
        if not any(any(v not in (None, "") for v in line.values()) for line in lines):
            issues.append(Issue("no_lines", "error", f"At least one item is required in {rt.lines_label}", "lines"))
        for i, line in enumerate(lines):
            if not any(v not in (None, "") for v in line.values()):
                issues.append(Issue("empty_line", "warning", f"Line {i + 1} is empty and will be ignored", f"lines.{i}"))
                continue
            for spec in rt.line_fields:
                path = f"lines.{i}.{spec.name}"
                if spec.required and line.get(spec.name) in (None, "") and path not in invalid_fields:
                    issues.append(Issue("required_missing", "error", f"Line {i + 1}: {spec.label} is required", path))
    return issues


def fractional_counts(record: ExtractedRecord, rt: RecordTypeSpec) -> list[Issue]:
    """Warn about a non-integer quantity of a count unit ("1.5 pcs"). Runs after the unit is final
    (it may come from the product's base unit during matching)."""
    issues: list[Issue] = []
    rows = [("", "", rt.fields, record.payload)]
    if rt.has_lines:
        rows += [(f"lines.{i}.", f"Line {i + 1}: ", rt.line_fields, line) for i, line in enumerate(record.payload.get("lines") or [])]
    for path, where, specs, values in rows:
        for spec in specs:
            qty, unit = values.get(spec.name), values.get(spec.quantity_unit_field) if spec.quantity_unit_field else None
            if spec.type == "decimal" and unit in COUNT_UNITS and qty not in (None, "") and Decimal(str(qty)) % 1:
                issues.append(Issue("fractional_count", "warning", f"{where}{spec.label} {qty} {unit} is not a whole number of "
                                    f"{unit}; check the value", path + spec.name, {"value": str(qty), "unit": unit}))
    return issues
