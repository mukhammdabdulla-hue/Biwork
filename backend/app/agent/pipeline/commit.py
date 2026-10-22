"""Stage 8 — Commit (docs/architecture.md §5.5, §6.1, §6.4).

Approved records are written through the owning module's service (`RecordTypeSpec.writer`) as the
approving user — never with elevated rights. The business insert and the `committed_writes` row
share one transaction, and `committed_writes.record_id` / `idempotency_key` are unique, so a retried
commit either finds the existing write or performs it, never both (Scenario F).

Import policies:
- valid_rows_only (default): batches commit independently; a failing record is marked `failed` and
  the rest of the batch continues (savepoint per record).
- all_or_nothing: every non-rejected record must be approved; one transaction for everything.
- stop_on_first_error: like valid_rows_only, but stops at the first failing record.
"""

from __future__ import annotations

import hashlib
import uuid
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any

from pydantic import ValidationError
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.agent.models import CommittedWrite, ExtractedRecord, IngestionItem, IngestionJob
from app.agent.pipeline.review import _update_job_after_review, build_env, refresh_record
from app.config import get_settings
from app.core import audit
from app.core.context import RequestContext
from app.core.errors import AppError, ValidationFailed
from app.core.manifest import RecordTypeSpec
from app.core.services.identity import context_for_user_id
from app.db.session import tenant_session
from app.modules.registry import record_type


class RecordCommitError(Exception):
    pass


@dataclass
class CommitOutcome:
    committed: list[dict[str, Any]] = field(default_factory=list)
    failed: list[dict[str, Any]] = field(default_factory=list)
    already_committed: int = 0
    stopped_early: bool = False


def idempotency_key(record: ExtractedRecord) -> str:
    return hashlib.sha256(f"{record.id}:{record.payload_version}".encode()).hexdigest()


def build_write_input(rt: RecordTypeSpec, record: ExtractedRecord, item: IngestionItem) -> dict[str, Any]:
    payload, refs = record.payload or {}, record.references or {}
    data: dict[str, Any] = {}
    for spec in rt.fields:
        value = payload.get(spec.name)
        if spec.type == "reference":
            data[f"{spec.name}_id"] = (refs.get(spec.name) or {}).get("id")
        data[spec.name] = value
    if rt.has_lines:
        lines = []
        for i, line in enumerate(payload.get("lines") or []):
            if not any(v not in (None, "") for v in line.values()):
                continue
            out: dict[str, Any] = {}
            for spec in rt.line_fields:
                if spec.type == "reference":
                    out[f"{spec.name}_id"] = (refs.get(f"lines.{i}.{spec.name}") or {}).get("id")
                out[spec.name] = line.get(spec.name)
            lines.append(out)
        data["lines"] = lines
    data["source_file_id"] = item.file_id
    return data


def _actor_context(session: Session, record: ExtractedRecord, rt: RecordTypeSpec, job: IngestionJob) -> RequestContext:
    if record.reviewed_by is not None:
        ctx = context_for_user_id(session, record.reviewed_by, record.company_id, request_id=f"commit:{job.id}")
        ctx.require(rt.write_permission, rt.approve_permission)  # re-checked: permissions may have changed since approval
    else:
        # Administrator-enabled auto-approval: a narrowly scoped system actor for this record type only.
        ctx = RequestContext(company_id=record.company_id, permissions=frozenset({rt.write_permission, rt.approve_permission}),
                             actor_type="agent", request_id=f"auto-commit:{job.id}")
    return ctx.with_provenance(
        ingestion_job_id=str(job.id), extracted_record_id=str(record.id), pipeline_version=job.pipeline_version,
        prompt_version=job.prompt_version, ai_provider=job.ai_provider, ai_model=job.ai_model,
        approved_by=str(record.reviewed_by) if record.reviewed_by else "auto",
    )


