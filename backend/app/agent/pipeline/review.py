"""Stage 7 — Review (docs/architecture.md §6.1): re-evaluate records, and the human actions on them —
correct, choose references, approve, reject, reopen, bulk approve, re-map spreadsheet columns.

`refresh_record` runs Normalize -> Validate -> Match for one record. It is used after extraction,
after every correction, and again right before commit (so approvals are re-checked against the
current database state).
"""

from __future__ import annotations

import uuid
import zoneinfo
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from app.agent.models import (
    Approval,
    ColumnMapping,
    ExtractedField,
    ExtractedRecord,
    ExtractionFeedback,
    IngestionItem,
    IngestionJob,
    MappingTemplate,
    MatchCandidate,
    ParsedSegment,
    ValidationResult,
)
from app.agent.pipeline import match as matching
from app.agent.pipeline.interpret import header_signature, interpret_table, save_mapping
from app.agent.pipeline.normalize import Env, normalize_record, normalize_value, spec_for
from app.agent.pipeline.validate import validate_record
from app.config import get_settings
from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict, NotFound, PreconditionFailed, ValidationFailed
from app.core.manifest import Issue, RecordTypeSpec
from app.core.matching import get_resolver
from app.core.models import Company
from app.core.services.master_data import unit_map
from app.core.services.workflows import get_workflow
from app.core.text import normalize_key
from app.modules.registry import record_type

EDITABLE = ("draft", "needs_review", "failed")


def build_env(session: Session, company_id: uuid.UUID, job: IngestionJob, rt: RecordTypeSpec | None = None) -> Env:
    company = session.get(Company, company_id)
    settings = dict(company.settings or {})
    tz = zoneinfo.ZoneInfo(settings.get("timezone") or "Asia/Tashkent")
    threshold = get_settings().agent_confidence_threshold
    if rt is not None:
        wf = get_workflow(session, company_id, rt.code)
        if wf is not None and wf.confidence_threshold is not None:
            threshold = float(wf.confidence_threshold)
    return Env(company_id=company_id, settings=settings, reference_date=job.created_at.astimezone(tz).date(),
               today=datetime.now(tz).date(), unit_map=unit_map(session), threshold=threshold, session=session)


def clear_results(session: Session, record: ExtractedRecord) -> None:
    session.execute(delete(ValidationResult).where(ValidationResult.record_id == record.id))
    session.execute(delete(MatchCandidate).where(MatchCandidate.record_id == record.id))
    session.expire(record, ["validation_results", "match_candidates"])


def store_issues(record: ExtractedRecord, stage: str, issues: list[Issue]) -> None:
    for issue in issues:
        record.validation_results.append(ValidationResult(
            company_id=record.company_id, field=issue.field, rule_code=issue.rule_code, severity=issue.severity,
            message=issue.message[:2000], details=issue.details, stage=stage,
        ))


def step_normalize(record: ExtractedRecord, rt: RecordTypeSpec, env: Env) -> list[Issue]:
    return normalize_record(record, rt, env)


def step_validate(record: ExtractedRecord, rt: RecordTypeSpec, prior: list[Issue]) -> list[Issue]:
    invalid = {i.field for i in prior if i.severity == "error" and i.field}
    return validate_record(record, rt, invalid) + matching.conflict_issues(record)


def step_match(record: ExtractedRecord, rt: RecordTypeSpec, env: Env) -> list[Issue]:
    issues = matching.resolve_references(record, rt, env)
    issues += matching.default_units(record, rt, env)
    issues += matching.run_rules(record, rt, env)  # business rules need resolved references
    issues += matching.detect_duplicates(record, rt, env)
    return issues


def finalize(record: ExtractedRecord, rt: RecordTypeSpec, env: Env, issues: list[Issue], set_status: bool = True) -> None:
    matching.assign_states(record, rt, issues, env)
    record.review_group = matching.review_group(record, issues)
    summary = rt.summary(record.payload) if rt.summary else None
    record.summary = summary[:300] if summary else None
    if set_status and record.status in EDITABLE:
        record.status = "needs_review"
        record.error = None


def issues_of(record: ExtractedRecord) -> list[Issue]:
    return [Issue(v.rule_code, v.severity, v.message, v.field, v.details) for v in record.validation_results]


def refresh_record(session: Session, record: ExtractedRecord, env: Env, *, set_status: bool = True) -> list[Issue]:
    """Normalize -> Validate -> Match -> states, for one record."""
    rt = record_type(record.record_type)
    clear_results(session, record)
    normalized = step_normalize(record, rt, env)
    store_issues(record, "normalize", normalized)
    validated = step_validate(record, rt, normalized)
    store_issues(record, "validate", validated)
    matched = step_match(record, rt, env)
    store_issues(record, "match", matched)
    issues = normalized + validated + matched
    finalize(record, rt, env, issues, set_status)
    return issues


