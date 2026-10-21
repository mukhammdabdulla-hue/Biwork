"""In-app notifications."""

from __future__ import annotations

import uuid
from datetime import UTC, datetime

from sqlalchemy import select, update
from sqlalchemy.orm import Session

from app.core.models import Membership, Notification, RolePermission


def notify(session: Session, company_id: uuid.UUID, user_id: uuid.UUID, kind: str, title: str,
           body: str | None = None, link: str | None = None) -> Notification:
    n = Notification(company_id=company_id, user_id=user_id, kind=kind, payload={"title": title, "body": body, "link": link})
    session.add(n)
    return n


def users_with_permission(session: Session, company_id: uuid.UUID, permission: str) -> list[uuid.UUID]:
    rows = session.execute(
        select(Membership.user_id)
        .join(RolePermission, RolePermission.role_id == Membership.role_id)
        .where(Membership.company_id == company_id, Membership.is_active.is_(True), RolePermission.permission == permission)
        .distinct()
    ).scalars()
    return list(rows)


def notify_permission(session: Session, company_id: uuid.UUID, permission: str, kind: str, title: str,
                      body: str | None = None, link: str | None = None, exclude: uuid.UUID | None = None) -> int:
    count = 0
    for user_id in users_with_permission(session, company_id, permission):
        if user_id != exclude:
            notify(session, company_id, user_id, kind, title, body, link)
            count += 1
    return count


def mark_read(session: Session, company_id: uuid.UUID, user_id: uuid.UUID, ids: list[uuid.UUID] | None) -> int:
    stmt = update(Notification).where(
        Notification.company_id == company_id, Notification.user_id == user_id, Notification.read_at.is_(None)
    )
    if ids:
        stmt = stmt.where(Notification.id.in_(ids))
    return session.execute(stmt.values(read_at=datetime.now(UTC))).rowcount or 0
