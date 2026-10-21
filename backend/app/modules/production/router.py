"""Production API (/api/v1/production)."""

from __future__ import annotations

import uuid
from datetime import date, timedelta
from typing import Any

from fastapi import APIRouter, Query, Response
from pydantic import BaseModel, Field
from sqlalchemy import func, select

from app.core.api import DB, Ctx, Idem, IfMatch, etag_for, paginate
from app.core.models import Product
from app.modules.inventory.service import movements_for_ref
from app.modules.production import service
from app.modules.production.models import ProductionLine, ProductionOrder, ProductionReport, Shift

router = APIRouter(prefix="/production", tags=["production"])


def _scope(stmt, model, ctx):
    if ctx.department_ids is not None:
        stmt = stmt.where(model.department_id.in_(ctx.department_ids))
    return stmt


# --------------------------------------------------------------------------- lines & shifts


@router.get("/lines")
def list_lines(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("production.read")
    return [{"id": str(x.id), "code": x.code, "name": x.name, "department_id": str(x.department_id),
             "capacity_per_shift": x.capacity_per_shift, "is_active": x.is_active}
            for x in db.execute(select(ProductionLine).where(ProductionLine.company_id == ctx.company_id).order_by(ProductionLine.code)).scalars()]


@router.post("/lines", status_code=201)
def create_line(data: service.LineIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    line = service.create_line(db, ctx, data)
    db.commit()
    return {"id": str(line.id), "code": line.code, "name": line.name}


@router.get("/shifts")
def list_shifts(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("production.read")
    return [{"id": str(x.id), "code": x.code, "name": x.name, "starts_at": x.starts_at, "ends_at": x.ends_at, "is_active": x.is_active}
            for x in db.execute(select(Shift).where(Shift.company_id == ctx.company_id).order_by(Shift.code)).scalars()]


@router.post("/shifts", status_code=201)
def create_shift(data: service.ShiftIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    shift = service.create_shift(db, ctx, data)
    db.commit()
    return {"id": str(shift.id), "code": shift.code, "name": shift.name}


# --------------------------------------------------------------------------- orders


def order_out(o: ProductionOrder, actual: Any = None) -> dict[str, Any]:
    return {"id": str(o.id), "number": o.number, "external_number": o.external_number,
            "product": {"id": str(o.product_id), "code": o.product.code, "name": o.product.name},
            "department": {"id": str(o.department_id), "name": o.department.name} if o.department_id else None,
            "line_id": str(o.line_id) if o.line_id else None, "planned_qty": o.planned_qty, "unit": o.unit.code,
            "planned_start": o.planned_start, "planned_end": o.planned_end, "status": o.status, "priority": o.priority,
            "notes": o.notes, "actual_qty": actual, "version": o.version, "created_at": o.created_at,
            "source_record_id": str(o.source_record_id) if o.source_record_id else None}


def _actuals(db, order_ids: list[uuid.UUID]) -> dict[uuid.UUID, Any]:
    if not order_ids:
        return {}
    rows = db.execute(select(ProductionReport.order_id, func.sum(func.coalesce(
        ProductionReport.qty_accepted, ProductionReport.qty_produced - func.coalesce(ProductionReport.qty_defective, 0))))
        .where(ProductionReport.order_id.in_(order_ids), ProductionReport.status == "approved")
        .group_by(ProductionReport.order_id)).all()
    return dict(rows)


@router.get("/orders")
def list_orders(ctx: Ctx, db: DB, status: str | None = None, product_id: uuid.UUID | None = None, q: str | None = None,
                cursor: str | None = None, limit: int = Query(100, le=500)) -> dict[str, Any]:
    ctx.require("production.read")
    stmt = _scope(select(ProductionOrder).where(ProductionOrder.company_id == ctx.company_id), ProductionOrder, ctx)
    if status:
        stmt = stmt.where(ProductionOrder.status.in_(status.split(",")))
    if product_id:
        stmt = stmt.where(ProductionOrder.product_id == product_id)
    if q:
        stmt = stmt.where(ProductionOrder.number.ilike(f"%{q}%") | ProductionOrder.external_number.ilike(f"%{q}%"))
    rows, next_cursor = paginate(db, stmt, ProductionOrder, cursor, limit)
    actual = _actuals(db, [o.id for o in rows])
    return {"items": [order_out(o, actual.get(o.id, 0)) for o in rows], "next_cursor": next_cursor}


@router.get("/orders/{order_id}")
def get_order(order_id: uuid.UUID, ctx: Ctx, db: DB, response: Response) -> dict[str, Any]:
    ctx.require("production.read")
    o = service.get_order(db, ctx, order_id)
    response.headers["ETag"] = etag_for(o.version)
    return order_out(o, _actuals(db, [o.id]).get(o.id, 0))


@router.post("/orders", status_code=201)
def create_order(data: service.OrderIn, ctx: Ctx, db: DB, idem: Idem):
    replay = idem.replay(db, ctx, data.model_dump(mode="json"))
    if replay:
        return replay
    o = service.create_order(db, ctx, data)
    out = order_out(o, 0)
    idem.store(db, ctx, 201, out)
    db.commit()
    return out


class StatusIn(BaseModel):
    status: str


@router.post("/orders/{order_id}/status")
def set_order_status(order_id: uuid.UUID, data: StatusIn, ctx: Ctx, db: DB, expected: IfMatch, response: Response) -> dict[str, Any]:
    o = service.set_order_status(db, ctx, order_id, data.status, expected)
    db.commit()
    response.headers["ETag"] = etag_for(o.version)
    return order_out(o, _actuals(db, [o.id]).get(o.id, 0))


# --------------------------------------------------------------------------- reports


def report_out(db, r: ProductionReport) -> dict[str, Any]:
    shift = db.get(Shift, r.shift_id) if r.shift_id else None
    order = db.get(ProductionOrder, r.order_id) if r.order_id else None
    return {"id": str(r.id), "number": r.number, "production_date": r.production_date,
            "product": {"id": str(r.product_id), "code": r.product.code, "name": r.product.name},
            "department": {"id": str(r.department_id), "name": r.department.name},
            "shift": {"id": str(shift.id), "name": shift.name} if shift else None,
            "order": {"id": str(order.id), "number": order.number} if order else None, "line_id": str(r.line_id) if r.line_id else None,
            "qty_produced": r.qty_produced, "qty_accepted": r.qty_accepted, "qty_defective": r.qty_defective, "unit": r.unit.code,
            "status": r.status, "supersedes_id": str(r.supersedes_id) if r.supersedes_id else None, "notes": r.notes,
            "approved_at": r.approved_at, "version": r.version, "created_at": r.created_at,
            "source_record_id": str(r.source_record_id) if r.source_record_id else None}


@router.get("/reports")
def list_reports(ctx: Ctx, db: DB, date_from: date | None = None, date_to: date | None = None, product_id: uuid.UUID | None = None,
                 department_id: uuid.UUID | None = None, status: str | None = None, cursor: str | None = None,
                 limit: int = Query(100, le=500)) -> dict[str, Any]:
    ctx.require("production.read")
    stmt = _scope(select(ProductionReport).where(ProductionReport.company_id == ctx.company_id), ProductionReport, ctx)
    if date_from:
        stmt = stmt.where(ProductionReport.production_date >= date_from)
    if date_to:
        stmt = stmt.where(ProductionReport.production_date <= date_to)
    if product_id:
        stmt = stmt.where(ProductionReport.product_id == product_id)
    if department_id:
        stmt = stmt.where(ProductionReport.department_id == department_id)
    if status:
        stmt = stmt.where(ProductionReport.status.in_(status.split(",")))
    rows, next_cursor = paginate(db, stmt, ProductionReport, cursor, limit, order="created_at")
    return {"items": [report_out(db, r) for r in rows], "next_cursor": next_cursor}


@router.get("/reports/{report_id}")
def get_report(report_id: uuid.UUID, ctx: Ctx, db: DB, response: Response) -> dict[str, Any]:
    ctx.require("production.read")
    r = service.get_report(db, ctx, report_id)
    response.headers["ETag"] = etag_for(r.version)
    movements = movements_for_ref(db, ctx.company_id, "production_report", r.id)
    superseded_by = db.execute(select(ProductionReport.id, ProductionReport.number).where(ProductionReport.supersedes_id == r.id)).first()
    return {**report_out(db, r),
            "stock_movements": [{"id": str(m.id), "number": m.number, "type": m.movement_type, "qty": m.qty, "unit": m.unit.code,
                                 "warehouse": m.warehouse.name, "reverses_id": str(m.reverses_id) if m.reverses_id else None}
                                for m in movements],
            "superseded_by": {"id": str(superseded_by[0]), "number": superseded_by[1]} if superseded_by else None}


class ReportCreate(service.ReportIn):
    approve: bool = False


@router.post("/reports", status_code=201)
def create_report(data: ReportCreate, ctx: Ctx, db: DB, idem: Idem):
    replay = idem.replay(db, ctx, data.model_dump(mode="json"))
    if replay:
        return replay
    r = service.create_report(db, ctx, service.ReportIn(**data.model_dump(exclude={"approve"})), approve=data.approve)
    out = report_out(db, r)
    idem.store(db, ctx, 201, out)
    db.commit()
    return out


@router.put("/reports/{report_id}")
def update_draft(report_id: uuid.UUID, data: service.ReportIn, ctx: Ctx, db: DB, expected: IfMatch, response: Response) -> dict[str, Any]:
    r = service.update_draft(db, ctx, report_id, data, expected)
    db.commit()
    response.headers["ETag"] = etag_for(r.version)
    return report_out(db, r)


@router.post("/reports/{report_id}/approve")
def approve_report(report_id: uuid.UUID, ctx: Ctx, db: DB, expected: IfMatch) -> dict[str, Any]:
    r = service.approve_report(db, ctx, report_id, expected)
    db.commit()
    return report_out(db, r)


class ReasonIn(BaseModel):
    reason: str = Field(min_length=3, max_length=1000)


@router.post("/reports/{report_id}/cancel")
def cancel_report(report_id: uuid.UUID, data: ReasonIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    r = service.cancel_report(db, ctx, report_id, data.reason)
    db.commit()
    return report_out(db, r)


class CorrectionIn(BaseModel):
    report: service.ReportIn
    reason: str = Field(min_length=3, max_length=1000)


@router.post("/reports/{report_id}/correct", status_code=201)
def correct_report(report_id: uuid.UUID, data: CorrectionIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    r = service.correct_report(db, ctx, report_id, data.report, data.reason)
    db.commit()
    return report_out(db, r)


# --------------------------------------------------------------------------- planning


@router.get("/plan-vs-actual")
def plan_vs_actual(ctx: Ctx, db: DB, date_from: date | None = None, date_to: date | None = None) -> list[dict[str, Any]]:
    ctx.require("production.read")
    today = date.today()
    return service.plan_vs_actual(db, ctx, date_from or today - timedelta(days=30), date_to or today + timedelta(days=30))


@router.get("/calendar")
def calendar(ctx: Ctx, db: DB, month: str = Query(pattern=r"^\d{4}-\d{2}$")) -> dict[str, Any]:
    """Orders overlapping the month and daily approved output, for the planning calendar."""
    ctx.require("production.read")
    first = date.fromisoformat(month + "-01")
    last = (first.replace(day=28) + timedelta(days=4)).replace(day=1) - timedelta(days=1)
    orders = db.execute(_scope(select(ProductionOrder).where(
        ProductionOrder.company_id == ctx.company_id, ProductionOrder.status != "cancelled",
        func.coalesce(ProductionOrder.planned_start, ProductionOrder.planned_end) <= last,
        func.coalesce(ProductionOrder.planned_end, ProductionOrder.planned_start) >= first,
    ), ProductionOrder, ctx).order_by(ProductionOrder.planned_start)).scalars().all()
    daily = db.execute(_scope(select(ProductionReport.production_date, Product.base_unit_id, func.sum(ProductionReport.qty_produced),
                                     func.sum(func.coalesce(ProductionReport.qty_defective, 0)))
                              .join(Product, Product.id == ProductionReport.product_id)
                              .where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved",
                                     ProductionReport.production_date.between(first, last)), ProductionReport, ctx)
                       .group_by(ProductionReport.production_date, Product.base_unit_id)).all()
    days: dict[str, dict[str, Any]] = {}
    for d, _unit, produced, defective in daily:
        entry = days.setdefault(d.isoformat(), {"produced": 0, "defective": 0})
        entry["produced"] += float(produced)
        entry["defective"] += float(defective)
    actual = _actuals(db, [o.id for o in orders])
    return {"month": month, "orders": [order_out(o, actual.get(o.id, 0)) for o in orders], "days": days,
            "note": "Daily totals add quantities across products; see reports for per-product units."}

