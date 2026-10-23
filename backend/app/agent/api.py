"""BIWORK Agent API (/api/v1/agent) — docs/architecture.md §5.4."""

from __future__ import annotations

import uuid
from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, File, Form, Header, Query, UploadFile
from fastapi.responses import JSONResponse, Response
from pydantic import BaseModel, Field
from sqlalchemy import func, or_, select
from sqlalchemy.orm import Session

from app.agent import tasks
from app.agent.models import (
    AICall,
    Approval,
    CommittedWrite,
    ExtractedRecord,
    IngestionItem,
    IngestionJob,
    MatchCandidate,
    ParsedSegment,
    StageRun,
    ValidationResult,
)
from app.agent.pipeline import commit as commit_stage
from app.agent.pipeline import review
from app.agent.pipeline.ingest import IMPORT_POLICIES, UploadedFile, allowed_record_types, create_job
from app.agent.pipeline.orchestrator import prepare_retry
from app.agent.pipeline.report import error_report_xlsx, job_report
from app.agent.usage import usage_report
from app.ai.extraction import provider_info
from app.ai.router import describe as describe_routing
from app.ai.router import health_report as provider_health
from app.analytics.service import agent_stats
from app.config import get_settings
from app.core import audit
from app.core.api import DB, Ctx, Idem, IfMatch, paginate
from app.core.errors import AppError, NotFound, PayloadTooLarge, ValidationFailed
from app.core.models import StoredFile, User
from app.core.services.files import EXTENSIONS
from app.db.session import after_commit
from app.modules.registry import record_type, record_types

router = APIRouter(prefix="/agent", tags=["agent"])


# --------------------------------------------------------------------------- serializers


def _names(db: Session, ids) -> dict[uuid.UUID, str]:
    ids = {i for i in ids if i}
    return dict(db.execute(select(User.id, User.full_name).where(User.id.in_(ids))).all()) if ids else {}


def job_out(job: IngestionJob, names: dict | None = None, items: int | None = None) -> dict[str, Any]:
    return {
        "id": str(job.id), "title": job.title, "status": job.status, "current_stage": job.current_stage,
        "source_kind": job.source_kind, "channel": job.channel, "import_policy": job.import_policy,
        "target_record_type": job.target_record_type, "created_at": job.created_at, "started_at": job.started_at,
        "finished_at": job.finished_at, "submitted_by": (names or {}).get(job.submitted_by),
        "stats": job.stats, "warnings": job.warnings, "error": job.error, "attempt": job.attempt,
        "ai_provider": job.ai_provider, "ai_model": job.ai_model, "ai_is_mock": job.ai_is_mock,
        "ai_mode": "demo" if job.ai_is_mock else "live", "prompt_version": job.prompt_version,
        "pipeline_version": job.pipeline_version, "duplicate_of_job_id": str(job.duplicate_of_job_id) if job.duplicate_of_job_id else None,
        "items": items,
    }


def _tables(db: Session, item: IngestionItem) -> list[dict[str, Any]]:
    tables = db.execute(select(ParsedSegment).where(ParsedSegment.item_id == item.id, ParsedSegment.kind == "table")
                        .order_by(ParsedSegment.seq)).scalars().all()
    out = []
    for t in tables:
        mappings = review.current_mapping(db, item.id, t.seq)
        out.append({
            "table_seq": t.seq, "name": t.content["name"], "sheet": t.sheet, "headers": t.content["headers"],
            "header_row": t.content.get("header_row"), "n_rows": t.content.get("n_rows"), "column_types": t.content.get("column_types"),
            "preamble": t.content.get("preamble"), "record_type": mappings[0].record_type if mappings else None,
            "mapping": [{"source_index": m.source_index, "source_column": m.source_column, "target_field": m.target_field,
                         "confidence": float(m.confidence), "mapped_by": m.mapped_by, "confirmed": m.confirmed_by is not None}
                        for m in mappings],
        })
    return out


def item_out(db: Session, item: IngestionItem, files: dict[uuid.UUID, StoredFile]) -> dict[str, Any]:
    f = files.get(item.file_id) if item.file_id else None
    return {
        "id": str(item.id), "position": item.position, "format": item.detected_format, "status": item.status, "error": item.error,
        "file": {"id": str(f.id), "name": f.original_name, "size": f.size, "mime_type": f.mime_type, "scan_status": f.scan_status} if f else None,
        "text_preview": (item.raw_text or "")[:500] if item.raw_text else None, "parser": item.parser, "ocr_used": item.ocr_used,
        "ocr_confidence": float(item.ocr_confidence) if item.ocr_confidence is not None else None, "page_count": item.page_count,
        "warnings": item.warnings, "detected_record_type": item.detected_record_type, "is_tabular": bool(item.meta.get("is_tabular")),
        "tables": _tables(db, item),
    }


