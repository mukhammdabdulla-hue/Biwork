"""Production service — the only write path for production data (docs/architecture.md §5.2).

Corrections never edit an approved report in place: a new report supersedes the old one, the
old one is cancelled, and its stock postings are reversed by compensating movements (§4.5).
"""

from __future__ import annotations

import uuid
from datetime import UTC, date, datetime, time
from decimal import Decimal
from typing import Any

from pydantic import BaseModel, Field, model_validator
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict, NotFound, PreconditionFailed, ValidationFailed
from app.core.manifest import WriteResult
from app.core.models import Company, Department, Product, Unit
from app.core.numbering import next_number
from app.core.services.master_data import check_reference, convert, require_unit
from app.core.text import clean_text, fmt_qty
from app.modules.inventory import service as inventory
from app.modules.production.models import ProductionLine, ProductionOrder, ProductionReport, Shift

ORDER_STATUSES = ("planned", "released", "in_progress", "completed", "cancelled")
ORDER_TRANSITIONS = {
    "planned": {"released", "in_progress", "cancelled"},
    "released": {"in_progress", "cancelled", "planned"},
    "in_progress": {"completed", "cancelled"},
    "completed": set(),
    "cancelled": set(),
}
PRIORITIES = ("low", "normal", "high", "urgent")

# --------------------------------------------------------------------------- reference data


class ShiftIn(BaseModel):
    code: str = Field(min_length=1, max_length=20)
    name: str = Field(min_length=1, max_length=100)
    starts_at: time | None = None
    ends_at: time | None = None


def create_shift(session: Session, ctx: RequestContext, data: ShiftIn) -> Shift:
    ctx.require("production.write", "master.write")
    if session.execute(select(Shift.id).where(Shift.company_id == ctx.company_id, Shift.code == data.code)).first():
        raise Conflict(f"Shift '{data.code}' already exists")
    shift = Shift(company_id=ctx.company_id, **data.model_dump())
    session.add(shift)
    session.flush()
    audit.record(session, "shift.created", ctx=ctx, entity_type="shift", entity_id=shift.id,
                 summary=f"Shift {shift.code} — {shift.name} created", changes=data.model_dump())
    return shift


class LineIn(BaseModel):
    code: str = Field(min_length=1, max_length=50)
    name: str = Field(min_length=1, max_length=200)
    department_id: uuid.UUID
    capacity_per_shift: Decimal | None = Field(default=None, ge=0)
    unit: str | None = None


def create_line(session: Session, ctx: RequestContext, data: LineIn) -> ProductionLine:
    ctx.require("production.write", "master.write")
    _workshop(session, ctx, data.department_id)
    unit = require_unit(session, data.unit) if data.unit else None
    line = ProductionLine(company_id=ctx.company_id, code=data.code, name=clean_text(data.name), department_id=data.department_id,
                          capacity_per_shift=data.capacity_per_shift, unit_id=unit.id if unit else None)
    session.add(line)
    session.flush()
    audit.record(session, "production_line.created", ctx=ctx, entity_type="production_line", entity_id=line.id,
                 summary=f"Production line {line.code} — {line.name} created",
                 changes={"code": line.code, "name": line.name})
    return line


# --------------------------------------------------------------------------- shared checks


def _product(session: Session, ctx: RequestContext, product_id: uuid.UUID) -> Product:
    product = session.execute(
        select(Product).where(Product.id == product_id, Product.company_id == ctx.company_id, Product.deleted_at.is_(None))
    ).scalar_one_or_none()
    if product is None or not product.is_active:
        raise ValidationFailed("Unknown or inactive product", extra={"field": "product"})
    return product


