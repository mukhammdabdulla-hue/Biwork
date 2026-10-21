"""Engine, sessions, and the row-level-security tenant context (docs/architecture.md §4.7)."""

from __future__ import annotations

import uuid
from collections.abc import Callable, Iterator
from contextlib import contextmanager

from sqlalchemy import create_engine, event, text
from sqlalchemy.orm import Session, sessionmaker

from app.config import get_settings

_settings = get_settings()

engine = create_engine(_settings.database_url, pool_size=_settings.db_pool_size, pool_pre_ping=True)
SessionLocal = sessionmaker(bind=engine, autoflush=False, expire_on_commit=False)


@event.listens_for(Session, "after_begin")
def _apply_tenant_context(session: Session, transaction, connection) -> None:
    """`SET LOCAL app.company_id` at the start of every transaction.

    The value comes only from an authenticated, membership-verified context (or a worker task
    that carries the company id it was enqueued with) — never from raw request input.
    """
    company_id = session.info.get("company_id")
    user_id = session.info.get("user_id")
    if company_id is not None:
        connection.execute(text("SELECT set_config('app.company_id', :v, true)"), {"v": str(company_id)})
    if user_id is not None:
        connection.execute(text("SELECT set_config('app.user_id', :v, true)"), {"v": str(user_id)})


def bind_tenant(session: Session, company_id: uuid.UUID | None, user_id: uuid.UUID | None = None) -> None:
    """Scope a session to a company. Applies immediately if a transaction is already open."""
    session.info["company_id"] = company_id
    session.info["user_id"] = user_id
    if session.in_transaction():
        conn = session.connection()
        conn.execute(text("SELECT set_config('app.company_id', :v, true)"), {"v": str(company_id or "")})
        if user_id is not None:
            conn.execute(text("SELECT set_config('app.user_id', :v, true)"), {"v": str(user_id)})


def get_db() -> Iterator[Session]:
    session = SessionLocal()
    try:
        yield session
    finally:
        session.close()


@contextmanager
def tenant_session(company_id: uuid.UUID, user_id: uuid.UUID | None = None) -> Iterator[Session]:
    """Session for background work, committed on success and rolled back on error."""
    session = SessionLocal()
    bind_tenant(session, company_id, user_id)
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


# --------------------------------------------------------------------------- post-commit hooks

_AFTER_COMMIT_KEY = "after_commit_callbacks"


def after_commit(session: Session, fn: Callable[[], None]) -> None:
    """Run `fn` only once the current transaction has committed (e.g. enqueue a Celery task).

    Enqueuing after commit guarantees a worker never picks up a job whose rows are not visible yet.
    """
    session.info.setdefault(_AFTER_COMMIT_KEY, []).append(fn)


@event.listens_for(Session, "after_commit")
def _run_after_commit(session: Session) -> None:
    callbacks = session.info.pop(_AFTER_COMMIT_KEY, [])
    for fn in callbacks:
        fn()


@event.listens_for(Session, "after_rollback")
def _drop_after_commit(session: Session) -> None:
    session.info.pop(_AFTER_COMMIT_KEY, None)