def prepare_job(session: Session, job: IngestionJob) -> dict[str, Any]:
    """Normalize, validate and match every undecided record of a job (stages 4–6)."""
    records = session.execute(select(ExtractedRecord).where(
        ExtractedRecord.job_id == job.id, ExtractedRecord.status.in_(EDITABLE),
    ).order_by(ExtractedRecord.seq)).scalars().all()
    envs: dict[str, Env] = {}
    groups: dict[str, int] = {}
    for record in records:
        env = envs.get(record.record_type) or build_env(session, job.company_id, job, record_type(record.record_type))
        envs[record.record_type] = env
        refresh_record(session, record, env)
        groups[record.review_group] = groups.get(record.review_group, 0) + 1
        session.flush()
    return {"records": len(records), "groups": groups}


# --------------------------------------------------------------------------- loading helpers


def get_record(session: Session, ctx: RequestContext, record_id: uuid.UUID) -> ExtractedRecord:
    record = session.execute(select(ExtractedRecord).where(
        ExtractedRecord.id == record_id, ExtractedRecord.company_id == ctx.company_id,
    )).scalar_one_or_none()
    if record is None:
        raise NotFound("Record not found")
    return record


def get_job(session: Session, ctx: RequestContext, job_id: uuid.UUID) -> IngestionJob:
    job = session.execute(select(IngestionJob).where(IngestionJob.id == job_id, IngestionJob.company_id == ctx.company_id)).scalar_one_or_none()
    if job is None:
        raise NotFound("Job not found")
    return job


def _snapshot(record: ExtractedRecord) -> dict[str, Any]:
    return {"payload": record.payload, "references": {k: v.get("id") for k, v in (record.references or {}).items()}}


# --------------------------------------------------------------------------- corrections


def correct_record(session: Session, ctx: RequestContext, record_id: uuid.UUID, *, fields: dict[str, Any] | None = None,
                   references: dict[str, str | None] | None = None, add_lines: int = 0, remove_lines: list[int] | None = None,
                   expected_revision: int | None = None) -> ExtractedRecord:
    ctx.require("agent.review")
    record = get_record(session, ctx, record_id)
    rt = record_type(record.record_type)
    if record.status not in EDITABLE:
        raise ValidationFailed(f"A {record.status} record cannot be edited" + (" (reopen it first)" if record.status in ("approved", "rejected") else ""),
                               code="record_not_editable")
    if expected_revision is not None and record.payload_version != expected_revision:
        raise PreconditionFailed("The record was changed by someone else; reload it")
    job = session.get(IngestionJob, record.job_id)
    before = _snapshot(record)
    now = datetime.now(UTC)
    by_name = {f.field: f for f in record.fields}
    changes: dict[str, Any] = {}

    if remove_lines and rt.has_lines:
        _remove_lines(session, record, rt, sorted(set(remove_lines)))
        by_name = {f.field: f for f in record.fields}
        changes["removed_lines"] = sorted(set(remove_lines))
    if add_lines and rt.has_lines:
        count = len({f.field.split(".")[1] for f in record.fields if f.field.startswith("lines.")})
        position = max((f.position for f in record.fields), default=0) + 1
        for i in range(count, count + min(add_lines, 50)):
            for spec in rt.line_fields:
                f = ExtractedField(company_id=record.company_id, record=record, position=position, field=f"lines.{i}.{spec.name}",
                                   state="missing", extracted_by="user")
                session.add(f)
                by_name[f.field] = f
                position += 1
        changes["added_lines"] = add_lines

    for name, value in (fields or {}).items():
        f = by_name.get(name)
        if f is None or spec_for(rt, name) is None:
            raise ValidationFailed(f"Unknown field '{name}'", extra={"field": name})
        new_raw = None if value in (None, "") else str(value).strip()
        if new_raw == f.raw_value and f.edited_by is not None:
            continue
        session.add(ExtractionFeedback(
            company_id=record.company_id, record_id=record.id, record_type=rt.code, field=name,
            model_value=(f.model_value or {}).get("value", f.raw_value), corrected_value=new_raw, extracted_by=f.extracted_by,
            prompt_version=job.prompt_version, corrected_by=ctx.user_id,
        ))
        changes[name] = {"from": f.raw_value, "to": new_raw}
        f.raw_value, f.extracted_by, f.edited_by, f.edited_at = new_raw, "user", ctx.user_id, now
        f.confidence = Decimal("1") if new_raw else None
        if (f.model_value or {}).get("alternatives"):
            f.model_value = {**f.model_value, "resolved_by_user": True}

    refs = dict(record.references or {})
    for name, entity_id in (references or {}).items():
        spec = spec_for(rt, name)
        if spec is None or spec.type != "reference":
            raise ValidationFailed(f"'{name}' is not a reference field", extra={"field": name})
        f = by_name.get(name)
        if entity_id is None:
            refs.pop(name, None)
            changes[f"{name}#reference"] = {"to": None}
            continue
        cand = get_resolver(spec.reference).get(session, record.company_id, uuid.UUID(str(entity_id)))
        if cand is None:
            raise ValidationFailed(f"Selected {spec.label.lower()} does not exist", extra={"field": name})
        if f is not None and not f.raw_value:
            f.raw_value = cand.label
        if f is not None:
            f.edited_by, f.edited_at, f.extracted_by, f.confidence = ctx.user_id, now, "user", Decimal("1")
        text = (f.raw_value if f is not None else cand.label)
        refs[name] = {**cand.as_reference(), "match_kind": "manual", "manual": True, "text": " ".join(text.split())}
        changes[f"{name}#reference"] = {"to": cand.label}
    record.references = refs
    if not changes:
        return record
    session.flush()
    session.expire(record, ["fields"])
    env = build_env(session, record.company_id, job, rt)
    # Manual references are keyed by the normalized text, so normalize first.
    for name, ref in refs.items():
        if ref.get("manual"):
            f = next((x for x in record.fields if x.field == name), None)
            if f is not None:
                ref["text"] = str(normalize_value_text(rt, name, f.raw_value, env))
    record.references = dict(refs)
    refresh_record(session, record, env)
    record.payload_version += 1
    session.add(Approval(company_id=record.company_id, record_id=record.id, decided_by=ctx.user_id, decision="correct",
                         diff=audit.canonical(changes), payload_version=record.payload_version))
    audit.record(session, "agent.record.corrected", ctx=ctx, entity_type="extracted_record", entity_id=record.id,
                 changes=audit.diff(before, _snapshot(record)) | {"edits": audit.canonical(changes)},
                 context={"ingestion_job_id": str(record.job_id)})
    _update_job_after_review(session, job)
    return record


