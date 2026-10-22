"""Supply service: suppliers and purchase requests with the procurement approval workflow."""

from __future__ import annotations

import dataclasses
import uuid
from datetime import UTC, date, datetime
from decimal import Decimal
from typing import Any

from pydantic import BaseModel, Field, model_validator
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict, NotFound, PreconditionFailed, ValidationFailed
from app.core.manifest import WriteResult
from app.core.models import Department, Product, Task
from app.core.numbering import next_number
from app.core.services.master_data import require_unit
from app.core.services.notifications import notify_permission
from app.core.services.tasks import TaskIn, create_task
from app.core.text import clean_text, normalize_key
from app.modules.supply.models import PurchaseRequest, PurchaseRequestLine, Supplier, SupplierAlias

PR_TRANSITIONS = {
    "submitted": {"approved", "rejected", "cancelled"},
    "approved": {"ordered", "cancelled"},
    "ordered": {"closed"},
    "rejected": set(),
    "closed": set(),
    "cancelled": set(),
}
PRIORITIES = ("low", "normal", "high", "urgent")


# --------------------------------------------------------------------------- suppliers


class SupplierIn(BaseModel):
    code: str = Field(min_length=1, max_length=50)
    name: str = Field(min_length=1, max_length=250)
    tin: str | None = Field(default=None, max_length=32)
    contact_person: str | None = Field(default=None, max_length=200)
    phone: str | None = Field(default=None, max_length=50)
    email: str | None = Field(default=None, max_length=200)
    address: str | None = Field(default=None, max_length=1000)
    aliases: list[str] = Field(default_factory=list, max_length=30)


def create_supplier(session: Session, ctx: RequestContext, data: SupplierIn, source_record_id: uuid.UUID | None = None) -> Supplier:
    ctx.require("supply.write")
    if session.execute(select(Supplier.id).where(Supplier.company_id == ctx.company_id, Supplier.code == data.code)).first():
        raise Conflict(f"Supplier code '{data.code}' already exists")
    if data.tin and session.execute(select(Supplier.id).where(Supplier.company_id == ctx.company_id, Supplier.tin == data.tin)).first():
        raise Conflict(f"A supplier with TIN {data.tin} already exists", code="duplicate_tin")
    supplier = Supplier(company_id=ctx.company_id, code=data.code, name=clean_text(data.name, 250), tin=data.tin,
                        contact_person=clean_text(data.contact_person), phone=data.phone, email=data.email,
                        address=clean_text(data.address), created_by=ctx.user_id, updated_by=ctx.user_id,
                        source_record_id=source_record_id)
    session.add(supplier)
    session.flush()
    for alias in {normalize_key(a): a for a in [supplier.name, supplier.code, *data.aliases] if normalize_key(a)}.items():
        session.add(SupplierAlias(company_id=ctx.company_id, supplier_id=supplier.id, alias=alias[1], normalized_alias=alias[0]))
    audit.record(session, "supplier.created", ctx=ctx, entity_type="supplier", entity_id=supplier.id,
                 changes={"code": supplier.code, "name": supplier.name, "tin": supplier.tin})
    return supplier


def write_supplier_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    """Agent commit path. A supplier without a code gets the next SUP number."""
    p = data.model_dump()
    supplier = create_supplier(session, ctx, SupplierIn(
        code=p.get("code") or next_number(session, ctx.company_id, "SUP"), name=p["name"], tin=p.get("tin"),
        contact_person=p.get("contact_person"), phone=p.get("phone"), email=p.get("email"), address=p.get("address"),
    ), source_record_id=source_record_id)
    return WriteResult("supply.suppliers", supplier.id, supplier.code, f"{supplier.code} — {supplier.name}")


# --------------------------------------------------------------------------- purchase requests


class LineIn(BaseModel):
    description: str = Field(min_length=1, max_length=300)
    quantity: Decimal = Field(gt=0)
    unit: str
    product_id: uuid.UUID | None = None
    estimated_unit_price: Decimal | None = Field(default=None, ge=0)


