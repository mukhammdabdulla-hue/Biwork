"""Pipeline orchestration (docs/architecture.md §6.2).

Each stage reads its inputs from the database, writes its outputs in its own transaction, records a
`stage_runs` row, and then the next stage is enqueued. Stages are idempotent, so a failed job is
retried from its last successful stage, and the watchdog re-enqueues stages stuck in `running`.
"""

from __future__ import annotations

import logging
import uuid
from datetime import UTC, datetime, timedelta
from typing import Any

from sqlalchemy import null, or_, select, text, update

from app.agent.models import AICall, ExtractedRecord, IngestionItem, IngestionJob, StageRun
from app.agent.pipeline import commit as commit_stage
from app.agent.pipeline.context import StageError, fail_job, set_job, stage_run
from app.agent.pipeline.interpret import interpret_item
from app.agent.pipeline.parse import mark_item_failed, parse_item
from app.agent.pipeline.review import (
    _update_job_after_review,
    auto_approve_ready,
    build_env,
    clear_results,
    finalize,
    issues_of,
    step_match,
    step_normalize,
    step_validate,
    store_issues,
)
from app.ai.providers.base import PermanentAIError
from app.core import audit
from app.core.errors import AppError
from app.core.models import Company
from app.db.session import SessionLocal, tenant_session
from app.modules.registry import record_type

log = logging.getLogger("biwork.agent")


def _items(company_id: uuid.UUID, job_id: uuid.UUID, status: str) -> list[uuid.UUID]:
    with tenant_session(company_id) as s:
        return list(s.execute(select(IngestionItem.id).where(IngestionItem.job_id == job_id, IngestionItem.status == status)
                              .order_by(IngestionItem.position)).scalars())


def _job_status(company_id: uuid.UUID, job_id: uuid.UUID) -> str:
    with tenant_session(company_id) as s:
        return s.get(IngestionJob, job_id).status


def run_parse(company_id: uuid.UUID, job_id: uuid.UUID) -> bool:
    """Stage 2 for every pending item. Returns False when the job cannot continue."""
    if _job_status(company_id, job_id) == "cancelled":
        return False
    set_job(company_id, job_id, status="processing", started_at=datetime.now(UTC), error=None)
    for item_id in _items(company_id, job_id, "pending"):
        try:
            with stage_run(company_id, job_id, "parse", item_id) as metrics, tenant_session(company_id) as s:
                metrics.update(parse_item(s, s.get(IngestionItem, item_id)))
        except StageError as exc:
            with tenant_session(company_id) as s:
                mark_item_failed(s, item_id, str(exc))
    return True


def run_interpret(company_id: uuid.UUID, job_id: uuid.UUID, provider=None) -> bool:
    """Stage 3 for every parsed item (the only stage that calls AI)."""
    if _job_status(company_id, job_id) == "cancelled":
        return False
    for item_id in _items(company_id, job_id, "parsed"):
        try:
            with stage_run(company_id, job_id, "interpret", item_id) as metrics, tenant_session(company_id) as s:
                metrics.update(interpret_item(s, s.get(IngestionItem, item_id), provider=provider))
        except (StageError, PermanentAIError) as exc:
            with tenant_session(company_id) as s:
                mark_item_failed(s, item_id, str(exc))
    return True


def _undecided(s, job_id: uuid.UUID) -> list[ExtractedRecord]:
    return list(s.execute(select(ExtractedRecord).where(
        ExtractedRecord.job_id == job_id, ExtractedRecord.status.in_(("draft", "needs_review")),
    ).order_by(ExtractedRecord.seq)).scalars())


def run_prepare(company_id: uuid.UUID, job_id: uuid.UUID) -> dict[str, Any]:
    """Stages 4–6 as three observable passes over the job's undecided records, then review."""
    with stage_run(company_id, job_id, "normalize") as metrics, tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        count = 0
        cache: dict[str, Any] = {}
        for record in _undecided(s, job_id):
            env = cache.setdefault(record.record_type, build_env(s, company_id, job, record_type(record.record_type)))
            clear_results(s, record)
            store_issues(record, "normalize", step_normalize(record, record_type(record.record_type), env))
            count += 1
        metrics["records"] = count
    with stage_run(company_id, job_id, "validate") as metrics, tenant_session(company_id) as s:
        errors = 0
        for record in _undecided(s, job_id):
            issues = step_validate(record, record_type(record.record_type), issues_of(record))
            errors += sum(1 for i in issues if i.severity == "error")
            store_issues(record, "validate", issues)
        metrics["errors"] = errors
    with stage_run(company_id, job_id, "match") as metrics, tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        envs: dict[str, Any] = {}
        groups: dict[str, int] = {}
        for record in _undecided(s, job_id):
            rt = record_type(record.record_type)
            env = envs.setdefault(rt.code, build_env(s, company_id, job, rt))
            store_issues(record, "match", step_match(record, rt, env))
            s.flush()
            s.expire(record, ["validation_results"])
            finalize(record, rt, env, issues_of(record))
            groups[record.review_group] = groups.get(record.review_group, 0) + 1
            s.flush()
        metrics["groups"] = groups
    auto: list[uuid.UUID] = []
    with stage_run(company_id, job_id, "review") as metrics, tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        items = s.execute(select(IngestionItem).where(IngestionItem.job_id == job_id)).scalars().all()
        records = s.execute(select(ExtractedRecord.id).where(ExtractedRecord.job_id == job_id)).all()
        failed_items = [i for i in items if i.status == "failed"]
        if not records and failed_items:
            reasons = "; ".join(i.error or "unknown error" for i in failed_items)[:1500]
            job.status = "failed"
            job.error = {"stage": "interpret", "message": reasons, "retryable": True, "at": datetime.now(UTC).isoformat()}
            job.finished_at = datetime.now(UTC)
        else:
            job.status = "awaiting_review"
            if failed_items:
                job.warnings = [*job.warnings, *[f"{i.meta.get('filename') or 'Input'}: {i.error}" for i in failed_items]]
            auto = auto_approve_ready(s, job)
            _update_job_after_review(s, job)
        metrics.update({"records": len(records), "failed_items": len(failed_items), "auto_approved": len(auto)})
        audit.record(s, "agent.job.processed", company_id=company_id, actor_type="agent", entity_type="ingestion_job",
                     entity_id=job_id, summary=f"{len(records)} record(s) extracted, status {job.status}",
                     context={"pipeline_version": job.pipeline_version, "prompt_version": job.prompt_version,
                              "ai_provider": job.ai_provider, "ai_model": job.ai_model})
    return {"auto_approved": [str(a) for a in auto]}


