"""Audit API: event log, hash-chain verification, and the provenance trace (Scenario G)."""

from __future__ import annotations

import uuid
from datetime import datetime
from typing import Any

from fastapi import APIRouter, Query
from sqlalchemy import select

from app.agent.models import (
    AICall,
    Approval,
    CommittedWrite,
    ExtractedRecord,
    IngestionItem,
    IngestionJob,
    MatchCandidate,
    StageRun,
    ValidationResult,
)
from app.core import audit
from app.core.api import DB, Ctx
from app.core.context import RequestContext
from app.core.errors import NotFound, PermissionDenied
from app.core.models import AuditEvent, StoredFile, User

router = APIRouter(prefix="/audit", tags=["audit"])


def _names(db, ids) -> dict[uuid.UUID, str]:
    ids = {i for i in ids if i}
    return dict(db.execute(select(User.id, User.full_name).where(User.id.in_(ids))).all()) if ids else {}


def _require_audit(ctx: RequestContext) -> None:
    """The audit trail spans every department, so members limited to some departments cannot read it."""
    ctx.require("audit.read")
    if ctx.department_ids is not None:
        raise PermissionDenied("The audit trail covers all departments; your access is limited to some of them",
                               code="department_restricted")


def event_out(e: AuditEvent, names: dict) -> dict[str, Any]:
    return {"id": e.id, "occurred_at": e.occurred_at, "actor_type": e.actor_type, "actor_id": str(e.actor_id) if e.actor_id else None,
            "actor_name": names.get(e.actor_id), "action": e.action, "entity_type": e.entity_type, "entity_id": e.entity_id,
            "summary": e.summary, "changes": e.changes, "context": e.context, "hash": e.hash}


@router.get("/events")
def list_events(ctx: Ctx, db: DB, action: str | None = None, entity_type: str | None = None, entity_id: str | None = None,
                actor_id: uuid.UUID | None = None, since: datetime | None = None, until: datetime | None = None,
                before_id: int | None = Query(None, ge=1, le=2**63 - 1), limit: int = Query(100, ge=1, le=500)) -> dict[str, Any]:
    _require_audit(ctx)
    stmt = select(AuditEvent).where(AuditEvent.company_id == ctx.company_id)
    if action:
        stmt = stmt.where(AuditEvent.action.startswith(action))
    if entity_type:
        stmt = stmt.where(AuditEvent.entity_type == entity_type)
    if entity_id:
        stmt = stmt.where(AuditEvent.entity_id == entity_id)
    if actor_id:
        stmt = stmt.where(AuditEvent.actor_id == actor_id)
    if since:
        stmt = stmt.where(AuditEvent.occurred_at >= since)
    if until:
        stmt = stmt.where(AuditEvent.occurred_at <= until)
    if before_id:
        stmt = stmt.where(AuditEvent.id < before_id)
    rows = db.execute(stmt.order_by(AuditEvent.id.desc()).limit(limit + 1)).scalars().all()
    names = _names(db, [e.actor_id for e in rows])
    return {"items": [event_out(e, names) for e in rows[:limit]], "next_before_id": rows[limit - 1].id if len(rows) > limit else None}


@router.get("/verify")
def verify(ctx: Ctx, db: DB) -> dict[str, Any]:
    _require_audit(ctx)
    return audit.verify_chain(db, ctx.company_id)