class PurchaseRequestIn(BaseModel):
    request_date: date
    lines: list[LineIn] = Field(min_length=1, max_length=500)
    needed_by: date | None = None
    department_id: uuid.UUID | None = None
    requested_by: str | None = Field(default=None, max_length=200)
    supplier_id: uuid.UUID | None = None
    priority: str = "normal"
    currency: str | None = Field(default=None, min_length=3, max_length=3)
    external_number: str | None = Field(default=None, max_length=60)
    notes: str | None = Field(default=None, max_length=5000)

    @model_validator(mode="after")
    def _check(self) -> PurchaseRequestIn:
        if self.needed_by and self.needed_by < self.request_date:
            raise ValueError("needed_by must not be before request_date")
        if self.priority not in PRIORITIES:
            raise ValueError(f"priority must be one of {', '.join(PRIORITIES)}")
        return self


def pr_snapshot(pr: PurchaseRequest) -> dict[str, Any]:
    return {
        "number": pr.number, "external_number": pr.external_number, "request_date": pr.request_date, "needed_by": pr.needed_by,
        "department_id": pr.department_id, "requested_by": pr.requested_by_employee, "supplier_id": pr.supplier_id,
        "status": pr.status, "priority": pr.priority, "currency": pr.currency, "total_estimated": pr.total_estimated,
        "lines": [{"no": li.line_no, "description": li.description, "qty": li.qty, "unit_id": li.unit_id,
                   "product_id": li.product_id, "est_price": li.est_price} for li in pr.lines],
    }


def create_purchase_request(session: Session, ctx: RequestContext, data: PurchaseRequestIn, *,
                            source_record_id: uuid.UUID | None = None, source_file_id: uuid.UUID | None = None) -> PurchaseRequest:
    ctx.require("supply.write")
    if data.department_id:
        if not session.execute(select(Department.id).where(Department.id == data.department_id, Department.company_id == ctx.company_id)).first():
            raise ValidationFailed("Unknown department", extra={"field": "department"})
        ctx.require_department(data.department_id)
    if data.supplier_id and not session.execute(
        select(Supplier.id).where(Supplier.id == data.supplier_id, Supplier.company_id == ctx.company_id)
    ).first():
        raise ValidationFailed("Unknown supplier", extra={"field": "supplier"})
    if data.external_number and session.execute(select(PurchaseRequest.id).where(
        PurchaseRequest.company_id == ctx.company_id, PurchaseRequest.external_number == data.external_number,
        PurchaseRequest.status != "cancelled",
    )).first():
        raise Conflict(f"Purchase request '{data.external_number}' was already registered", code="duplicate_purchase_request")
    pr = PurchaseRequest(
        company_id=ctx.company_id, number=next_number(session, ctx.company_id, "PR", data.request_date),
        external_number=clean_text(data.external_number, 60), request_date=data.request_date, needed_by=data.needed_by,
        department_id=data.department_id, requested_by_employee=clean_text(data.requested_by, 200), supplier_id=data.supplier_id,
        status="submitted", priority=data.priority, currency=(data.currency or "").upper() or None,
        notes=clean_text(data.notes, 5000), source_file_id=source_file_id, created_by=ctx.user_id, updated_by=ctx.user_id,
        source_record_id=source_record_id,
    )
    total = Decimal(0)
    priced = False
    for i, line in enumerate(data.lines, start=1):
        unit = require_unit(session, line.unit)
        if line.product_id and not session.execute(
            select(Product.id).where(Product.id == line.product_id, Product.company_id == ctx.company_id)
        ).first():
            raise ValidationFailed(f"Line {i}: unknown product", extra={"field": f"lines.{i - 1}.product"})
        pr.lines.append(PurchaseRequestLine(
            company_id=ctx.company_id, line_no=i, product_id=line.product_id, description=clean_text(line.description, 300),
            qty=line.quantity, unit_id=unit.id, est_price=line.estimated_unit_price, currency=pr.currency,
        ))
        if line.estimated_unit_price is not None:
            total += line.estimated_unit_price * line.quantity
            priced = True
    pr.total_estimated = total if priced else None
    session.add(pr)
    session.flush()
    audit.record(session, "purchase_request.created", ctx=ctx, entity_type="purchase_request", entity_id=pr.id,
                 summary=f"{pr.number} submitted with {len(pr.lines)} line(s)", changes=pr_snapshot(pr))
    _route_for_approval(session, ctx, pr)
    return pr