def record_summary(r: ExtractedRecord, counts: dict[str, int] | None = None, number: str | None = None) -> dict[str, Any]:
    return {
        "id": str(r.id), "seq": r.seq, "job_id": str(r.job_id), "item_id": str(r.item_id), "record_type": r.record_type,
        "target_module": r.target_module, "status": r.status, "review_group": r.review_group, "summary": r.summary,
        "overall_confidence": float(r.overall_confidence) if r.overall_confidence is not None else None, "row_ref": r.row_ref,
        "error": r.error, "payload_version": r.payload_version, "issues": counts or {}, "committed_number": number,
        "reviewed_at": r.reviewed_at,
    }


def record_detail(db: Session, r: ExtractedRecord) -> dict[str, Any]:
    rt = record_type(r.record_type)
    candidates = db.execute(select(MatchCandidate).where(MatchCandidate.record_id == r.id).order_by(MatchCandidate.score.desc())).scalars().all()
    by_field: dict[str | None, list[MatchCandidate]] = {}
    for c in candidates:
        by_field.setdefault(c.field, []).append(c)
    approvals = db.execute(select(Approval).where(Approval.record_id == r.id).order_by(Approval.decided_at)).scalars().all()
    names = _names(db, [a.decided_by for a in approvals] + [f.edited_by for f in r.fields])
    write = db.execute(select(CommittedWrite).where(CommittedWrite.record_id == r.id)).scalar_one_or_none()
    item = db.get(IngestionItem, r.item_id)
    issues = [{"id": v.id, "field": v.field, "severity": v.severity, "rule_code": v.rule_code, "message": v.message,
               "stage": v.stage, "details": v.details} for v in r.validation_results]

    def field_out(f) -> dict[str, Any]:
        spec = rt.field(f.field.split(".")[-1])
        ref = (r.references or {}).get(f.field)
        return {
            "field": f.field, "label": spec.label if spec else f.field, "type": spec.type if spec else "string",
            "required": spec.required if spec else False, "enum": list(spec.enum) if spec and spec.enum else None,
            "reference": spec.reference if spec else None, "raw_value": f.raw_value, "normalized_value": f.normalized_value,
            "confidence": float(f.confidence) if f.confidence is not None else None, "state": f.state, "state_reason": f.state_reason,
            "extracted_by": f.extracted_by, "edited_by": names.get(f.edited_by), "edited_at": f.edited_at,
            "alternatives": (f.model_value or {}).get("alternatives") or [],
            "match": ref, "candidates": [{"entity_id": str(c.entity_id), "label": c.label, "score": float(c.score),
                                          "match_kind": c.match_kind, "selected": c.selected} for c in by_field.get(f.field, [])],
            "sources": [{"page": s.page, "sheet": s.sheet, "cell": s.cell, "bbox": s.bbox, "text_start": s.text_start,
                         "text_end": s.text_end, "excerpt": s.excerpt} for s in f.source_refs],
        }

    header = [field_out(f) for f in r.fields if not f.field.startswith("lines.")]
    lines: dict[int, list[dict[str, Any]]] = {}
    for f in r.fields:
        if f.field.startswith("lines."):
            lines.setdefault(int(f.field.split(".")[1]), []).append(field_out(f))
    return {
        **record_summary(r, number=write.target_number if write else None),
        "label": rt.label, "has_lines": rt.has_lines, "lines_label": rt.lines_label, "payload": r.payload,
        "approve_permission": rt.approve_permission,
        "fields": header, "lines": [lines[i] for i in sorted(lines)], "issues": issues,
        "duplicates": [{"entity_type": c.entity_type, "entity_id": str(c.entity_id), "label": c.label, "score": float(c.score),
                        "kind": c.match_kind, "details": c.details} for c in by_field.get(None, [])],
        "approvals": [{"decision": a.decision, "by": names.get(a.decided_by) or ("system" if a.auto else None), "auto": a.auto,
                       "comment": a.comment, "diff": a.diff if a.decision == "correct" else None, "at": a.decided_at,
                       "payload_version": a.payload_version} for a in approvals],
        "committed": {"target_table": write.target_table, "target_id": str(write.target_id), "number": write.target_number,
                      "committed_at": write.committed_at} if write else None,
        "source": {"item_id": str(item.id), "format": item.detected_format, "file_id": str(item.file_id) if item.file_id else None,
                   "ocr_used": item.ocr_used},
        **review_context(db, r, rt, item, write),
    }