def _workshop(session: Session, ctx: RequestContext, department_id: uuid.UUID) -> Department:
    dep = session.execute(
        select(Department).where(Department.id == department_id, Department.company_id == ctx.company_id)
    ).scalar_one_or_none()
    if dep is None or not dep.is_active:
        raise ValidationFailed("Unknown production unit", extra={"field": "department"})
    if dep.kind != "workshop":
        raise ValidationFailed(f"'{dep.name}' is not a production unit (workshop)", extra={"field": "department"})
    ctx.require_department(dep.id)
    return dep


def _unit_for(session: Session, product: Product, unit_code: str | None) -> Unit:
    unit = require_unit(session, unit_code) if unit_code else product.base_unit
    if convert(Decimal(1), unit, product.base_unit) is None:
        raise ValidationFailed(
            f"Unit '{unit.code}' is not compatible with {product.code} (base unit '{product.base_unit.code}')",
            code="unit_incompatible", extra={"field": "unit"},
        )
    return unit


# --------------------------------------------------------------------------- production orders


class OrderIn(BaseModel):
    product_id: uuid.UUID
    planned_qty: Decimal = Field(gt=0)
    unit: str | None = None
    department_id: uuid.UUID | None = None
    line_id: uuid.UUID | None = None
    planned_start: date | None = None
    planned_end: date | None = None
    priority: str = "normal"
    external_number: str | None = Field(default=None, max_length=60)
    notes: str | None = Field(default=None, max_length=2000)

    @model_validator(mode="after")
    def _dates(self) -> OrderIn:
        if self.planned_start and self.planned_end and self.planned_end < self.planned_start:
            raise ValueError("planned_end must not be before planned_start")
        if self.priority not in PRIORITIES:
            raise ValueError(f"priority must be one of {', '.join(PRIORITIES)}")
        return self


def order_snapshot(o: ProductionOrder) -> dict[str, Any]:
    return {k: getattr(o, k) for k in ("product_id", "planned_qty", "unit_id", "department_id", "line_id", "planned_start",
                                       "planned_end", "status", "priority", "external_number", "notes")}


def create_order(session: Session, ctx: RequestContext, data: OrderIn, source_record_id: uuid.UUID | None = None) -> ProductionOrder:
    ctx.require("production.write")
    product = _product(session, ctx, data.product_id)
    unit = _unit_for(session, product, data.unit)
    if data.department_id:
        _workshop(session, ctx, data.department_id)
    check_reference(session, ctx, ProductionLine, data.line_id, "production line", "line")
    if data.external_number and session.execute(
        select(ProductionOrder.id).where(ProductionOrder.company_id == ctx.company_id,
                                         ProductionOrder.external_number == data.external_number,
                                         ProductionOrder.status != "cancelled")
    ).first():
        raise Conflict(f"An order with external number '{data.external_number}' already exists", code="duplicate_order")
    order = ProductionOrder(
        company_id=ctx.company_id, number=next_number(session, ctx.company_id, "MO", data.planned_start),
        external_number=clean_text(data.external_number, 60), product_id=product.id, department_id=data.department_id,
        line_id=data.line_id, planned_qty=data.planned_qty, unit_id=unit.id, planned_start=data.planned_start,
        planned_end=data.planned_end, priority=data.priority, notes=clean_text(data.notes, 2000),
        created_by=ctx.user_id, updated_by=ctx.user_id, source_record_id=source_record_id,
    )
    session.add(order)
    session.flush()
    audit.record(session, "production_order.created", ctx=ctx, entity_type="production_order", entity_id=order.id,
                 summary=f"Order {order.number}: {data.planned_qty} {unit.code} of {product.code}", changes=order_snapshot(order))
    return order


def get_order(session: Session, ctx: RequestContext, order_id: uuid.UUID) -> ProductionOrder:
    order = session.execute(
        select(ProductionOrder).where(ProductionOrder.id == order_id, ProductionOrder.company_id == ctx.company_id)
    ).scalar_one_or_none()
    if order is None or not ctx.can_access_department(order.department_id):
        raise NotFound("Production order not found")
    return order


