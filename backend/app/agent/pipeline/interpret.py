"""Stage 3 — Interpret (docs/architecture.md §6.1, §6.4).

- Spreadsheets / pasted tables: map columns ONCE per table (saved template -> header rules -> AI for
  unresolved columns only), then turn rows into records deterministically — no per-row AI calls.
- Documents / free text: classify, then schema-constrained extraction per record type.
- Structured payloads (forms, API): validated directly, no AI.

The only stage that calls a language model; its output is treated as untrusted proposals.
"""

from __future__ import annotations

import base64
import hashlib
from datetime import UTC, date, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import delete, func, select
from sqlalchemy.orm import Session

from app.agent.models import ColumnMapping, ExtractedRecord, IngestionItem, IngestionJob, MappingTemplate, ParsedSegment
from app.agent.normalization import parse_date
from app.agent.pipeline.context import StageError
from app.agent.pipeline.ingest import enabled_modules
from app.agent.pipeline.records import FieldInput, column_letter, create_record, locate_quote, ocr_confidence_for
from app.ai import extraction
from app.ai.policy import AIPolicy, PolicyBlocked
from app.ai.providers.base import ImageBlock, PdfBlock
from app.core.locale_data import DATE_SPAN
from app.core.manifest import FieldSpec, RecordTypeSpec
from app.core.models import Company, StoredFile
from app.core.services.files import read_bytes
from app.core.text import normalize_key
from app.modules.registry import record_type, record_types_for_modules

RULE_EXACT, RULE_PARTIAL, TEMPLATE, USER = 0.95, 0.8, 0.97, 1.0
MIN_CLASSIFY_CONFIDENCE = 0.5


# --------------------------------------------------------------------------- column mapping


def header_signature(headers: list[str]) -> str:
    return hashlib.sha256("|".join(normalize_key(h) for h in headers).encode()).hexdigest()


def _rule_match(header: str, fields: tuple[FieldSpec, ...]) -> tuple[str, float] | None:
    key = normalize_key(header)
    if not key:
        return None
    for f in fields:
        names = {normalize_key(n) for n in (f.name, f.label, *f.aliases)}
        if key in names:
            return f.name, RULE_EXACT
    best: tuple[str, float] | None = None
    for f in fields:
        for alias in (f.label, *f.aliases):
            ak = normalize_key(alias)
            if len(ak) >= 4 and (key.startswith(ak) or ak in key.split() or (len(key) >= 4 and ak.startswith(key))):
                if best is None:
                    best = (f.name, RULE_PARTIAL)
    return best


def rule_mapping(headers: list[str], rt: RecordTypeSpec) -> dict[int, tuple[str | None, float, str]]:
    used: set[str] = set()
    out: dict[int, tuple[str | None, float, str]] = {}
    fields = (*rt.fields, *rt.line_fields)
    for i, h in enumerate(headers):
        hit = _rule_match(h, fields)
        if hit and hit[0] not in used:
            used.add(hit[0])
            out[i] = (hit[0], hit[1], "rule")
        else:
            out[i] = (None, 0.0, "rule")
    return out


def _score(mapping: dict[int, tuple[str | None, float, str]], rt: RecordTypeSpec) -> float:
    mapped = {m[0] for m in mapping.values() if m[0]}
    required = [f.name for f in rt.fields if f.required] if not (mapped & {f.name for f in rt.line_fields}) else \
        [f.name for f in rt.line_fields if f.required]
    return len(mapped) + 2 * sum(1 for r in required if r in mapped) - 0.5 * sum(1 for r in required if r not in mapped)