def _record_trace(db, record: ExtractedRecord) -> dict[str, Any]:
    job = db.get(IngestionJob, record.job_id)
    item = db.get(IngestionItem, record.item_id)
    stored = db.get(StoredFile, item.file_id) if item.file_id else None
    approvals = db.execute(select(Approval).where(Approval.record_id == record.id).order_by(Approval.decided_at)).scalars().all()
    names = _names(db, [a.decided_by for a in approvals] + [job.submitted_by])
    write = db.execute(select(CommittedWrite).where(CommittedWrite.record_id == record.id)).scalar_one_or_none()
    ai_calls = db.execute(select(AICall).where(AICall.item_id == item.id).order_by(AICall.created_at)).scalars().all()
    return {
        "original_input": {
            "job_id": str(job.id), "submitted_at": job.created_at, "submitted_by": names.get(job.submitted_by),
            "channel": job.channel, "source_kind": job.source_kind, "text": item.raw_text, "payload": item.raw_payload,
            "file": {"id": str(stored.id), "name": stored.original_name, "sha256": stored.sha256, "size": stored.size,
                     "format": stored.detected_format, "scan_status": stored.scan_status} if stored else None,
            "parser": item.parser, "ocr_used": item.ocr_used, "ocr_confidence": item.ocr_confidence, "row_ref": record.row_ref,
        },
        "processing": {
            "pipeline_version": job.pipeline_version, "prompt_version": job.prompt_version, "ai_provider": job.ai_provider,
            "ai_model": job.ai_model, "ai_is_mock": job.ai_is_mock,
            "ai_calls": [{"task": c.task, "provider": c.provider, "model": c.model, "prompt_version": c.prompt_version,
                          "schema_version": c.schema_version, "status": c.status, "input_tokens": c.input_tokens,
                          "output_tokens": c.output_tokens, "cost_usd": c.cost_usd, "data_categories": c.data_categories,
                          "at": c.created_at} for c in ai_calls],
            "stages": [{"stage": r.stage, "status": r.status, "attempt": r.attempt, "started_at": r.started_at,
                        "duration_ms": r.duration_ms, "error": r.error}
                       for r in db.execute(select(StageRun).where(StageRun.job_id == job.id).order_by(StageRun.started_at)).scalars()],
        },
        "extracted_values": [
            {"field": f.field, "raw_value": f.raw_value, "model_value": f.model_value, "normalized_value": f.normalized_value,
             "confidence": f.confidence, "state": f.state, "extracted_by": f.extracted_by, "edited_by": names.get(f.edited_by) or (str(f.edited_by) if f.edited_by else None),
             "sources": [{"page": s.page, "sheet": s.sheet, "cell": s.cell, "bbox": s.bbox, "excerpt": s.excerpt} for s in f.source_refs]}
            for f in record.fields
        ],
        "validation_results": [{"stage": v.stage, "severity": v.severity, "rule": v.rule_code, "field": v.field, "message": v.message}
                               for v in db.execute(select(ValidationResult).where(ValidationResult.record_id == record.id)
                                                   .order_by(ValidationResult.id)).scalars()],
        "matches": [{"field": m.field, "entity_type": m.entity_type, "label": m.label, "score": m.score, "kind": m.match_kind,
                     "selected": m.selected}
                    for m in db.execute(select(MatchCandidate).where(MatchCandidate.record_id == record.id)).scalars() if m.selected
                    or m.match_kind in ("duplicate_record", "pending_record")],
        "approvals": [{"decision": a.decision, "by": names.get(a.decided_by) or ("system (auto-approval)" if a.auto else None),
                       "auto": a.auto, "comment": a.comment, "diff": a.diff, "payload_version": a.payload_version,
                       "at": a.decided_at} for a in approvals],
        "record": {"id": str(record.id), "record_type": record.record_type, "status": record.status, "payload": record.payload,
                   "references": record.references, "payload_version": record.payload_version},
        "database_write": {"target_table": write.target_table, "target_id": str(write.target_id), "number": write.target_number,
                           "idempotency_key": write.idempotency_key, "committed_at": write.committed_at} if write else None,
    }


@router.get("/trace/{entity_type}/{entity_id}")
def trace(entity_type: str, entity_id: uuid.UUID, ctx: Ctx, db: DB) -> dict[str, Any]:
    """Full provenance chain: original input -> extracted values -> validation -> approval ->
    processing versions -> final database change."""
    _require_audit(ctx)
    record = None
    if entity_type == "extracted_record":
        record = db.get(ExtractedRecord, entity_id)
    else:
        write = db.execute(select(CommittedWrite).where(CommittedWrite.target_id == entity_id)).scalar_one_or_none()
        if write is not None:
            record = db.get(ExtractedRecord, write.record_id)
    events = db.execute(select(AuditEvent).where(
        AuditEvent.company_id == ctx.company_id,
        AuditEvent.entity_id.in_([str(entity_id)] + ([str(record.id)] if record else [])),
    ).order_by(AuditEvent.id)).scalars().all()
    if record is None and not events:
        raise NotFound("Nothing is recorded for this entity")
    names = _names(db, [e.actor_id for e in events])
    return {
        "entity_type": entity_type, "entity_id": str(entity_id),
        "created_via": "biwork_agent" if record is not None else "direct",
        "agent": _record_trace(db, record) if record is not None else None,
        "audit_events": [event_out(e, names) for e in events],
    }
