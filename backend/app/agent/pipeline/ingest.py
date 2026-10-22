"""Stage 1 — Ingest (docs/architecture.md §6.1): accept text, files, or a structured payload, store
originals, fingerprint the content, detect repeated uploads before any AI cost is spent, and
enqueue processing after the transaction commits."""

from __future__ import annotations

import dataclasses
import hashlib
import json
import uuid
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.agent.models import IngestionItem, IngestionJob, StageRun
from app.agent.versioning import pipeline_version
from app.ai.extraction import provider_info
from app.config import get_settings
from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict, ValidationFailed
from app.core.models import CompanyModule
from app.core.services.files import store_upload
from app.core.text import clean_text
from app.db.session import after_commit
from app.modules.registry import record_types_for_modules

IMPORT_POLICIES = ("valid_rows_only", "all_or_nothing", "stop_on_first_error")
MAX_TEXT_CHARS = 200_000


@dataclass
class UploadedFile:
    filename: str
    data: bytes


def enabled_modules(session: Session, company_id: uuid.UUID) -> list[str]:
    return list(session.execute(
        select(CompanyModule.module_code).where(CompanyModule.company_id == company_id, CompanyModule.enabled.is_(True))
    ).scalars())


def allowed_record_types(session: Session, company_id: uuid.UUID) -> list[str]:
    return [rt.code for rt in record_types_for_modules(enabled_modules(session, company_id))]


def _fingerprint(file_hashes: list[str], text: str | None, payload: dict[str, Any] | None, target: str | None) -> str:
    normalized_text = " ".join((text or "").split()).lower()
    material = json.dumps({"files": sorted(file_hashes), "text": normalized_text, "payload": payload, "target": target},
                          sort_keys=True, ensure_ascii=False, default=str)
    return hashlib.sha256(material.encode()).hexdigest()


def find_live_duplicate(session: Session, company_id: uuid.UUID, fingerprint: str) -> IngestionJob | None:
    return session.execute(select(IngestionJob).where(
        IngestionJob.company_id == company_id, IngestionJob.content_fingerprint == fingerprint,
        IngestionJob.status.notin_(("failed", "cancelled")),
    ).order_by(IngestionJob.created_at)).scalars().first()