def run_commit(company_id: uuid.UUID, job_id: uuid.UUID, record_ids: list[uuid.UUID] | None = None) -> commit_stage.CommitOutcome:
    try:
        with stage_run(company_id, job_id, "commit") as metrics:
            outcome = commit_stage.commit_job(company_id, job_id, record_ids)
            metrics.update({"committed": len(outcome.committed), "failed": len(outcome.failed),
                            "stopped_early": outcome.stopped_early})
    except AppError as exc:  # business-level refusal (e.g. all-or-nothing blocked or rolled back)
        set_job(company_id, job_id, status="awaiting_review",
                error={"stage": "commit", "message": exc.detail, "code": exc.code, "retryable": False,
                       "at": datetime.now(UTC).isoformat()})
        raise
    except Exception as exc:  # crash mid-batch: earlier batches stay committed, retry continues safely
        fail_job(company_id, job_id, "commit", exc, retryable=True)
        raise
    with stage_run(company_id, job_id, "report") as metrics, tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        metrics.update({"status": job.status})
    return outcome


# --------------------------------------------------------------------------- retry / cancel / watchdog


def prepare_retry(session, job: IngestionJob, target_record_type: str | None = None) -> str:
    """Reset failed parts of a job so processing resumes from the last successful stage.
    Returns which pipeline to enqueue: 'process' or 'commit'."""
    stage = (job.error or {}).get("stage")
    if job.status not in ("failed",):
        raise AppError(f"Only failed jobs can be retried (status: {job.status})", code="invalid_transition")
    job.attempt += 1
    job.error = None
    job.finished_at = None
    if stage == "commit":
        job.status = "awaiting_review"
        return "commit"
    if target_record_type:
        job.target_record_type = target_record_type
    for item in job.items:
        if item.status == "failed":
            has_segments = session.execute(text("SELECT 1 FROM agent.parsed_segments WHERE item_id = :i LIMIT 1"),
                                           {"i": item.id}).first() is not None
            item.status = "parsed" if has_segments and item.parser else "pending"
            item.error = None
    job.status = "queued"
    return "process"


def watchdog(timeout_seconds: int) -> list[tuple[uuid.UUID, uuid.UUID]]:
    """Find stages stuck in `running` (worker died) across companies, mark them failed, return jobs to resume."""
    with SessionLocal() as s:
        stale = s.execute(text("SELECT company_id, job_id, stage FROM agent.stale_stage_runs(:t)"), {"t": timeout_seconds}).all()
    resumed = []
    for company_id, job_id, stage in stale:
        with tenant_session(company_id) as s:
            for run in s.execute(select(StageRun).where(StageRun.job_id == job_id, StageRun.status == "running")).scalars():
                run.status = "failed"
                run.finished_at = datetime.now(UTC)
                run.error = f"Stage exceeded {timeout_seconds}s without finishing (worker lost); re-enqueued by watchdog"
            job = s.get(IngestionJob, job_id)
            if job.status in ("processing", "queued"):
                job.status = "queued"
                resumed.append((company_id, job_id))
            elif job.status == "committing":
                job.status = "failed"
                job.error = {"stage": "commit", "message": "Commit interrupted (worker lost)", "retryable": True}
        log.warning("watchdog: job %s stuck in %s", job_id, stage)
    return resumed


def purge_ai_payloads() -> int:
    """Enforce each company's `ai_policy.retain_ai_payloads_days`: drop stored AI request/response
    content older than the retention period (0 = keep none). Call metadata stays for cost and audit."""
    cleared = 0
    with SessionLocal() as s:
        companies = s.execute(select(Company.id, Company.settings)).all()
    for company_id, settings in companies:
        days = int(((settings or {}).get("ai_policy") or {}).get("retain_ai_payloads_days", 0) or 0)
        cutoff = datetime.now(UTC) - timedelta(days=days)
        with tenant_session(company_id) as s:
            result = s.execute(update(AICall).where(
                AICall.company_id == company_id, AICall.created_at < cutoff,
                or_(AICall.request_redacted.is_not(None), AICall.response.is_not(None)),
            ).values(request_redacted=null(), response=null()))
            cleared += result.rowcount or 0
    if cleared:
        log.info("purged stored AI payloads of %d call(s)", cleared)
    return cleared

