"""Celery application (docs/architecture.md §5.6, D4).

Queues: `parse` (scanning, parsing, OCR, deterministic normalize/validate/match), `ai` (LLM calls,
bounded concurrency), `commit` (transactional writes). `celery beat` runs the watchdog.
Set CELERY_ALWAYS_EAGER=true to run everything in-process (tests, single-process development).
"""

from __future__ import annotations

from celery import Celery

from app.config import get_settings

settings = get_settings()

celery = Celery("biwork", broker=settings.broker_url, include=["app.agent.tasks"])
celery.conf.update(
    task_always_eager=settings.celery_always_eager,
    task_eager_propagates=False,
    task_acks_late=True,
    task_reject_on_worker_lost=True,
    worker_prefetch_multiplier=1,
    task_default_queue="default",
    task_routes={
        "agent.parse": {"queue": "parse"},
        "agent.prepare": {"queue": "parse"},
        "agent.interpret": {"queue": "ai"},
        "agent.commit": {"queue": "commit"},
        "agent.watchdog": {"queue": "default"},
    },
    beat_schedule={"agent-watchdog": {"task": "agent.watchdog", "schedule": 300.0}},
    broker_connection_retry_on_startup=True,
    task_ignore_result=True,
    timezone="UTC",
)