def create_job(
    session: Session, ctx: RequestContext, *, text: str | None = None, files: list[UploadedFile] | None = None,
    payload: dict[str, Any] | None = None, target_record_type: str | None = None, import_policy: str = "valid_rows_only",
    force: bool = False, idempotency_key: str | None = None, channel: str = "web", title: str | None = None,
) -> IngestionJob:
    ctx.require("agent.submit")
    settings = get_settings()
    files = files or []
    text = text.strip() if text and text.strip() else None
    if not (text or files or payload):
        raise ValidationFailed("Provide text, at least one file, or a structured payload")
    if len(files) > settings.max_files_per_job:
        raise ValidationFailed(f"At most {settings.max_files_per_job} files per submission")
    if text and len(text) > MAX_TEXT_CHARS:
        raise ValidationFailed(f"Text is longer than {MAX_TEXT_CHARS:,} characters; upload it as a file instead")
    if import_policy not in IMPORT_POLICIES:
        raise ValidationFailed(f"import_policy must be one of {', '.join(IMPORT_POLICIES)}")
    allowed = allowed_record_types(session, ctx.company_id)
    if payload is not None:
        target_record_type = target_record_type or payload.get("record_type")
        if not target_record_type:
            raise ValidationFailed("A structured payload needs a record_type")
    if target_record_type and target_record_type not in allowed:
        raise ValidationFailed(f"Record type '{target_record_type}' is not available for this company",
                               extra={"allowed": allowed})

    # Hash files before storing anything, so a repeated upload costs neither storage nor AI.
    file_hashes = [hashlib.sha256(f.data).hexdigest() for f in files]
    fingerprint = _fingerprint(file_hashes, text, payload, target_record_type)

    if idempotency_key:
        existing = session.execute(select(IngestionJob).where(
            IngestionJob.company_id == ctx.company_id, IngestionJob.idempotency_key == idempotency_key,
        )).scalar_one_or_none()
        if existing is not None:
            if existing.content_fingerprint != fingerprint:
                raise Conflict("This Idempotency-Key was already used for a different submission", code="idempotency_key_reused")
            return existing

    previous = find_live_duplicate(session, ctx.company_id, fingerprint)
    if previous is not None and not force:
        raise Conflict(
            "This content was already submitted. Open the previous job, or resubmit with force=true to process it "
            "again (existing records will still be detected as duplicates).",
            code="duplicate_upload",
            extra={"duplicate_of_job_id": str(previous.id), "duplicate_of_status": previous.status,
                   "duplicate_of_created_at": previous.created_at.isoformat(), "duplicate_of_title": previous.title},
        )

    info = provider_info()
    warnings: list[str] = []
    if info["is_mock"]:
        warnings.append(info["demo_notice"])
    if files and not settings.clamav_host:
        warnings.append("Malware scanning is not configured (CLAMAV_HOST unset); files were not scanned")
    if previous is not None:
        warnings.append(f"Re-processing content already submitted in job {previous.id}")

    job = IngestionJob(
        company_id=ctx.company_id, submitted_by=ctx.user_id, api_client_id=ctx.api_client_id, channel=channel,
        source_kind="form" if payload is not None else ("file" if files else "text"),
        title=clean_text(title, 300) or (files[0].filename if files else (text or "")[:120]),
        status="queued", import_policy=import_policy, target_record_type=target_record_type,
        idempotency_key=idempotency_key, content_fingerprint=fingerprint, force_reprocess=bool(force and previous),
        duplicate_of_job_id=previous.id if previous else None, pipeline_version=pipeline_version(),
        ai_provider=info["provider"], ai_model=(info["model_extract"] or "")[:60] or None, ai_is_mock=info["is_mock"],
        prompt_version=info["prompt_version"], warnings=warnings, stats={},
    )
    session.add(job)
    session.flush()

    # Storing an upload is part of submitting it: agent.submit implies the narrow files.write grant.
    file_ctx = dataclasses.replace(ctx, permissions=ctx.permissions | {"files.write"})
    position = 0
    if text:
        job.items.append(IngestionItem(company_id=ctx.company_id, position=position, raw_text=text, detected_format="text",
                                       sha256=hashlib.sha256(text.encode()).hexdigest()))
        position += 1
    if payload is not None:
        job.items.append(IngestionItem(company_id=ctx.company_id, position=position, raw_payload=payload, detected_format="form",
                                       sha256=hashlib.sha256(json.dumps(payload, sort_keys=True, default=str).encode()).hexdigest()))
        position += 1
    for upload, sha in zip(files, file_hashes, strict=True):
        stored = store_upload(session, file_ctx, upload.filename, upload.data)
        job.items.append(IngestionItem(company_id=ctx.company_id, position=position, file_id=stored.id,
                                       detected_format=stored.detected_format, sha256=sha,
                                       meta={"filename": stored.original_name, "size": stored.size}))
        position += 1
    session.add(StageRun(company_id=ctx.company_id, job_id=job.id, stage="ingest", status="succeeded",
                         finished_at=datetime.now(UTC), duration_ms=0,
                         metrics={"items": position, "files": len(files), "fingerprint": fingerprint[:12]}))
    try:
        session.flush()
    except IntegrityError as exc:  # a concurrent identical submission won the race
        raise Conflict("This content was just submitted by another request", code="duplicate_upload") from exc
    audit.record(session, "agent.job.created", ctx=ctx, entity_type="ingestion_job", entity_id=job.id,
                 summary=f"Submitted {job.source_kind}: {job.title}",
                 changes={"items": position, "fingerprint": fingerprint, "import_policy": import_policy,
                          "target_record_type": target_record_type, "force": bool(force and previous)},
                 context={"pipeline_version": job.pipeline_version, "ai_provider": job.ai_provider})

    from app.agent import tasks  # late import: tasks -> orchestrator -> stages

    company_id, job_id = ctx.company_id, job.id
    after_commit(session, lambda: tasks.enqueue_process(company_id, job_id))
    return job
