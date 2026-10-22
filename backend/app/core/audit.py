"""Append-only, hash-chained audit events (docs/architecture.md §4.6).

Each company has its own chain; platform-level events (e.g. failed logins) use the "platform"
chain. `verify_chain` recomputes hashes to detect tampering.
"""

from __future__ import annotations

import hashlib
import json
import uuid
from datetime import UTC, date, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import select, text
from sqlalchemy.orm import Session

from app.core.context import RequestContext
from app.core.models import AuditChainHead, AuditEvent


def _json_default(value: Any) -> Any:
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if isinstance(value, (uuid.UUID, Decimal)):
        return str(value)
    return str(value)


def canonical(value: Any) -> Any:
    """JSON-safe copy with stable types, used both for storage and hashing."""
    return json.loads(json.dumps(value, default=_json_default, sort_keys=True)) if value is not None else None


def _event_hash(event: AuditEvent) -> str:
    material = {
        "company_id": str(event.company_id) if event.company_id else None,
        "occurred_at": event.occurred_at.isoformat(),
        "actor_type": event.actor_type,
        "actor_id": str(event.actor_id) if event.actor_id else None,
        "action": event.action,
        "entity_type": event.entity_type,
        "entity_id": event.entity_id,
        "summary": event.summary,
        "changes": event.changes,
        "context": event.context,
        "prev_hash": event.prev_hash,
    }
    return hashlib.sha256(json.dumps(material, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def record(
    session: Session,
    action: str,
    *,
    ctx: RequestContext | None = None,
    company_id: uuid.UUID | None = None,
    actor_type: str | None = None,
    actor_id: uuid.UUID | None = None,
    entity_type: str | None = None,
    entity_id: Any = None,
    summary: str | None = None,
    changes: dict[str, Any] | None = None,
    context: dict[str, Any] | None = None,
) -> AuditEvent:
    """Append an audit event in the caller's transaction (it commits or rolls back with the change)."""
    if ctx is not None:
        company_id = company_id or ctx.company_id
        actor_type = actor_type or ctx.actor_type
        actor_id = actor_id or ctx.actor_id
        context = {"request_id": ctx.request_id, "ip": ctx.ip_address, **ctx.provenance, **(context or {})}
    chain_key = str(company_id) if company_id else "platform"

    session.execute(
        text(
            "INSERT INTO core.audit_chain_heads (chain_key, last_event_id, last_hash) "
            "VALUES (:k, 0, '') ON CONFLICT (chain_key) DO NOTHING"
        ),
        {"k": chain_key},
    )
    head = session.execute(
        select(AuditChainHead).where(AuditChainHead.chain_key == chain_key).with_for_update()
    ).scalar_one()

    # Pre-allocate the id: an INSERT ... RETURNING would be checked against the read policy, which
    # (by design) hides platform-level events (company_id NULL) from every tenant.
    event_id = session.execute(text("SELECT nextval(pg_get_serial_sequence('core.audit_events', 'id'))")).scalar_one()
    event = AuditEvent(
        id=event_id,
        occurred_at=datetime.now(UTC),
        company_id=company_id,
        actor_type=actor_type or "system",
        actor_id=actor_id,
        action=action,
        entity_type=entity_type,
        entity_id=str(entity_id) if entity_id is not None else None,
        summary=summary,
        changes=canonical(changes),
        context=canonical({k: v for k, v in (context or {}).items() if v is not None}),
        prev_hash=head.last_hash or None,
    )
    event.hash = _event_hash(event)
    session.add(event)
    session.flush()
    head.last_event_id = event.id
    head.last_hash = event.hash
    return event


def verify_chain(session: Session, company_id: uuid.UUID) -> dict[str, Any]:
    """Recompute the company's chain. Returns {ok, checked, broken_at}."""
    events = session.execute(
        select(AuditEvent).where(AuditEvent.company_id == company_id).order_by(AuditEvent.id)
    ).scalars()
    prev = None
    checked = 0
    for event in events:
        if event.prev_hash != prev or _event_hash(event) != event.hash:
            return {"ok": False, "checked": checked, "broken_at": event.id}
        prev = event.hash
        checked += 1
    return {"ok": True, "checked": checked, "broken_at": None}


def diff(before: dict[str, Any], after: dict[str, Any]) -> dict[str, Any]:
    """Field-level before/after for changed keys."""
    before, after = canonical(before) or {}, canonical(after) or {}
    return {
        key: {"from": before.get(key), "to": after.get(key)}
        for key in sorted(set(before) | set(after))
        if before.get(key) != after.get(key)
    }
