"""Building extracted records and fields, and locating source evidence."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

from sqlalchemy.orm import Session

from app.agent.models import ExtractedField, ExtractedRecord, IngestionItem, IngestionJob, SourceRef
from app.core.manifest import FieldSpec, RecordTypeSpec


@dataclass
class FieldInput:
    raw: Any = None
    confidence: float | None = None
    extracted_by: str = "ai"
    model_value: Any = None
    refs: list[dict[str, Any]] = field(default_factory=list)


def _raw_text(value: Any) -> str | None:
    if value is None:
        return None
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    text = str(value).strip()
    return text or None


def _add_field(session: Session, record: ExtractedRecord, item: IngestionItem, name: str, position: int,
               spec: FieldSpec, value: FieldInput) -> ExtractedField:
    raw = _raw_text(value.raw)
    f = ExtractedField(
        company_id=record.company_id, record=record, position=position, field=name, raw_value=raw,
        model_value=value.model_value, confidence=Decimal(str(round(value.confidence, 3))) if value.confidence is not None and raw else None,
        state="missing" if raw is None else "low_confidence", extracted_by=value.extracted_by,
    )
    for ref in value.refs:
        f.source_refs.append(SourceRef(company_id=record.company_id, item_id=item.id, file_id=item.file_id, **ref))
    session.add(f)
    return f


def create_record(session: Session, job: IngestionJob, item: IngestionItem, rt: RecordTypeSpec, seq: int,
                  fields: dict[str, FieldInput], lines: list[dict[str, FieldInput]] | None = None,
                  row_ref: dict[str, Any] | None = None) -> ExtractedRecord:
    record = ExtractedRecord(
        company_id=job.company_id, job_id=job.id, item_id=item.id, seq=seq, record_type=rt.code, target_module=rt.module,
        status="draft", row_ref=row_ref or {}, payload={}, references={},
    )
    session.add(record)
    position = 0
    for spec in rt.fields:
        _add_field(session, record, item, spec.name, position, spec, fields.get(spec.name, FieldInput(extracted_by="none")))
        position += 1
    for i, line in enumerate(lines or []):
        for spec in rt.line_fields:
            _add_field(session, record, item, f"lines.{i}.{spec.name}", position, spec,
                       line.get(spec.name, FieldInput(extracted_by="none")))
            position += 1
    return record


def column_letter(index: int) -> str:
    letters = ""
    index += 1
    while index:
        index, rem = divmod(index - 1, 26)
        letters = chr(65 + rem) + letters
    return letters


# --------------------------------------------------------------------------- source evidence


def locate_quote(item: IngestionItem, segments_by_page: dict[int, dict[str, Any]], quote: str | None) -> dict[str, Any] | None:
    """Find an exact excerpt in the item's text; resolve page and bounding box when available."""
    if not quote:
        return None
    text = item.meta.get("full_text") or item.raw_text or ""
    start = text.find(quote)
    if start < 0:
        start = text.casefold().find(quote.casefold())
    if start < 0:
        pattern = r"\s+".join(re.escape(tok) for tok in quote.split())
        m = re.search(pattern, text, re.IGNORECASE) if pattern else None
        if not m:
            return {"excerpt": quote[:500]}
        start, end = m.start(), m.end()
    else:
        end = start + len(quote)
    ref: dict[str, Any] = {"text_start": start, "text_end": end, "excerpt": text[max(0, start - 60): end + 60][:600]}
    for page, s, e in item.meta.get("page_offsets") or []:
        if s <= start <= e:
            ref["page"] = page
            seg = segments_by_page.get(page) or {}
            for line in seg.get("lines") or []:
                if quote.casefold() in line["text"].casefold() or line["text"].casefold() in quote.casefold():
                    ref["bbox"] = line["bbox"]
                    break
            break
    return ref


def ocr_confidence_for(ref: dict[str, Any] | None, segments_by_page: dict[int, dict[str, Any]], default: float | None) -> float | None:
    if not ref or "bbox" not in ref:
        return default
    seg = segments_by_page.get(ref.get("page") or 1) or {}
    for line in seg.get("lines") or []:
        if line.get("bbox") == ref["bbox"] and line.get("conf") is not None:
            return float(line["conf"])
    return default
