"""Shared pipeline plumbing: stage-run bookkeeping, job updates, progress events."""

from __future__ import annotations

import json
import logging
import time
import uuid
from collections.abc import Iterator
from contextlib import contextmanager
from datetime import UTC, datetime
from typing import Any

from sqlalchemy import func, select

from app.agent.models import IngestionJob, StageRun
from app.config import get_settings
from app.db.session import tenant_session

log = logging.getLogger("biwork.agent")

STAGES = ("ingest", "parse", "interpret", "normalize", "validate", "match", "review", "commit", "report")


class StageError(Exception):
    """A permanent, user-visible stage failure (bad input, policy, unreadable file)."""


@contextmanager
def stage_run(company_id: uuid.UUID, job_id: uuid.UUID, stage: str, item_id: uuid.UUID | None = None,
              metrics: dict[str, Any] | None = None) -> Iterator[dict[str, Any]]:
    """Record one attempt of a stage in its own transaction, so failures stay observable even when
    the stage's own transaction rolls back."""
    with tenant_session(company_id) as s:
        attempt = (s.execute(select(func.count()).select_from(StageRun).where(
            StageRun.job_id == job_id, StageRun.stage == stage,
            StageRun.item_id.is_(None) if item_id is None else StageRun.item_id == item_id,
        )).scalar_one() or 0) + 1
        run = StageRun(company_id=company_id, job_id=job_id, item_id=item_id, stage=stage, attempt=attempt, status="running")
        s.add(run)
        s.flush()
        run_id = run.id
        s.execute(IngestionJob.__table__.update().where(IngestionJob.id == job_id).values(current_stage=stage))
    started = time.monotonic()
    out: dict[str, Any] = dict(metrics or {})
    try:
        yield out
    except Exception as exc:
        _finish(company_id, run_id, "failed", started, out, f"{exc.__class__.__name__}: {exc}")
        raise
    _finish(company_id, run_id, "succeeded", started, out, None)
    publish(company_id, job_id, {"stage": stage, "status": "succeeded"})


def _finish(company_id: uuid.UUID, run_id: uuid.UUID, status: str, started: float, metrics: dict[str, Any], error: str | None) -> None:
    with tenant_session(company_id) as s:
        run = s.get(StageRun, run_id)
        run.status = status
        run.finished_at = datetime.now(UTC)
        run.duration_ms = int((time.monotonic() - started) * 1000)
        run.metrics = json.loads(json.dumps(metrics, default=str))
        run.error = error[:4000] if error else None


def set_job(company_id: uuid.UUID, job_id: uuid.UUID, **values: Any) -> None:
    with tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        for k, v in values.items():
            setattr(job, k, v)
    publish(company_id, job_id, {k: v for k, v in values.items() if k in ("status", "current_stage")})


def fail_job(company_id: uuid.UUID, job_id: uuid.UUID, stage: str, exc: BaseException, retryable: bool) -> None:
    log.warning("job %s failed at %s: %s", job_id, stage, exc)
    set_job(company_id, job_id, status="failed", current_stage=stage, finished_at=datetime.now(UTC),
            error={"stage": stage, "type": exc.__class__.__name__, "message": str(exc)[:2000], "retryable": retryable,
                   "at": datetime.now(UTC).isoformat()})


def publish(company_id: uuid.UUID, job_id: uuid.UUID, event: dict[str, Any]) -> None:
    """Push a progress event (Redis pub/sub -> SSE). Best effort: polling is the fallback."""
    url = get_settings().redis_url
    if not url:
        return
    try:
        import redis

        redis.Redis.from_url(url, socket_timeout=1).publish(
            f"biwork:events:{company_id}", json.dumps({"type": "job", "job_id": str(job_id), **event}, default=str)
        )
    except Exception:
        pass
