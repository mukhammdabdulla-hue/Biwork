"""Tasks and notifications."""

from __future__ import annotations

import uuid
from datetime import date
from typing import Any

from fastapi import APIRouter, Query, Response
from pydantic import BaseModel
from sqlalchemy import select

from app.core.api import DB, Ctx, Idem, IfMatch, etag_for, paginate
from app.core.models import Notification, Task, User
from app.core.services import notifications, tasks

router = APIRouter(tags=["tasks"])


def task_out(t: Task, names: dict[uuid.UUID, str] | None = None) -> dict[str, Any]:
    return {"id": str(t.id), "number": t.number, "title": t.title, "description": t.description,
            "assignee_id": str(t.assignee_id) if t.assignee_id else None,
            "assignee_name": (names or {}).get(t.assignee_id) if t.assignee_id else None,
            "department_id": str(t.department_id) if t.department_id else None, "due_date": t.due_date, "status": t.status,
            "priority": t.priority, "ref_type": t.ref_type, "ref_id": str(t.ref_id) if t.ref_id else None,
            "overdue": bool(t.due_date and t.due_date < date.today() and t.status in ("open", "in_progress")),
            "version": t.version, "created_at": t.created_at, "completed_at": t.completed_at,
            "source_record_id": str(t.source_record_id) if t.source_record_id else None}


@router.get("/tasks")
def list_tasks(ctx: Ctx, db: DB, status: str | None = None, mine: bool = False, overdue: bool = False,
               cursor: str | None = None, limit: int = Query(100, le=500)) -> dict[str, Any]:
    ctx.require("tasks.read")
    stmt = select(Task).where(Task.company_id == ctx.company_id)
    if status:
        stmt = stmt.where(Task.status.in_(status.split(",")))
    if mine:
        stmt = stmt.where(Task.assignee_id == ctx.user_id)
    if overdue:
        stmt = stmt.where(Task.due_date < date.today(), Task.status.in_(("open", "in_progress")))
    if ctx.department_ids is not None:
        stmt = stmt.where(Task.department_id.is_(None) | Task.department_id.in_(ctx.department_ids))
    rows, next_cursor = paginate(db, stmt, Task, cursor, limit)
    names = dict(db.execute(select(User.id, User.full_name).where(User.id.in_({t.assignee_id for t in rows if t.assignee_id}))).all())
    return {"items": [task_out(t, names) for t in rows], "next_cursor": next_cursor}


@router.post("/tasks", status_code=201)
def create_task(data: tasks.TaskIn, ctx: Ctx, db: DB, idem: Idem):
    replay = idem.replay(db, ctx, data.model_dump(mode="json"))
    if replay:
        return replay
    t = tasks.create_task(db, ctx, data)
    out = task_out(t)
    idem.store(db, ctx, 201, out)
    db.commit()
    return out


@router.patch("/tasks/{task_id}")
def update_task(task_id: uuid.UUID, data: tasks.TaskUpdate, ctx: Ctx, db: DB, expected: IfMatch, response: Response) -> dict[str, Any]:
    t = tasks.update_task(db, ctx, task_id, data, expected)
    db.commit()
    response.headers["ETag"] = etag_for(t.version)
    return task_out(t)


@router.get("/notifications")
def list_notifications(ctx: Ctx, db: DB, unread: bool = False, limit: int = Query(50, ge=1, le=200)) -> dict[str, Any]:
    stmt = select(Notification).where(Notification.company_id == ctx.company_id, Notification.user_id == ctx.user_id)
    if unread:
        stmt = stmt.where(Notification.read_at.is_(None))
    rows = db.execute(stmt.order_by(Notification.created_at.desc()).limit(limit)).scalars().all()
    unread_count = db.execute(select(Notification.id).where(Notification.company_id == ctx.company_id,
                                                            Notification.user_id == ctx.user_id,
                                                            Notification.read_at.is_(None))).all()
    return {"unread": len(unread_count), "items": [{"id": str(n.id), "kind": n.kind, **n.payload, "created_at": n.created_at,
                                                    "read": n.read_at is not None} for n in rows]}


class ReadIn(BaseModel):
    ids: list[uuid.UUID] | None = None


@router.post("/notifications/read")
def mark_read(data: ReadIn, ctx: Ctx, db: DB) -> dict[str, int]:
    n = notifications.mark_read(db, ctx.company_id, ctx.user_id, data.ids)
    db.commit()
    return {"marked": n}