def resolve_mapping(session: Session, ai_ctx: extraction.AIContext | None, company_id, headers: list[str],
                    rows: list[list[Any]], candidates: list[RecordTypeSpec], warnings: list[str],
                    ) -> tuple[RecordTypeSpec, dict[int, tuple[str | None, float, str]]]:
    signature = header_signature(headers)
    codes = [rt.code for rt in candidates]
    template = session.execute(select(MappingTemplate).where(
        MappingTemplate.company_id == company_id, MappingTemplate.header_signature == signature,
        MappingTemplate.record_type.in_(codes),
    ).order_by(MappingTemplate.updated_at.desc())).scalars().first()
    if template is not None:
        rt = record_type(template.record_type)
        return rt, {i: (template.mapping.get(normalize_key(h)), TEMPLATE, "template") for i, h in enumerate(headers)}

    scored = sorted(((_score(rule_mapping(headers, rt), rt), rt) for rt in candidates), key=lambda x: -x[0])
    best_score, rt = scored[0]
    mapping = rule_mapping(headers, rt)
    mapped_fields = {m[0] for m in mapping.values() if m[0]}
    required = {f.name for f in rt.fields if f.required}
    unresolved = [i for i, m in mapping.items() if m[0] is None and not headers[i].startswith("Column ")]
    ambiguous = len(scored) > 1 and scored[1][0] >= best_score - 0.5
    if ai_ctx is not None and (unresolved and (required - mapped_fields) or ambiguous or best_score <= 0):
        columns = [{"index": i, "header": h, "samples": [r[i] for r in rows[:5] if i < len(r) and r[i] not in (None, "")]}
                   for i, h in enumerate(headers)]
        try:
            proposal = extraction.map_columns(ai_ctx, columns, candidates)
        except PolicyBlocked as exc:
            warnings.append(f"AI column mapping skipped: {exc}")
        else:
            proposed_rt = record_type(proposal["record_type"])
            if proposed_rt.code != rt.code and (ambiguous or best_score <= 0):
                rt = proposed_rt
                mapping = rule_mapping(headers, rt)
            used = {m[0] for m in mapping.values() if m[0]}
            valid = {f.name for f in (*rt.fields, *rt.line_fields)}
            for col in proposal["columns"]:
                i, name = col["column_index"], col["field"]
                if i in mapping and mapping[i][0] is None and name in valid and name not in used:
                    mapping[i] = (name, float(col["confidence"]), "ai")
                    used.add(name)
    return rt, mapping


def _preamble_date(preamble: list[str], reference: date) -> tuple[str, str] | None:
    found = []
    for line in preamble:
        for m in DATE_SPAN.finditer(line):
            parsed = parse_date(m.group(0), reference=reference)
            if parsed.value:
                found.append((m.group(0), line))
    return found[0] if len({f[0] for f in found}) == 1 else None


def interpret_table(session: Session, job: IngestionJob, item: IngestionItem, table: ParsedSegment,
                    rows: list[ParsedSegment], rt: RecordTypeSpec, mapping: dict[int, tuple[str | None, float, str]],
                    seq_start: int, reference: date) -> int:
    headers = table.content["headers"]
    line_names = {f.name for f in rt.line_fields}
    mapped = {i: m for i, m in mapping.items() if m[0]}
    as_lines = rt.has_lines and all(m[0] in line_names for m in mapped.values())
    sheet = table.sheet
    preamble_date = None
    date_fields = [f for f in rt.fields if f.type == "date" and f.required]
    if date_fields and date_fields[0].name not in {m[0] for m in mapped.values()}:
        preamble_date = _preamble_date(table.content.get("preamble") or [], reference)

    def cell_input(row: ParsedSegment, i: int, conf: float, by: str) -> FieldInput:
        cells = row.content["cells"]
        value = cells[i] if i < len(cells) else None
        ref = {"sheet": sheet, "cell": f"{column_letter(i)}{row.content['row_number']}", "excerpt": f"{headers[i]}: {value}"}
        return FieldInput(raw=value, confidence=conf, extracted_by="mapping",
                          model_value={"value": value, "column": headers[i], "mapped_by": by}, refs=[ref])

    if as_lines:
        lines = [{name: cell_input(row, i, conf, by) for i, (name, conf, by) in mapped.items()} for row in rows]
        create_record(session, job, item, rt, seq_start, {}, lines,
                      {"sheet": sheet, "table": table.content["name"], "table_seq": table.seq,
                       "rows": [r.content["row_number"] for r in rows]})
        return 1
    count = 0
    for row in rows:
        fields = {name: cell_input(row, i, conf, by) for i, (name, conf, by) in mapped.items() if name not in line_names}
        if preamble_date and date_fields[0].name not in fields:
            fields[date_fields[0].name] = FieldInput(raw=preamble_date[0], confidence=0.6, extracted_by="derived",
                                                     model_value={"value": preamble_date[0], "from": "sheet title"},
                                                     refs=[{"sheet": sheet, "excerpt": preamble_date[1][:500]}])
        create_record(session, job, item, rt, seq_start + count, fields, None,
                      {"sheet": sheet, "row": row.content["row_number"], "table": table.content["name"], "table_seq": table.seq})
        count += 1
    return count