def set_order_status(session: Session, ctx: RequestContext, order_id: uuid.UUID, status: str,
                     expected_version: int | None = None) -> ProductionOrder:
    ctx.require("production.write")
    order = get_order(session, ctx, order_id)
    if expected_version is not None and order.version != expected_version:
        raise PreconditionFailed("Order was modified by someone else; reload and try again")
    if status not in ORDER_TRANSITIONS[order.status]:
        raise ValidationFailed(f"Cannot change order status from '{order.status}' to '{status}'", code="invalid_transition")
    before = order.status
    order.status = status
    order.updated_by = ctx.user_id
    session.flush()
    audit.record(session, "production_order.status_changed", ctx=ctx, entity_type="production_order", entity_id=order.id,
                 summary=f"Production order {order.number} status changed from {before} to {status}",
                 changes={"status": {"from": before, "to": status}})
    return order


def write_order_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    p = data.model_dump()
    order = create_order(session, ctx, OrderIn(
        product_id=p["product_id"], planned_qty=p["planned_qty"], unit=p.get("unit"), department_id=p.get("department_id"),
        line_id=p.get("line_id"), planned_start=p.get("planned_start"), planned_end=p.get("planned_end"),
        priority=p.get("priority") or "normal", external_number=p.get("external_number"), notes=p.get("notes"),
    ), source_record_id=source_record_id)
    return WriteResult("production.production_orders", order.id, order.number,
                       f"{order.number} — {fmt_qty(order.planned_qty)} {order.unit.code} {order.product.code}")


# --------------------------------------------------------------------------- production reports


class ReportIn(BaseModel):
    production_date: date
    product_id: uuid.UUID
    department_id: uuid.UUID
    qty_produced: Decimal = Field(ge=0)
    qty_accepted: Decimal | None = Field(default=None, ge=0)
    qty_defective: Decimal | None = Field(default=None, ge=0)
    unit: str | None = None
    shift_id: uuid.UUID | None = None
    line_id: uuid.UUID | None = None
    order_id: uuid.UUID | None = None
    notes: str | None = Field(default=None, max_length=2000)

    @model_validator(mode="after")
    def _reconcile(self) -> ReportIn:
        if (self.qty_accepted or 0) + (self.qty_defective or 0) > self.qty_produced:
            raise ValueError("accepted + defective quantities exceed the produced quantity")
        return self


def report_snapshot(r: ProductionReport) -> dict[str, Any]:
    return {k: getattr(r, k) for k in ("number", "production_date", "product_id", "department_id", "shift_id", "line_id",
                                       "order_id", "qty_produced", "qty_accepted", "qty_defective", "unit_id", "status",
                                       "supersedes_id", "notes")}


def find_live_report(session: Session, company_id: uuid.UUID, production_date: date, product_id: uuid.UUID,
                     department_id: uuid.UUID, shift_id: uuid.UUID | None, exclude: uuid.UUID | None = None) -> ProductionReport | None:
    stmt = select(ProductionReport).where(
        ProductionReport.company_id == company_id, ProductionReport.production_date == production_date,
        ProductionReport.product_id == product_id, ProductionReport.department_id == department_id,
        ProductionReport.shift_id.is_(None) if shift_id is None else ProductionReport.shift_id == shift_id,
        ProductionReport.status != "cancelled",
    )
    if exclude:
        stmt = stmt.where(ProductionReport.id != exclude)
    return session.execute(stmt).scalars().first()


def _check_report_refs(session: Session, ctx: RequestContext, data: ReportIn) -> tuple[Product, Unit]:
    product = _product(session, ctx, data.product_id)
    _workshop(session, ctx, data.department_id)
    unit = _unit_for(session, product, data.unit)
    if data.shift_id and not session.execute(select(Shift.id).where(Shift.id == data.shift_id, Shift.company_id == ctx.company_id)).first():
        raise ValidationFailed("Unknown shift", extra={"field": "shift"})
    if data.line_id and not session.execute(
        select(ProductionLine.id).where(ProductionLine.id == data.line_id, ProductionLine.company_id == ctx.company_id)
    ).first():
        raise ValidationFailed("Unknown production line", extra={"field": "line"})
    if data.order_id:
        order = get_order(session, ctx, data.order_id)
        if order.product_id != product.id:
            raise ValidationFailed("The production order is for a different product", extra={"field": "order"})
        if order.status == "cancelled":
            raise ValidationFailed("The production order is cancelled", extra={"field": "order"})
    return product, unit