def normalize_value_text(rt: RecordTypeSpec, name: str, raw: Any, env: Env) -> Any:
    spec = spec_for(rt, name)
    return normalize_value(spec, raw, env).value if spec else raw


def _remove_lines(session: Session, record: ExtractedRecord, rt: RecordTypeSpec, indexes: list[int]) -> None:
    lines: dict[int, list[ExtractedField]] = {}
    for f in record.fields:
        if f.field.startswith("lines."):
            lines.setdefault(int(f.field.split(".")[1]), []).append(f)
    keep = [i for i in sorted(lines) if i not in indexes]
    for i in indexes:
        for f in lines.get(i, []):
            record.fields.remove(f)
            session.delete(f)
    session.flush()
    # Renumber remaining lines so indexes stay contiguous.
    for new_index, old_index in enumerate(keep):
        if new_index != old_index:
            for f in lines[old_index]:
                f.field = f"lines.{new_index}.{f.field.split('.', 2)[2]}"
    refs = {}
    for k, v in (record.references or {}).items():
        if k.startswith("lines."):
            old = int(k.split(".")[1])
            if old in indexes:
                continue
            k = f"lines.{keep.index(old)}.{k.split('.', 2)[2]}"
        refs[k] = v
    record.references = refs
    session.flush()


# --------------------------------------------------------------------------- decisions


def _require_approver(ctx: RequestContext, rt: RecordTypeSpec) -> None:
    ctx.require("agent.review", rt.approve_permission, rt.write_permission)


def approve_record(session: Session, ctx: RequestContext, record_id: uuid.UUID, comment: str | None = None,
                   expected_revision: int | None = None) -> ExtractedRecord:
    record = get_record(session, ctx, record_id)
    rt = record_type(record.record_type)
    _require_approver(ctx, rt)
    if record.status != "needs_review":
        raise ValidationFailed(f"Only records awaiting review can be approved (status: {record.status})", code="invalid_transition")
    if expected_revision is not None and record.payload_version != expected_revision:
        raise PreconditionFailed("The record was changed by someone else; reload it")
    job = session.get(IngestionJob, record.job_id)
    issues = refresh_record(session, record, build_env(session, record.company_id, job, rt))
    errors = [i for i in issues if i.severity == "error"]
    if errors:
        raise ValidationFailed("Fix the errors before approving: " + "; ".join(e.message for e in errors[:3]),
                               code="record_has_errors", extra={"errors": [e.message for e in errors]})
    _decide(session, ctx, record, "approve", comment)
    _update_job_after_review(session, job)
    return record