def save_mapping(session: Session, item: IngestionItem, table_seq: int, rt: RecordTypeSpec, headers: list[str],
                 mapping: dict[int, tuple[str | None, float, str]], confirmed_by=None) -> None:
    session.execute(delete(ColumnMapping).where(ColumnMapping.item_id == item.id, ColumnMapping.table_seq == table_seq))
    for i, h in enumerate(headers):
        name, conf, by = mapping.get(i, (None, 0.0, "rule"))
        session.add(ColumnMapping(
            company_id=item.company_id, item_id=item.id, table_seq=table_seq, record_type=rt.code, source_index=i,
            source_column=h[:200], target_field=name, confidence=Decimal(str(round(conf, 3))), mapped_by=by,
            confirmed_by=confirmed_by, confirmed_at=datetime.now(UTC) if confirmed_by else None,
        ))


def existing_mapping(session: Session, item_id, table_seq: int):
    """A mapping a person confirmed: (record_type, mapping, confirmed_by) or None."""
    rows = session.execute(select(ColumnMapping).where(ColumnMapping.item_id == item_id, ColumnMapping.table_seq == table_seq,
                                                       ColumnMapping.mapped_by == "user")).scalars().all()
    if not rows:
        return None
    return rows[0].record_type, {r.source_index: (r.target_field, float(r.confidence), "user") for r in rows}, rows[0].confirmed_by


# --------------------------------------------------------------------------- documents


def _envelope_to_inputs(env: dict[str, Any], fields: tuple[FieldSpec, ...], item: IngestionItem,
                        pages: dict[int, dict[str, Any]], extracted_by: str) -> dict[str, FieldInput]:
    out: dict[str, FieldInput] = {}
    default_ocr = float(item.ocr_confidence) if item.ocr_confidence is not None else None
    for spec in fields:
        fv = env.get(spec.name) or {}
        if fv.get("not_found") or fv.get("value") in (None, ""):
            out[spec.name] = FieldInput(extracted_by=extracted_by, model_value={"not_found": True})
            continue
        ref = locate_quote(item, pages, fv.get("source_quote"))
        confidence = float(fv.get("confidence") or 0)
        if item.ocr_used:
            ocr = ocr_confidence_for(ref, pages, default_ocr)
            if ocr is not None:  # never trust model confidence alone on OCR text
                confidence = min(confidence, ocr / 100)
        out[spec.name] = FieldInput(
            raw=fv["value"], confidence=confidence, extracted_by=extracted_by,
            model_value={"value": fv["value"], "confidence": fv.get("confidence"), "source_quote": fv.get("source_quote"),
                         "alternatives": fv.get("alternatives") or []},
            refs=[ref] if ref else [],
        )
    return out


def _vision_blocks(session: Session, item: IngestionItem, policy: AIPolicy, provider) -> list:
    if not (policy.send_images_to_ai and provider.supports_vision and item.file_id):
        return []
    if item.detected_format == "image":
        stored = session.get(StoredFile, item.file_id)
        return [ImageBlock(stored.mime_type, base64.standard_b64encode(read_bytes(stored)).decode())]
    if item.detected_format == "pdf" and item.ocr_used:
        stored = session.get(StoredFile, item.file_id)
        return [PdfBlock(base64.standard_b64encode(read_bytes(stored)).decode())]
    return []


def interpret_document(session: Session, job: IngestionJob, item: IngestionItem, candidates: list[RecordTypeSpec],
                       ai_ctx: extraction.AIContext, seq_start: int, pages: dict[int, dict[str, Any]],
                       warnings: list[str]) -> tuple[int, list[str]]:
    text = item.meta.get("full_text") or item.raw_text or ""
    provider = ai_ctx.provider or extraction.get_provider()
    ai_ctx.provider = provider
    blocks = _vision_blocks(session, item, ai_ctx.policy, provider)
    if not text.strip() and not blocks:
        raise StageError("No readable text was found in this input" + (" (OCR found nothing)" if item.ocr_used else ""))
    if len(candidates) == 1:
        types = [candidates[0]]
    else:
        result = extraction.classify(ai_ctx, text, candidates)
        if result.contains_instructions:
            warnings.append("The AI classifier flagged instruction-like text in this content; it was treated as data only")
        types = [record_type(r["record_type"]) for r in result.record_types if r["confidence"] >= MIN_CLASSIFY_CONFIDENCE][:3]
        if not types:
            raise StageError("Could not identify a supported record type in this content. Retry with a record type selected.")
    by = "mock" if provider.is_mock else "ai"
    count = 0
    for rt in types:
        for env in extraction.extract(ai_ctx, rt, text, blocks):
            fields = _envelope_to_inputs(env, rt.fields, item, pages, by)
            lines = [_envelope_to_inputs(line, rt.line_fields, item, pages, by) for line in env.get("lines") or []]
            if not any(f.raw not in (None, "") for f in fields.values()) and not lines:
                continue
            create_record(session, job, item, rt, seq_start + count, fields, lines,
                          {"page": next((r.get("page") for f in fields.values() for r in f.refs if r.get("page")), None)})
            count += 1
    if count == 0:
        raise StageError(f"No {', '.join(rt.label for rt in types)} data could be extracted from this content")
    return count, [rt.code for rt in types]