ORIGINAL_INPUT_PREVIEW = 20_000


def review_context(db: Session, r: ExtractedRecord, rt, item: IngestionItem, write: CommittedWrite | None) -> dict[str, Any]:
    """What a reviewer needs beside the fields: the original input, which provider/model produced
    the proposal (and whether it is simulated), the uncertain values, and the database change that
    approval would cause."""
    text = item.meta.get("full_text") or item.raw_text or ""
    stored = db.get(StoredFile, item.file_id) if item.file_id else None
    meta = r.ai_meta or {}
    calls = db.execute(select(AICall).where(AICall.item_id == item.id).order_by(AICall.created_at)).scalars().all()
    uncertain = [{"field": f.field, "state": f.state, "reason": f.state_reason, "value": f.raw_value,
                  "confidence": float(f.confidence) if f.confidence is not None else None}
                 for f in r.fields if f.state in ("low_confidence", "conflicting", "missing", "invalid", "needs_approval")]
    return {
        "original_input": {
            "format": item.detected_format, "filename": stored.original_name if stored else item.meta.get("filename"),
            "file_id": str(item.file_id) if item.file_id else None, "ocr_used": item.ocr_used,
            "text": text[:ORIGINAL_INPUT_PREVIEW], "truncated": len(text) > ORIGINAL_INPUT_PREVIEW,
            "row_ref": r.row_ref,
        },
        "ai": {
            "provider": meta.get("provider"), "model": meta.get("model"), "tier": meta.get("tier"),
            "routing_reason": meta.get("routing_reason"), "fallback_from": meta.get("fallback_from"),
            "is_demo": bool(meta.get("is_demo")), "escalated": bool(meta.get("escalated")),
            "escalation_reason": meta.get("escalation_reason"), "concerns": meta.get("concerns") or [],
            "mean_confidence": meta.get("mean_confidence"),
            "label": "Demo mode — simulated, not real AI analysis" if meta.get("is_demo") else None,
            "calls": [{"task": c.task, "provider": c.provider, "model": c.model, "tier": c.tier, "status": c.status,
                       "attempt": c.attempt, "fallback_from": c.fallback_from, "latency_ms": c.latency_ms,
                       "input_tokens": c.input_tokens, "output_tokens": c.output_tokens,
                       "cost_usd": float(c.cost_usd) if c.cost_usd is not None else None, "error": c.error} for c in calls],
        } if meta or calls else None,
        "uncertain_values": uncertain,
        "proposed_change": {
            "action": "create", "target": rt.label, "record_type": rt.code, "module": rt.module,
            "values": r.payload, "references": r.references, "requires_permission": rt.write_permission,
            "status": "written" if write else ("blocked" if r.review_group == "needs_correction" else "pending_approval"),
            "written_to": {"table": write.target_table, "id": str(write.target_id), "number": write.target_number} if write else None,
        },
    }


# --------------------------------------------------------------------------- submission


def _submit(db: Session, ctx, idem_key: str | None, **kwargs) -> JSONResponse:
    try:
        job = create_job(db, ctx, idempotency_key=idem_key, **kwargs)
    except AppError:
        db.rollback()
        raise
    out = job_out(job, items=len(job.items))
    db.commit()  # enqueues processing after commit
    return JSONResponse(_jsonable(out), status_code=202)


def _jsonable(value: Any) -> Any:
    import json

    return json.loads(json.dumps(value, default=str))