def _output_qty(report: ProductionReport) -> Decimal:
    if report.qty_accepted is not None:
        return report.qty_accepted
    return report.qty_produced - (report.qty_defective or Decimal(0))


def _post_output(session: Session, ctx: RequestContext, report: ProductionReport) -> None:
    """Approved output goes to the configured finished-goods warehouse (Production -> Inventory).
    Without one nothing is posted; `stock_warnings` tells the user so."""
    company = session.get(Company, ctx.company_id)
    warehouse_id = (company.settings or {}).get("production_output_warehouse_id")
    qty = _output_qty(report)
    if not warehouse_id or qty <= 0:
        return
    inventory.post_movement(session, ctx, inventory.MovementIn(
        movement_date=report.production_date, movement_type="production_output", warehouse_id=uuid.UUID(warehouse_id),
        product_id=report.product_id, quantity=qty, unit=report.unit.code, reference=report.number,
    ), ref_type="production_report", ref_id=report.id, permission="production.approve")


def _reverse_output(session: Session, ctx: RequestContext, report: ProductionReport, reason: str) -> None:
    movements = inventory.movements_for_ref(session, ctx.company_id, "production_report", report.id)
    reversed_ids = {m.reverses_id for m in movements if m.reverses_id}
    for movement in movements:
        if movement.reverses_id is None and movement.id not in reversed_ids:
            inventory.reverse_movement(session, ctx, movement.id, reason, permission="production.approve",
                                       ref_type="production_report", ref_id=report.id)


def stock_warnings(session: Session, ctx: RequestContext, report: ProductionReport) -> list[dict[str, str]]:
    """Why an approved report's output is not in stock (returned with the report, never silent)."""
    if report.status != "approved" or _output_qty(report) <= 0:
        return []
    if inventory.movements_for_ref(session, ctx.company_id, "production_report", report.id):
        return []
    return [{"code": "output_not_posted",
             "message": "The output was not added to stock: no finished-goods warehouse was set in the company settings "
                        "when the report was approved"}]


def create_report(session: Session, ctx: RequestContext, data: ReportIn, *, approve: bool = False,
                  source_record_id: uuid.UUID | None = None, supersedes_id: uuid.UUID | None = None) -> ProductionReport:
    if supersedes_id is None:  # a correction is an approver's action (correct_report) and needs no write permission
        ctx.require("production.write")
    if approve:
        ctx.require("production.approve")
    product, unit = _check_report_refs(session, ctx, data)
    existing = find_live_report(session, ctx.company_id, data.production_date, product.id, data.department_id, data.shift_id)
    if existing is not None:
        raise Conflict(
            f"A report for this date, product, production unit and shift already exists ({existing.number})",
            code="duplicate_report", extra={"existing_id": str(existing.id), "existing_number": existing.number},
        )
    now = datetime.now(UTC)
    report = ProductionReport(
        company_id=ctx.company_id, number=next_number(session, ctx.company_id, "PRD", data.production_date),
        production_date=data.production_date, product_id=product.id, product=product, department_id=data.department_id,
        shift_id=data.shift_id, line_id=data.line_id, order_id=data.order_id, qty_produced=data.qty_produced,
        qty_accepted=data.qty_accepted, qty_defective=data.qty_defective, unit_id=unit.id, unit=unit,
        status="approved" if approve else "draft", supersedes_id=supersedes_id, notes=clean_text(data.notes, 2000),
        approved_by=ctx.user_id if approve else None, approved_at=now if approve else None,
        created_by=ctx.user_id, updated_by=ctx.user_id, source_record_id=source_record_id,
    )
    session.add(report)
    session.flush()
    audit.record(session, "production_report.created", ctx=ctx, entity_type="production_report", entity_id=report.id,
                 summary=f"{report.number}: {data.qty_produced} {unit.code} of {product.code} ({report.status})",
                 changes=report_snapshot(report))
    if approve:
        _post_output(session, ctx, report)
    return report