def _decide(session: Session, ctx: RequestContext | None, record: ExtractedRecord, decision: str, comment: str | None,
            auto: bool = False) -> None:
    now = datetime.now(UTC)
    record.status = {"approve": "approved", "reject": "rejected"}[decision]
    record.reviewed_by = ctx.user_id if ctx else None
    record.reviewed_at = now
    session.add(Approval(company_id=record.company_id, record_id=record.id, decided_by=ctx.user_id if ctx else None,
                         decision=decision, auto=auto, comment=comment, payload_version=record.payload_version,
                         diff={"payload": audit.canonical(record.payload)}))
    audit.record(session, f"agent.record.{'approved' if decision == 'approve' else 'rejected'}", ctx=ctx,
                 company_id=record.company_id, actor_type="agent" if auto else None, entity_type="extracted_record",
                 entity_id=record.id, summary=record.summary, context={"ingestion_job_id": str(record.job_id), "auto": auto,
                                                                        "comment": comment})


def reject_record(session: Session, ctx: RequestContext, record_id: uuid.UUID, reason: str | None) -> ExtractedRecord:
    ctx.require("agent.review")
    record = get_record(session, ctx, record_id)
    if record.status not in ("draft", "needs_review", "approved", "failed"):
        raise ValidationFailed(f"A {record.status} record cannot be rejected", code="invalid_transition")
    _decide(session, ctx, record, "reject", reason)
    _update_job_after_review(session, session.get(IngestionJob, record.job_id))
    return record


def reopen_record(session: Session, ctx: RequestContext, record_id: uuid.UUID) -> ExtractedRecord:
    ctx.require("agent.review")
    record = get_record(session, ctx, record_id)
    if record.status not in ("approved", "rejected"):
        raise ValidationFailed("Only approved (not yet saved) or rejected records can be reopened", code="invalid_transition")
    job = session.get(IngestionJob, record.job_id)
    record.status = "needs_review"
    refresh_record(session, record, build_env(session, record.company_id, job, record_type(record.record_type)))
    audit.record(session, "agent.record.reopened", ctx=ctx, entity_type="extracted_record", entity_id=record.id)
    _update_job_after_review(session, job)
    return record


def bulk_approve(session: Session, ctx: RequestContext, job_id: uuid.UUID, record_ids: list[uuid.UUID] | None,
                 group: str | None = "ready") -> dict[str, Any]:
    job = get_job(session, ctx, job_id)
    stmt = select(ExtractedRecord).where(ExtractedRecord.job_id == job.id, ExtractedRecord.status == "needs_review")
    if record_ids:
        stmt = stmt.where(ExtractedRecord.id.in_(record_ids))
    elif group:
        stmt = stmt.where(ExtractedRecord.review_group == group)
    approved, skipped = [], []
    for record in session.execute(stmt.order_by(ExtractedRecord.seq)).scalars():
        try:
            approve_record(session, ctx, record.id)
            approved.append(str(record.id))
        except ValidationFailed as exc:
            skipped.append({"record_id": str(record.id), "seq": record.seq, "reason": exc.detail})
    if job.import_policy == "all_or_nothing" and skipped:
        raise ValidationFailed(f"All-or-nothing import: {len(skipped)} record(s) cannot be approved yet",
                               code="all_or_nothing_blocked", extra={"skipped": skipped})
    return {"approved": approved, "skipped": skipped}


def _update_job_after_review(session: Session, job: IngestionJob) -> None:
    session.flush()
    rows = session.execute(select(ExtractedRecord.status, ExtractedRecord.review_group).where(ExtractedRecord.job_id == job.id)).all()
    counts: dict[str, int] = {}
    for status, group in rows:
        counts[status] = counts.get(status, 0) + 1
        if status == "needs_review":
            counts[f"group_{group}"] = counts.get(f"group_{group}", 0) + 1
    job.stats = {**(job.stats or {}), "records": counts, "total_records": len(rows)}
    if job.status in ("awaiting_review", "completed") and rows:
        job.status = "completed" if all(s in ("committed", "rejected") for s, _ in rows) else "awaiting_review"


# --------------------------------------------------------------------------- column mapping