@router.post("/jobs", status_code=202)
def submit(ctx: Ctx, db: DB, files: list[UploadFile] | None = File(None), text: str | None = Form(None),
           record_type_code: str | None = Form(None, alias="record_type"), import_policy: str = Form("valid_rows_only"),
           force: bool = Form(False), title: str | None = Form(None),
           idempotency_key: str | None = Header(None, alias="Idempotency-Key", max_length=120)) -> JSONResponse:
    """Submit text and/or files (multipart/form-data) for processing."""
    limit = get_settings().max_upload_bytes
    uploads = []
    for f in files or []:
        data = f.file.read(limit + 1)
        if len(data) > limit:
            raise PayloadTooLarge(f"'{f.filename}' exceeds the {get_settings().max_upload_mb} MB limit")
        uploads.append(UploadedFile(f.filename or "upload", data))
    return _submit(db, ctx, idempotency_key, text=text, files=uploads, target_record_type=record_type_code or None,
                   import_policy=import_policy, force=force, title=title, channel="api" if ctx.actor_type == "api_client" else "web")


class JsonSubmission(BaseModel):
    text: str | None = Field(default=None, max_length=200_000)
    record_type: str | None = None
    payload: dict[str, Any] | None = Field(default=None, description="Structured record(s): {record_type, fields} or {record_type, records}")
    import_policy: str = "valid_rows_only"
    force: bool = False
    title: str | None = None


@router.post("/jobs/json", status_code=202)
def submit_json(data: JsonSubmission, ctx: Ctx, db: DB,
                idempotency_key: str | None = Header(None, alias="Idempotency-Key", max_length=120)) -> JSONResponse:
    """Submit text or a structured payload as JSON (system-to-system integrations, forms)."""
    return _submit(db, ctx, idempotency_key, text=data.text, payload=data.payload, target_record_type=data.record_type,
                   import_policy=data.import_policy, force=data.force, title=data.title,
                   channel="api" if ctx.actor_type == "api_client" else "web")


# --------------------------------------------------------------------------- jobs


@router.get("/jobs")
def list_jobs(ctx: Ctx, db: DB, status: str | None = None, source_kind: str | None = None, q: str | None = None,
              mine: bool = False, cursor: str | None = None, limit: int = Query(50, le=200)) -> dict[str, Any]:
    ctx.require("agent.read")
    stmt = select(IngestionJob).where(IngestionJob.company_id == ctx.company_id)
    if status:
        stmt = stmt.where(IngestionJob.status.in_(status.split(",")))
    if source_kind:
        stmt = stmt.where(IngestionJob.source_kind == source_kind)
    if q:
        stmt = stmt.where(or_(IngestionJob.title.ilike(f"%{q}%"), IngestionJob.id.in_(
            select(IngestionItem.job_id).join(StoredFile, StoredFile.id == IngestionItem.file_id)
            .where(StoredFile.original_name.ilike(f"%{q}%")))))
    if mine:
        stmt = stmt.where(IngestionJob.submitted_by == ctx.user_id)
    rows, next_cursor = paginate(db, stmt, IngestionJob, cursor, limit)
    names = _names(db, [j.submitted_by for j in rows])
    counts = dict(db.execute(select(IngestionItem.job_id, func.count()).where(IngestionItem.job_id.in_([j.id for j in rows]))
                             .group_by(IngestionItem.job_id)).all()) if rows else {}
    return {"items": [job_out(j, names, counts.get(j.id)) for j in rows], "next_cursor": next_cursor}


@router.get("/jobs/{job_id}")
def get_job(job_id: uuid.UUID, ctx: Ctx, db: DB) -> dict[str, Any]:
    ctx.require("agent.read")
    job = review.get_job(db, ctx, job_id)
    names = _names(db, [job.submitted_by])
    files = {f.id: f for f in db.execute(select(StoredFile).where(StoredFile.id.in_([i.file_id for i in job.items if i.file_id]))).scalars()}
    runs = db.execute(select(StageRun).where(StageRun.job_id == job.id).order_by(StageRun.started_at)).scalars().all()
    return {
        **job_out(job, names, len(job.items)),
        "items_detail": [item_out(db, i, files) for i in job.items],
        "stage_runs": [{"stage": r.stage, "status": r.status, "attempt": r.attempt, "item_id": str(r.item_id) if r.item_id else None,
                        "started_at": r.started_at, "finished_at": r.finished_at, "duration_ms": r.duration_ms, "error": r.error,
                        "metrics": r.metrics} for r in runs],
        "report": job_report(db, job),
    }