def commit_record(session: Session, record: ExtractedRecord, job: IngestionJob, env) -> dict[str, Any]:
    existing = session.execute(select(CommittedWrite).where(CommittedWrite.record_id == record.id)).scalar_one_or_none()
    if existing is not None:  # idempotent replay
        record.status = "committed"
        return {"record_id": str(record.id), "number": existing.target_number, "target_table": existing.target_table,
                "target_id": str(existing.target_id), "replayed": True}
    rt = record_type(record.record_type)
    issues = refresh_record(session, record, env, set_status=False)
    errors = [i.message for i in issues if i.severity == "error"]
    if errors:
        raise RecordCommitError("; ".join(errors[:3]))
    ctx = _actor_context(session, record, rt, job)
    item = session.get(IngestionItem, record.item_id)
    try:
        data = rt.schema.model_validate(build_write_input(rt, record, item))
    except ValidationError as exc:
        raise RecordCommitError("; ".join(f"{'.'.join(map(str, e['loc']))}: {e['msg']}" for e in exc.errors()[:3])) from exc
    result = rt.writer(session, ctx, data, record.id)
    session.add(CommittedWrite(
        company_id=record.company_id, record_id=record.id, target_table=result.target_table, target_id=result.target_id,
        target_number=result.number, operation="insert", idempotency_key=idempotency_key(record), committed_by=ctx.user_id,
    ))
    record.status = "committed"
    record.error = None
    session.flush()
    audit.record(session, "agent.record.committed", ctx=ctx, entity_type="extracted_record", entity_id=record.id,
                 summary=f"Saved as {result.number or result.target_id}",
                 changes={"target_table": result.target_table, "target_id": str(result.target_id), "number": result.number})
    return {"record_id": str(record.id), "seq": record.seq, "number": result.number, "label": result.label,
            "target_table": result.target_table, "target_id": str(result.target_id)}


def _approved_ids(session: Session, job_id: uuid.UUID, record_ids: list[uuid.UUID] | None) -> list[uuid.UUID]:
    stmt = select(ExtractedRecord.id).where(ExtractedRecord.job_id == job_id, ExtractedRecord.status == "approved")
    if record_ids:
        stmt = stmt.where(ExtractedRecord.id.in_(record_ids))
    return list(session.execute(stmt.order_by(ExtractedRecord.seq)).scalars())


def check_commit_allowed(session: Session, job: IngestionJob) -> None:
    if job.import_policy == "all_or_nothing":
        open_records = session.execute(select(ExtractedRecord.seq).where(
            ExtractedRecord.job_id == job.id, ExtractedRecord.status.in_(("draft", "needs_review", "failed")),
        )).scalars().all()
        if open_records:
            raise ValidationFailed(
                f"All-or-nothing import: {len(open_records)} record(s) are not approved or rejected yet "
                f"(#{', #'.join(map(str, open_records[:10]))})", code="all_or_nothing_blocked",
            )


def commit_job(company_id: uuid.UUID, job_id: uuid.UUID, record_ids: list[uuid.UUID] | None = None) -> CommitOutcome:
    outcome = CommitOutcome()
    with tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        check_commit_allowed(s, job)
        ids = _approved_ids(s, job_id, record_ids)
        policy = job.import_policy
        job.status = "committing"
    batch_size = max(len(ids), 1) if policy == "all_or_nothing" else get_settings().agent_commit_batch_size
    for start in range(0, len(ids), batch_size):
        batch = ids[start:start + batch_size]
        with tenant_session(company_id) as s:
            job = s.get(IngestionJob, job_id)
            env = build_env(s, company_id, job)
            for rid in batch:
                record = s.get(ExtractedRecord, rid)
                if policy == "all_or_nothing":
                    try:
                        outcome.committed.append(commit_record(s, record, job, env))
                    except (RecordCommitError, AppError, IntegrityError) as exc:
                        raise ValidationFailed(f"All-or-nothing import rolled back: record #{record.seq}: {_message(exc)}",
                                               code="commit_rolled_back", extra={"record_id": str(record.id)}) from exc
                    continue
                savepoint = s.begin_nested()
                try:
                    outcome.committed.append(commit_record(s, record, job, env))
                    savepoint.commit()
                except (RecordCommitError, AppError, IntegrityError) as exc:
                    savepoint.rollback()
                    record = s.get(ExtractedRecord, rid)
                    record.status = "failed"
                    record.error = _message(exc)[:2000]
                    outcome.failed.append({"record_id": str(rid), "seq": record.seq, "error": record.error})
                    if policy == "stop_on_first_error":
                        outcome.stopped_early = True
                        break
        if outcome.stopped_early:
            break
    with tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        job.status = "awaiting_review"
        job.error = None
        _update_job_after_review(s, job)
        if job.status == "completed":
            job.finished_at = datetime.now(UTC)
        job.stats = {**job.stats, "last_commit": {"committed": len(outcome.committed), "failed": len(outcome.failed),
                                                   "stopped_early": outcome.stopped_early, "at": datetime.now(UTC).isoformat()}}
    return outcome


def _message(exc: BaseException) -> str:
    if isinstance(exc, AppError):
        return exc.detail
    if isinstance(exc, IntegrityError):
        return f"Database constraint violated: {str(exc.orig).splitlines()[0] if exc.orig else exc}"
    return str(exc)
