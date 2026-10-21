"""Approval workflow configuration per record type (auto-approval is administrator opt-in)."""

from __future__ import annotations

from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.models import WorkflowDefinition


def get_workflow(session: Session, company_id, record_type: str) -> WorkflowDefinition | None:
    return session.execute(
        select(WorkflowDefinition).where(WorkflowDefinition.company_id == company_id, WorkflowDefinition.record_type == record_type)
    ).scalar_one_or_none()


def set_workflow(session: Session, ctx: RequestContext, record_type: str, *, auto_approve: bool,
                 confidence_threshold: Decimal | None = None) -> WorkflowDefinition:
    ctx.require("admin.company")
    wf = get_workflow(session, ctx.company_id, record_type)
    before = {"auto_approve": wf.auto_approve, "confidence_threshold": wf.confidence_threshold} if wf else {}
    if wf is None:
        wf = WorkflowDefinition(company_id=ctx.company_id, record_type=record_type, steps=[])
        session.add(wf)
    wf.auto_approve = auto_approve
    wf.confidence_threshold = confidence_threshold
    wf.updated_by = ctx.user_id
    session.flush()
    audit.record(session, "workflow.updated", ctx=ctx, entity_type="workflow_definition", entity_id=wf.id,
                 summary=f"Approval settings for {record_type} changed",
                 changes=audit.diff(before, {"auto_approve": auto_approve, "confidence_threshold": confidence_threshold}))
    return wf