@router.get("/jobs/{job_id}/records")
def list_records(job_id: uuid.UUID, ctx: Ctx, db: DB, group: str | None = None, status: str | None = None, q: str | None = None,
                 offset: int = Query(0, ge=0), limit: int = Query(50, le=500)) -> dict[str, Any]:
    ctx.require("agent.read")
    job = review.get_job(db, ctx, job_id)
    stmt = select(ExtractedRecord).where(ExtractedRecord.job_id == job.id)
    if group:
        stmt = stmt.where(ExtractedRecord.review_group == group, ExtractedRecord.status == "needs_review")
    if status:
        stmt = stmt.where(ExtractedRecord.status.in_(status.split(",")))
    if q:
        stmt = stmt.where(ExtractedRecord.summary.ilike(f"%{q}%"))
    total = db.execute(select(func.count()).select_from(stmt.subquery())).scalar_one()
    rows = db.execute(stmt.order_by(ExtractedRecord.seq).offset(offset).limit(limit)).scalars().all()
    ids = [r.id for r in rows]
    counts: dict[uuid.UUID, dict[str, int]] = {}
    for rid, severity, n in db.execute(select(ValidationResult.record_id, ValidationResult.severity, func.count())
                                       .where(ValidationResult.record_id.in_(ids))
                                       .group_by(ValidationResult.record_id, ValidationResult.severity)).all():
        counts.setdefault(rid, {})[severity] = n
    numbers = dict(db.execute(select(CommittedWrite.record_id, CommittedWrite.target_number).where(CommittedWrite.record_id.in_(ids))).all())
    groups = dict(db.execute(select(ExtractedRecord.review_group, func.count()).where(
        ExtractedRecord.job_id == job.id, ExtractedRecord.status == "needs_review").group_by(ExtractedRecord.review_group)).all())
    statuses = dict(db.execute(select(ExtractedRecord.status, func.count()).where(ExtractedRecord.job_id == job.id)
                               .group_by(ExtractedRecord.status)).all())
    return {"total": total, "offset": offset, "groups": groups, "statuses": statuses,
            "items": [record_summary(r, counts.get(r.id), numbers.get(r.id)) for r in rows]}


# --------------------------------------------------------------------------- records


@router.get("/records/{record_id}")
def get_record(record_id: uuid.UUID, ctx: Ctx, db: DB) -> dict[str, Any]:
    ctx.require("agent.read")
    return record_detail(db, review.get_record(db, ctx, record_id))


class CorrectionIn(BaseModel):
    fields: dict[str, Any] = Field(default_factory=dict, description="field path -> new raw value (null clears)")
    references: dict[str, uuid.UUID | None] = Field(default_factory=dict, description="field path -> chosen entity id")
    add_lines: int = Field(default=0, ge=0, le=50)
    remove_lines: list[int] = Field(default_factory=list)


@router.patch("/records/{record_id}")
def correct(record_id: uuid.UUID, data: CorrectionIn, ctx: Ctx, db: DB, expected: IfMatch) -> dict[str, Any]:
    record = review.correct_record(db, ctx, record_id, fields=data.fields,
                                   references={k: str(v) if v else None for k, v in data.references.items()},
                                   add_lines=data.add_lines, remove_lines=data.remove_lines, expected_revision=expected)
    db.commit()
    return record_detail(db, record)


class DecisionIn(BaseModel):
    comment: str | None = Field(default=None, max_length=2000)


@router.post("/records/{record_id}/approve")
def approve(record_id: uuid.UUID, ctx: Ctx, db: DB, expected: IfMatch, data: DecisionIn | None = None) -> dict[str, Any]:
    record = review.approve_record(db, ctx, record_id, data.comment if data else None, expected)
    db.commit()
    return record_detail(db, record)


@router.post("/records/{record_id}/reject")
def reject(record_id: uuid.UUID, ctx: Ctx, db: DB, data: DecisionIn | None = None) -> dict[str, Any]:
    record = review.reject_record(db, ctx, record_id, data.comment if data else None)
    db.commit()
    return record_detail(db, record)


@router.post("/records/{record_id}/reopen")
def reopen(record_id: uuid.UUID, ctx: Ctx, db: DB) -> dict[str, Any]:
    record = review.reopen_record(db, ctx, record_id)
    db.commit()
    return record_detail(db, record)


# --------------------------------------------------------------------------- job actions


class BulkApproveIn(BaseModel):
    record_ids: list[uuid.UUID] | None = None
    group: str | None = "ready"


