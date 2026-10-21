"""Celery task wrappers around the pipeline stages.

Transient errors (AI rate limits/timeouts, scanner or database hiccups) are retried with
exponential backoff; anything else marks the job failed with a visible reason, ready for a
controlled retry from the last successful stage.
"""

from __future__ import annotations

import logging
import uuid

from sqlalchemy.exc import OperationalError

from app.agent.pipeline import orchestrator
from app.agent.pipeline.context import fail_job
from app.ai.providers.base import TransientAIError
from app.config import get_settings
from app.core.errors import AppError
from app.core.services.files import ScanUnavailable
from app.workers.celery_app import celery

log = logging.getLogger("biwork.agent")
TRANSIENT = (TransientAIError, ScanUnavailable, OperationalError, ConnectionError, TimeoutError)
MAX_RETRIES = 5


def _backoff(retries: int, exc: BaseException) -> float:
    hint = getattr(exc, "retry_after", None)
    return float(hint) if hint else min(300.0, 5.0 * (2 ** retries))


def _run(task, stage: str, company_id: str, job_id: str, fn, *args):
    cid, jid = uuid.UUID(company_id), uuid.UUID(job_id)
    try:
        return fn(cid, jid, *args)
    except TRANSIENT as exc:
        if task.request.retries >= MAX_RETRIES or get_settings().celery_always_eager:
            fail_job(cid, jid, stage, exc, retryable=True)
            return None
        log.info("transient error in %s for job %s, retrying: %s", stage, job_id, exc)
        raise task.retry(exc=exc, countdown=_backoff(task.request.retries, exc)) from exc
    except AppError:
        return None  # already recorded on the job by the stage (business-level refusal)
    except Exception as exc:
        log.exception("stage %s failed for job %s", stage, job_id)
        fail_job(cid, jid, stage, exc, retryable=True)
        return None


@celery.task(name="agent.parse", bind=True, max_retries=MAX_RETRIES)
def parse(self, company_id: str, job_id: str) -> None:
    if _run(self, "parse", company_id, job_id, orchestrator.run_parse):
        interpret.apply_async(args=[company_id, job_id])


@celery.task(name="agent.interpret", bind=True, max_retries=MAX_RETRIES)
def interpret(self, company_id: str, job_id: str) -> None:
    if _run(self, "interpret", company_id, job_id, orchestrator.run_interpret):
        prepare.apply_async(args=[company_id, job_id])


@celery.task(name="agent.prepare", bind=True, max_retries=MAX_RETRIES)
def prepare(self, company_id: str, job_id: str) -> None:
    result = _run(self, "match", company_id, job_id, orchestrator.run_prepare)
    if result and result.get("auto_approved"):
        commit.apply_async(args=[company_id, job_id, result["auto_approved"]])


@celery.task(name="agent.commit", bind=True, max_retries=MAX_RETRIES)
def commit(self, company_id: str, job_id: str, record_ids: list[str] | None = None) -> None:
    ids = [uuid.UUID(r) for r in record_ids] if record_ids else None
    _run(self, "commit", company_id, job_id, orchestrator.run_commit, ids)


@celery.task(name="agent.watchdog")
def watchdog() -> None:
    for company_id, job_id in orchestrator.watchdog(get_settings().stage_timeout_seconds):
        enqueue_process(company_id, job_id)


def enqueue_process(company_id: uuid.UUID, job_id: uuid.UUID) -> None:
    parse.apply_async(args=[str(company_id), str(job_id)])


def enqueue_commit(company_id: uuid.UUID, job_id: uuid.UUID, record_ids: list[uuid.UUID] | None = None) -> None:
    commit.apply_async(args=[str(company_id), str(job_id), [str(r) for r in record_ids] if record_ids else None])