def _route_for_approval(session: Session, ctx: RequestContext, pr: PurchaseRequest) -> None:
    """Procurement approval workflow: notify approvers and open an approval task."""
    link = f"/supply/purchase-requests/{pr.id}"
    notify_permission(session, ctx.company_id, "supply.approve", "purchase_request.submitted",
                      f"Purchase request {pr.number} awaits approval",
                      f"{len(pr.lines)} line(s), needed by {pr.needed_by or 'not specified'}", link, exclude=ctx.user_id)
    # The approval task is a workflow side effect of submitting the request, so it is created even
    # when the submitter cannot create tasks themselves (narrow, single-purpose grant).
    task_ctx = dataclasses.replace(ctx, permissions=ctx.permissions | {"tasks.write"})
    create_task(session, task_ctx, TaskIn(
        title=f"Review purchase request {pr.number}", description=pr.notes, due_date=pr.needed_by,
        priority="high" if pr.priority in ("high", "urgent") else "normal", ref_type="purchase_request", ref_id=pr.id,
        department_id=None,
    ))


def get_purchase_request(session: Session, ctx: RequestContext, pr_id: uuid.UUID) -> PurchaseRequest:
    pr = session.execute(
        select(PurchaseRequest).where(PurchaseRequest.id == pr_id, PurchaseRequest.company_id == ctx.company_id)
    ).unique().scalar_one_or_none()
    if pr is None or not ctx.can_access_department(pr.department_id):
        raise NotFound("Purchase request not found")
    return pr


def decide_purchase_request(session: Session, ctx: RequestContext, pr_id: uuid.UUID, status: str, comment: str | None,
                            expected_version: int | None = None) -> PurchaseRequest:
    ctx.require("supply.approve" if status in ("approved", "rejected") else "supply.write")
    pr = get_purchase_request(session, ctx, pr_id)
    if expected_version is not None and pr.version != expected_version:
        raise PreconditionFailed("Purchase request was modified by someone else; reload and try again")
    if status not in PR_TRANSITIONS[pr.status]:
        raise ValidationFailed(f"Cannot change status from '{pr.status}' to '{status}'", code="invalid_transition")
    before = pr.status
    pr.status = status
    if status in ("approved", "rejected"):
        pr.decided_by, pr.decided_at, pr.decision_comment = ctx.user_id, datetime.now(UTC), clean_text(comment, 2000)
    pr.updated_by = ctx.user_id
    session.flush()
    audit.record(session, f"purchase_request.{status}", ctx=ctx, entity_type="purchase_request", entity_id=pr.id,
                 changes={"status": {"from": before, "to": status}}, context={"comment": comment} if comment else None)
    # Close the approval task once a decision is made.
    if status in ("approved", "rejected", "cancelled"):
        for task in session.execute(select(Task).where(Task.company_id == ctx.company_id, Task.ref_type == "purchase_request",
                                                       Task.ref_id == pr.id, Task.status.in_(("open", "in_progress")))).scalars():
            task.status, task.completed_at = "done", datetime.now(UTC)
    return pr


def write_pr_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    """Agent commit path. Lines carry `item` (text as written) and `item_id` (matched product, if any)."""
    p = data.model_dump()
    pr = create_purchase_request(session, ctx, PurchaseRequestIn(
        request_date=p["request_date"], needed_by=p.get("needed_by"), department_id=p.get("department_id"),
        requested_by=p.get("requested_by"), supplier_id=p.get("supplier_id"), priority=p.get("priority") or "normal",
        currency=p.get("currency"), external_number=p.get("external_number"), notes=p.get("notes"),
        lines=[LineIn(description=li["item"], quantity=li["quantity"], unit=li["unit"], product_id=li.get("item_id"),
                      estimated_unit_price=li.get("estimated_unit_price")) for li in p["lines"]],
    ), source_record_id=source_record_id, source_file_id=p.get("source_file_id"))
    return WriteResult("supply.purchase_requests", pr.id, pr.number, f"{pr.number} — {len(pr.lines)} line(s), submitted for approval")