@router.post("/jobs/{job_id}/approve")
def bulk_approve(job_id: uuid.UUID, data: BulkApproveIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    result = review.bulk_approve(db, ctx, job_id, data.record_ids, data.group)
    db.commit()
    return result


class CommitIn(BaseModel):
    record_ids: list[uuid.UUID] | None = None


@router.post("/jobs/{job_id}/commit", status_code=202)
def commit(job_id: uuid.UUID, ctx: Ctx, db: DB, idem: Idem, data: CommitIn | None = None):
    """Save approved records through the owning module services (runs in the commit queue)."""
    ctx.require("agent.review")
    body = data.model_dump(mode="json") if data else {}
    replay = idem.replay(db, ctx, {"job": str(job_id), **body})
    if replay:
        return replay
    job = review.get_job(db, ctx, job_id)
    if job.status in ("processing", "queued", "committing", "cancelled"):
        raise ValidationFailed(f"Cannot save while the job is {job.status}", code="invalid_transition")
    commit_stage.check_commit_allowed(db, job)
    approved = db.execute(select(func.count()).select_from(ExtractedRecord).where(
        ExtractedRecord.job_id == job.id, ExtractedRecord.status == "approved")).scalar_one()
    if not approved:
        raise ValidationFailed("There are no approved records to save", code="nothing_to_commit")
    job.stats = {**job.stats, "commit_requested_by": str(ctx.user_id or ctx.api_client_id),
                 "commit_requested_at": datetime.now(UTC).isoformat()}
    audit.record(db, "agent.job.commit_requested", ctx=ctx, entity_type="ingestion_job", entity_id=job.id,
                 changes={"approved_records": approved, "record_ids": body.get("record_ids")})
    out = {"job_id": str(job.id), "queued_records": approved, "status": "committing"}
    idem.store(db, ctx, 202, out)
    company_id = ctx.company_id
    ids = data.record_ids if data else None
    after_commit(db, lambda: tasks.enqueue_commit(company_id, job_id, ids))
    db.commit()
    return JSONResponse(out, status_code=202)


class MappingIn(BaseModel):
    table_seq: int
    record_type: str
    mapping: dict[int, str | None]
    save_template: bool = True


@router.put("/jobs/{job_id}/items/{item_id}/mapping")
def update_mapping(job_id: uuid.UUID, item_id: uuid.UUID, data: MappingIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    result = review.update_mapping(db, ctx, job_id, item_id, data.table_seq, data.record_type, data.mapping, data.save_template)
    db.commit()
    return result


@router.get("/items/{item_id}/preview")
def item_preview(item_id: uuid.UUID, ctx: Ctx, db: DB, table_seq: int | None = None, offset: int = Query(0, ge=0),
                 limit: int = Query(100, le=500)) -> dict[str, Any]:
    """Parsed content for source preview: pages with line boxes, or table rows."""
    ctx.require("agent.read")
    item = db.execute(select(IngestionItem).where(IngestionItem.id == item_id, IngestionItem.company_id == ctx.company_id)).scalar_one_or_none()
    if item is None:
        raise NotFound("Item not found")
    if table_seq is not None:
        rows = db.execute(select(ParsedSegment).where(ParsedSegment.item_id == item.id, ParsedSegment.kind == "row",
                                                      ParsedSegment.table_seq == table_seq).order_by(ParsedSegment.seq)
                          .offset(offset).limit(limit)).scalars().all()
        return {"table_seq": table_seq, "offset": offset, "rows": [{"row_number": r.content["row_number"], "cells": r.content["cells"]} for r in rows]}
    pages = db.execute(select(ParsedSegment).where(ParsedSegment.item_id == item.id, ParsedSegment.kind == "text")
                       .order_by(ParsedSegment.seq)).scalars().all()
    return {"format": item.detected_format, "ocr_used": item.ocr_used, "full_text": (item.meta or {}).get("full_text") or item.raw_text,
            "pages": [{"page": p.page, "text": p.content.get("text"), "lines": p.content.get("lines"), "width": p.content.get("width"),
                       "height": p.content.get("height"), "ocr": p.content.get("ocr", False)} for p in pages],
            "tables": _tables(db, item)}


class RetryIn(BaseModel):
    record_type: str | None = None


@router.post("/jobs/{job_id}/retry", status_code=202)
def retry(job_id: uuid.UUID, ctx: Ctx, db: DB, data: RetryIn | None = None) -> dict[str, Any]:
    ctx.require("agent.submit")
    job = review.get_job(db, ctx, job_id)
    target = data.record_type if data else None
    if target and target not in allowed_record_types(db, ctx.company_id):
        raise ValidationFailed(f"Record type '{target}' is not available")
    what = prepare_retry(db, job, target)
    audit.record(db, "agent.job.retried", ctx=ctx, entity_type="ingestion_job", entity_id=job.id,
                 changes={"attempt": job.attempt, "resume": what, "record_type": target})
    company_id = ctx.company_id
    after_commit(db, (lambda: tasks.enqueue_commit(company_id, job_id)) if what == "commit" else (lambda: tasks.enqueue_process(company_id, job_id)))
    db.commit()
    return {"job_id": str(job.id), "resumed": what, "attempt": job.attempt}


@router.post("/jobs/{job_id}/cancel")
def cancel(job_id: uuid.UUID, ctx: Ctx, db: DB) -> dict[str, Any]:
    ctx.require("agent.submit")
    job = review.get_job(db, ctx, job_id)
    if job.status in ("completed", "cancelled", "committing"):
        raise ValidationFailed(f"A {job.status} job cannot be cancelled", code="invalid_transition")
    discarded = 0
    for r in db.execute(select(ExtractedRecord).where(ExtractedRecord.job_id == job.id,
                                                      ExtractedRecord.status.in_(("draft", "needs_review", "approved", "failed")))).scalars():
        r.status = "rejected"
        r.error = "Job cancelled"
        discarded += 1
    job.status = "cancelled"
    job.finished_at = datetime.now(UTC)
    audit.record(db, "agent.job.cancelled", ctx=ctx, entity_type="ingestion_job", entity_id=job.id, changes={"discarded_records": discarded})
    db.commit()
    return job_out(job)


@router.get("/jobs/{job_id}/report")
def report(job_id: uuid.UUID, ctx: Ctx, db: DB) -> dict[str, Any]:
    ctx.require("agent.read")
    return job_report(db, review.get_job(db, ctx, job_id))


@router.get("/jobs/{job_id}/error-report")
def error_report(job_id: uuid.UUID, ctx: Ctx, db: DB) -> Response:
    ctx.require("agent.read")
    job = review.get_job(db, ctx, job_id)
    return Response(error_report_xlsx(db, job), media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                    headers={"Content-Disposition": f'attachment; filename="biwork-errors-{str(job.id)[:8]}.xlsx"'})


# --------------------------------------------------------------------------- metadata


@router.get("/record-types")
def list_record_types(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    allowed = set(allowed_record_types(db, ctx.company_id))

    def spec(f) -> dict[str, Any]:
        return {"name": f.name, "label": f.label, "type": f.type, "required": f.required, "reference": f.reference,
                "enum": list(f.enum) or None, "aliases": list(f.aliases), "description": f.description}

    return [{"code": rt.code, "module": rt.module, "label": rt.label, "description": rt.description,
             "fields": [spec(f) for f in rt.fields], "line_fields": [spec(f) for f in rt.line_fields], "lines_label": rt.lines_label,
             "approve_permission": rt.approve_permission, "enabled": rt.code in allowed}
            for rt in record_types().values()]


@router.get("/info")
def info(ctx: Ctx) -> dict[str, Any]:
    s = get_settings()
    return {**provider_info(), "formats": sorted(ext.lstrip(".") for ext in EXTENSIONS), "max_upload_mb": s.max_upload_mb, "max_files_per_job": s.max_files_per_job,
            "import_policies": list(IMPORT_POLICIES), "ocr_enabled": s.ocr_enabled, "ocr_languages": s.ocr_langs,
            "malware_scanning": bool(s.clamav_host), "confidence_threshold": s.agent_confidence_threshold}


@router.get("/ai/status")
def ai_status(ctx: Ctx) -> dict[str, Any]:
    """Effective routing (provider order, models per tier) and provider health. No secrets."""
    ctx.require("agent.read")
    return {**describe_routing(), "providers": provider_health()}


@router.get("/ai/usage")
def ai_usage(ctx: Ctx, db: DB, days: int = Query(30, ge=1, le=365)) -> dict[str, Any]:
    """Usage monitoring: calls, tokens, estimated cost, latency, retries, fallbacks, outcomes."""
    ctx.require("agent.read")
    return usage_report(db, ctx.company_id, days)


@router.get("/stats")
def stats(ctx: Ctx, db: DB, days: int = Query(30, ge=1, le=365)) -> dict[str, Any]:
    ctx.require("agent.read")
    return agent_stats(db, ctx, days)