def get_report(session: Session, ctx: RequestContext, report_id: uuid.UUID) -> ProductionReport:
    report = session.execute(
        select(ProductionReport).where(ProductionReport.id == report_id, ProductionReport.company_id == ctx.company_id)
    ).scalar_one_or_none()
    if report is None or not ctx.can_access_department(report.department_id):
        raise NotFound("Production report not found")
    return report


def approve_report(session: Session, ctx: RequestContext, report_id: uuid.UUID, expected_version: int | None = None) -> ProductionReport:
    ctx.require("production.approve")
    report = get_report(session, ctx, report_id)
    if expected_version is not None and report.version != expected_version:
        raise PreconditionFailed("Report was modified by someone else; reload and try again")
    if report.status != "draft":
        raise ValidationFailed(f"Only draft reports can be approved (status: {report.status})", code="invalid_transition")
    report.status, report.approved_by, report.approved_at = "approved", ctx.user_id, datetime.now(UTC)
    report.updated_by = ctx.user_id
    session.flush()
    audit.record(session, "production_report.approved", ctx=ctx, entity_type="production_report", entity_id=report.id,
                 summary=f"Production report {report.number} approved",
                 changes={"status": {"from": "draft", "to": "approved"}})
    _post_output(session, ctx, report)
    return report


def cancel_report(session: Session, ctx: RequestContext, report_id: uuid.UUID, reason: str) -> ProductionReport:
    """Writers may discard drafts; cancelling an approved report reverses its postings and needs an approver."""
    if not (ctx.has("production.write") or ctx.has("production.approve")):
        ctx.require("production.write")
    report = get_report(session, ctx, report_id)
    if report.status == "cancelled":
        raise ValidationFailed("Report is already cancelled", code="invalid_transition")
    ctx.require("production.write" if report.status == "draft" else "production.approve")
    _mark_cancelled(session, ctx, report, reason)
    _reverse_output(session, ctx, report, reason)
    return report


def _mark_cancelled(session: Session, ctx: RequestContext, report: ProductionReport, reason: str) -> None:
    before = report.status
    report.status = "cancelled"
    report.updated_by = ctx.user_id
    session.flush()
    audit.record(session, "production_report.cancelled", ctx=ctx, entity_type="production_report", entity_id=report.id,
                 summary=f"Production report {report.number} cancelled",
                 changes={"status": {"from": before, "to": "cancelled"}}, context={"reason": reason})


def correct_report(session: Session, ctx: RequestContext, report_id: uuid.UUID, data: ReportIn, reason: str) -> ProductionReport:
    """Supersede an approved report: cancel the old one (reversing its postings) and create a new approved one."""
    ctx.require("production.approve")
    old = get_report(session, ctx, report_id)
    if old.status != "approved":
        raise ValidationFailed("Only approved reports can be corrected; edit drafts directly", code="invalid_transition")
    # The new output is posted before the old one is reversed, so output already issued from stock does
    # not take the balance below zero halfway through the correction.
    _mark_cancelled(session, ctx, old, f"Superseded: {reason}")
    new = create_report(session, ctx, data, approve=True, supersedes_id=old.id)
    _reverse_output(session, ctx, old, f"Superseded: {reason}")
    audit.record(session, "production_report.corrected", ctx=ctx, entity_type="production_report", entity_id=new.id,
                 summary=f"{new.number} supersedes {old.number}",
                 changes=audit.diff(report_snapshot(old), report_snapshot(new)), context={"reason": reason})
    return new