def interpret_form(session: Session, job: IngestionJob, item: IngestionItem, seq_start: int) -> tuple[int, str]:
    payload = item.raw_payload or {}
    rt = record_type(payload.get("record_type") or job.target_record_type)
    entries = payload.get("records") or [payload.get("fields") or {}]
    count = 0
    known = {f.name for f in rt.fields}
    for entry in entries:
        unknown = set(entry) - known - {"lines"}
        if unknown:
            raise StageError(f"Unknown field(s) for {rt.code}: {', '.join(sorted(unknown))}")
        fields = {k: FieldInput(raw=v, confidence=1.0, extracted_by="form", model_value={"value": v}) for k, v in entry.items()
                  if k != "lines" and v not in (None, "")}
        lines = [{k: FieldInput(raw=v, confidence=1.0, extracted_by="form") for k, v in line.items() if v not in (None, "")}
                 for line in entry.get("lines") or []]
        create_record(session, job, item, rt, seq_start + count, fields, lines, {"form": True})
        count += 1
    return count, rt.code


# --------------------------------------------------------------------------- stage entry point


def _next_seq(session: Session, job_id) -> int:
    return (session.execute(select(func.max(ExtractedRecord.seq)).where(ExtractedRecord.job_id == job_id)).scalar() or 0) + 1


def interpret_item(session: Session, item: IngestionItem, provider=None) -> dict[str, Any]:
    job = session.get(IngestionJob, item.job_id)
    company = session.get(Company, item.company_id)
    session.execute(delete(ExtractedRecord).where(ExtractedRecord.item_id == item.id))  # idempotent re-run
    session.flush()
    policy = AIPolicy.from_settings(company.settings)
    candidates = record_types_for_modules(enabled_modules(session, item.company_id))
    if job.target_record_type:
        candidates = [record_type(job.target_record_type)]
    ai_ctx = extraction.AIContext(company_id=item.company_id, job_id=job.id, item_id=item.id, policy=policy,
                                  reference_date=job.created_at.date(), provider=provider)
    warnings = list(item.warnings or [])
    seq = _next_seq(session, job.id)
    segments = session.execute(select(ParsedSegment).where(ParsedSegment.item_id == item.id).order_by(ParsedSegment.seq)).scalars().all()
    detected: list[str] = []
    created = 0

    if item.detected_format == "form":
        created, code = interpret_form(session, job, item, seq)
        detected = [code]
    elif item.meta.get("is_tabular"):
        tables = [s for s in segments if s.kind == "table"]
        if not tables:
            raise StageError("No table with column headers was found in this file")
        for table in tables:
            rows = [s for s in segments if s.kind == "row" and s.table_seq == table.seq]
            if not rows:
                warnings.append(f"{table.content['name']}: the table has no data rows")
                continue
            confirmed = existing_mapping(session, item.id, table.seq)
            if confirmed:
                rt, mapping = record_type(confirmed[0]), confirmed[1]
            else:
                rt, mapping = resolve_mapping(session, ai_ctx, item.company_id, table.content["headers"],
                                              [r.content["cells"] for r in rows], candidates, warnings)
            save_mapping(session, item, table.seq, rt, table.content["headers"], mapping,
                         confirmed_by=confirmed[2] if confirmed else None)
            unmapped_required = [f.label for f in rt.fields if f.required and f.name not in {m[0] for m in mapping.values()}]
            if unmapped_required and not (rt.has_lines and any(m[0] in {f.name for f in rt.line_fields} for m in mapping.values())):
                warnings.append(f"{table.content['name']}: no column was mapped to required field(s) "
                                f"{', '.join(unmapped_required)} — check the column mapping")
            created += interpret_table(session, job, item, table, rows, rt, mapping, seq + created, job.created_at.date())
            detected.append(rt.code)
    else:
        pages = {s.page or 1: s.content for s in segments if s.kind == "text"}
        created, detected = interpret_document(session, job, item, candidates, ai_ctx, seq, pages, warnings)

    item.warnings = warnings
    item.detected_record_type = ",".join(dict.fromkeys(detected)) or None
    item.status = "interpreted"
    return {"records": created, "record_types": detected, "ai_calls": ai_ctx.calls}

