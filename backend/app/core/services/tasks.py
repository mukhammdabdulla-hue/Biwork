"""Tasks (core): assignments with due dates; overdue tasks feed the executive dashboard."""

from __future__ import annotations

import uuid
from datetime import UTC, date, datetime
from typing import Any

from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.errors import NotFound, PreconditionFailed, ValidationFailed
from app.core.manifest import WriteResult
from app.core.matching import ListResolver, register_resolver
from app.core.models import Department, Membership, Task, User
from app.core.numbering import next_number
from app.core.services.master_data import check_reference
from app.core.services.notifications import notify
from app.core.text import clean_text

TASK_STATUSES = ("open", "in_progress", "done", "cancelled")
PRIORITIES = ("low", "normal", "high", "urgent")


class TaskIn(BaseModel):
    title: str = Field(min_length=1, max_length=300)
    description: str | None = Field(default=None, max_length=5000)
    assignee_id: uuid.UUID | None = None
    department_id: uuid.UUID | None = None
    due_date: date | None = None
    priority: str = "normal"
    ref_type: str | None = None
    ref_id: uuid.UUID | None = None


class TaskUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=300)
    description: str | None = Field(default=None, max_length=5000)
    assignee_id: uuid.UUID | None = None
    due_date: date | None = None
    priority: str | None = None
    status: str | None = None


def task_snapshot(t: Task) -> dict[str, Any]:
    return {k: getattr(t, k) for k in ("title", "description", "assignee_id", "department_id", "due_date", "priority", "status")}


def _check_assignee(session: Session, company_id: uuid.UUID, user_id: uuid.UUID | None) -> None:
    if user_id and not session.execute(
        select(Membership.id).where(Membership.company_id == company_id, Membership.user_id == user_id, Membership.is_active.is_(True))
    ).first():
        raise ValidationFailed("Assignee is not a member of this company", extra={"field": "assignee_id"})


def create_task(session: Session, ctx: RequestContext, data: TaskIn, source_record_id: uuid.UUID | None = None) -> Task:
    ctx.require("tasks.write")
    if data.priority not in PRIORITIES:
        raise ValidationFailed(f"priority must be one of {', '.join(PRIORITIES)}")
    _check_assignee(session, ctx.company_id, data.assignee_id)
    check_reference(session, ctx, Department, data.department_id, "department", "department_id")
    ctx.require_department(data.department_id)
    task = Task(
        company_id=ctx.company_id, number=next_number(session, ctx.company_id, "T"), title=clean_text(data.title, 300),
        description=clean_text(data.description, 5000), assignee_id=data.assignee_id, department_id=data.department_id,
        due_date=data.due_date, priority=data.priority, ref_type=data.ref_type, ref_id=data.ref_id,
        created_by=ctx.user_id, updated_by=ctx.user_id, source_record_id=source_record_id,
    )
    session.add(task)
    session.flush()
    audit.record(session, "task.created", ctx=ctx, entity_type="task", entity_id=task.id,
                 summary=f"Task {task.number}: {task.title}", changes=task_snapshot(task))
    if task.assignee_id and task.assignee_id != ctx.user_id:
        notify(session, ctx.company_id, task.assignee_id, "task.assigned", f"New task {task.number}", task.title, f"/tasks?task={task.id}")
    return task


def get_task(session: Session, ctx: RequestContext, task_id: uuid.UUID) -> Task:
    task = session.execute(select(Task).where(Task.id == task_id, Task.company_id == ctx.company_id)).scalar_one_or_none()
    if task is None or not ctx.can_access_department(task.department_id):
        raise NotFound("Task not found")
    return task


def update_task(session: Session, ctx: RequestContext, task_id: uuid.UUID, data: TaskUpdate, expected_version: int | None = None) -> Task:
    ctx.require("tasks.write")
    task = get_task(session, ctx, task_id)
    if expected_version is not None and task.version != expected_version:
        raise PreconditionFailed("Task was modified by someone else; reload and try again")
    before = task_snapshot(task)
    values = data.model_dump(exclude_unset=True)
    if "status" in values and values["status"] not in TASK_STATUSES:
        raise ValidationFailed(f"status must be one of {', '.join(TASK_STATUSES)}")
    if "priority" in values and values["priority"] not in PRIORITIES:
        raise ValidationFailed(f"priority must be one of {', '.join(PRIORITIES)}")
    if "assignee_id" in values:
        _check_assignee(session, ctx.company_id, values["assignee_id"])
    for key, value in values.items():
        setattr(task, key, clean_text(value) if key in ("title", "description") else value)
    if task.status != before["status"]:  # completing stamps the time; reopening or cancelling clears it
        task.completed_at = datetime.now(UTC) if task.status == "done" else None
    task.updated_by = ctx.user_id
    session.flush()
    changes = audit.diff(before, task_snapshot(task))
    if changes:
        audit.record(session, "task.updated", ctx=ctx, entity_type="task", entity_id=task.id, changes=changes,
                     summary=f"Task {task.number} updated")
    return task


def write_task_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    p = data.model_dump()
    task = create_task(session, ctx, TaskIn(
        title=p["title"], description=p.get("description"), assignee_id=p.get("assignee_id"),
        department_id=p.get("department_id"), due_date=p.get("due_date"), priority=p.get("priority") or "normal",
    ), source_record_id=source_record_id)
    return WriteResult("core.tasks", task.id, task.number, f"{task.number} — {task.title}")


def _company_users(session: Session, company_id: uuid.UUID) -> list[User]:
    return list(session.execute(
        select(User).join(Membership, Membership.user_id == User.id)
        .where(Membership.company_id == company_id, Membership.is_active.is_(True), User.is_active.is_(True)).distinct()
    ).scalars())


register_resolver(ListResolver(
    entity_type="user", load=_company_users, label=lambda u: f"{u.full_name} <{u.email}>",
    keys=lambda u: [u.email, u.full_name, u.email.split("@")[0]], permissions=("tasks.read", "admin.users"),
))