def update_draft(session: Session, ctx: RequestContext, report_id: uuid.UUID, data: ReportIn, expected_version: int | None) -> ProductionReport:
    ctx.require("production.write")
    report = get_report(session, ctx, report_id)
    if report.status != "draft":
        raise ValidationFailed("Approved reports are corrected, not edited", code="invalid_transition")
    if expected_version is not None and report.version != expected_version:
        raise PreconditionFailed("Report was modified by someone else; reload and try again")
    product, unit = _check_report_refs(session, ctx, data)
    clash = find_live_report(session, ctx.company_id, data.production_date, product.id, data.department_id, data.shift_id,
                             exclude=report.id)
    if clash:
        raise Conflict(f"Another report already covers this date/product/unit/shift ({clash.number})", code="duplicate_report")
    before = report_snapshot(report)
    for key in ("production_date", "department_id", "shift_id", "line_id", "order_id", "qty_produced", "qty_accepted", "qty_defective"):
        setattr(report, key, getattr(data, key))
    report.product_id, report.product, report.unit_id, report.unit = product.id, product, unit.id, unit
    report.notes = clean_text(data.notes, 2000)
    report.updated_by = ctx.user_id
    session.flush()
    audit.record(session, "production_report.updated", ctx=ctx, entity_type="production_report", entity_id=report.id,
                 summary=f"Production report {report.number} updated",
                 changes=audit.diff(before, report_snapshot(report)))
    return report


def write_report_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    """Agent commit path: the record was approved in review by a user holding production.approve."""
    p = data.model_dump()
    report = create_report(session, ctx, ReportIn(
        production_date=p["production_date"], product_id=p["product_id"], department_id=p["department_id"],
        qty_produced=p["qty_produced"], qty_accepted=p.get("qty_accepted"), qty_defective=p.get("qty_defective"),
        unit=p.get("unit"), shift_id=p.get("shift_id"), line_id=p.get("line_id"), order_id=p.get("order_id"),
        notes=p.get("notes"),
    ), approve=True, source_record_id=source_record_id)
    return WriteResult("production.production_reports", report.id, report.number,
                       f"{report.number} — {fmt_qty(report.qty_produced)} {report.unit.code} {report.product.code}")


# --------------------------------------------------------------------------- analytics helpers


def plan_vs_actual(session: Session, ctx: RequestContext, date_from: date, date_to: date) -> list[dict[str, Any]]:
    orders = session.execute(
        select(ProductionOrder).where(
            ProductionOrder.company_id == ctx.company_id, ProductionOrder.status != "cancelled",
            func.coalesce(ProductionOrder.planned_start, ProductionOrder.planned_end) <= date_to,
            func.coalesce(ProductionOrder.planned_end, ProductionOrder.planned_start) >= date_from,
        ).order_by(ProductionOrder.planned_start.nulls_last(), ProductionOrder.number)
    ).scalars().all()
    result = []
    for order in orders:
        if not ctx.can_access_department(order.department_id):
            continue
        reports = session.execute(
            select(ProductionReport).where(ProductionReport.order_id == order.id, ProductionReport.status == "approved")
        ).scalars().all()
        actual = Decimal(0)
        for r in reports:
            converted = convert(_output_qty(r), r.unit, order.unit)
            actual += converted if converted is not None else Decimal(0)
        result.append({
            "order_id": str(order.id), "number": order.number, "product": f"{order.product.code} — {order.product.name}",
            "department": order.department.name if order.department else None, "status": order.status,
            "planned_start": order.planned_start, "planned_end": order.planned_end, "unit": order.unit.code,
            "planned_qty": order.planned_qty, "actual_qty": actual,
            "completion_pct": float(round(actual / order.planned_qty * 100, 1)) if order.planned_qty else None,
        })
    return result