def update_mapping(session: Session, ctx: RequestContext, job_id: uuid.UUID, item_id: uuid.UUID, table_seq: int,
                   record_type_code: str, mapping: dict[int, str | None], save_template: bool = True) -> dict[str, Any]:
    """Apply a person's column mapping and re-run interpretation for that table deterministically."""
    ctx.require("agent.review")
    job = get_job(session, ctx, job_id)
    item = session.execute(select(IngestionItem).where(IngestionItem.id == item_id, IngestionItem.job_id == job.id)).scalar_one_or_none()
    if item is None:
        raise NotFound("Item not found")
    rt = record_type(record_type_code)
    table = session.execute(select(ParsedSegment).where(ParsedSegment.item_id == item.id, ParsedSegment.seq == table_seq,
                                                        ParsedSegment.kind == "table")).scalar_one_or_none()
    if table is None:
        raise NotFound("Table not found")
    headers = table.content["headers"]
    valid = {f.name for f in (*rt.fields, *rt.line_fields)}
    targets = [t for t in mapping.values() if t]
    if set(targets) - valid:
        raise ValidationFailed(f"Unknown field(s): {', '.join(sorted(set(targets) - valid))}")
    if len(targets) != len(set(targets)):
        raise ValidationFailed("Each field can be mapped from only one column")
    rows_segments = session.execute(select(ParsedSegment).where(ParsedSegment.item_id == item.id, ParsedSegment.kind == "row",
                                                                ParsedSegment.table_seq == table_seq).order_by(ParsedSegment.seq)).scalars().all()
    existing = session.execute(select(ExtractedRecord).where(ExtractedRecord.item_id == item.id)).scalars().all()
    table_records = [r for r in existing if (r.row_ref or {}).get("table_seq") == table_seq]
    if any(r.status in ("approved", "committed") for r in table_records):
        raise Conflict("Some rows of this table are already approved or saved; reopen or reject them before re-mapping",
                       code="mapping_locked")
    for r in table_records:
        session.delete(r)
    session.flush()
    full = {i: (mapping.get(i), 1.0, "user") for i in range(len(headers))}
    save_mapping(session, item, table_seq, rt, headers, full, confirmed_by=ctx.user_id)
    seq = (max((r.seq for r in existing), default=0)) + 1
    created = interpret_table(session, job, item, table, rows_segments, rt, full, seq, job.created_at.date())
    session.flush()
    env = build_env(session, job.company_id, job, rt)
    for record in session.execute(select(ExtractedRecord).where(ExtractedRecord.item_id == item.id,
                                                                ExtractedRecord.status == "draft")).scalars():
        refresh_record(session, record, env)
    if save_template:
        signature = header_signature(headers)
        template = session.execute(select(MappingTemplate).where(
            MappingTemplate.company_id == ctx.company_id, MappingTemplate.record_type == rt.code,
            MappingTemplate.header_signature == signature)).scalar_one_or_none()
        if template is None:
            template = MappingTemplate(company_id=ctx.company_id, record_type=rt.code, header_signature=signature, created_by=ctx.user_id)
            session.add(template)
        template.headers = headers
        template.mapping = {normalize_key(h): mapping.get(i) for i, h in enumerate(headers)}
    audit.record(session, "agent.mapping.updated", ctx=ctx, entity_type="ingestion_item", entity_id=item.id,
                 changes={"record_type": rt.code, "mapping": {headers[i]: t for i, t in mapping.items() if i < len(headers)},
                          "saved_template": save_template})
    job.status = "awaiting_review"
    _update_job_after_review(session, job)
    return {"records": created}


def current_mapping(session: Session, item_id: uuid.UUID, table_seq: int) -> list[ColumnMapping]:
    return list(session.execute(select(ColumnMapping).where(ColumnMapping.item_id == item_id, ColumnMapping.table_seq == table_seq)
                                .order_by(ColumnMapping.source_index)).scalars())


# --------------------------------------------------------------------------- auto-approval


def auto_approve_ready(session: Session, job: IngestionJob) -> list[uuid.UUID]:
    """Administrator-enabled auto-approval: only `ready` records (no warnings, every field high
    confidence) of record types whose workflow has auto_approve on (D7)."""
    approved = []
    for record in session.execute(select(ExtractedRecord).where(
        ExtractedRecord.job_id == job.id, ExtractedRecord.status == "needs_review", ExtractedRecord.review_group == "ready",
    )).scalars():
        rt = record_type(record.record_type)
        wf = get_workflow(session, job.company_id, rt.code)
        if wf is None or not wf.auto_approve:
            continue
        if any(f.state != "high_confidence" for f in record.fields if f.raw_value):
            continue
        _decide(session, None, record, "approve", "Auto-approved by workflow policy", auto=True)
        approved.append(record.id)
    _update_job_after_review(session, job)
    return approved
