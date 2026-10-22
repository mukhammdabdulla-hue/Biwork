"""Inventory service: the only write path for stock (people, Production, and BIWORK Agent)."""

from __future__ import annotations

import uuid
from datetime import date
from decimal import Decimal
from typing import Any

from pydantic import BaseModel, Field, field_validator
from sqlalchemy import func, select, text
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict, NotFound, ValidationFailed
from app.core.manifest import WriteResult
from app.core.models import Department, Product
from app.core.numbering import next_number
from app.core.services.master_data import check_reference, convert, require_unit
from app.core.text import clean_text, fmt_qty
from app.modules.inventory.models import StockBalance, StockMovement, Warehouse

MOVEMENT_DIRECTIONS = {
    "receipt": 1, "issue": -1, "adjustment_in": 1, "adjustment_out": -1,
    "production_output": 1, "production_consumption": -1, "transfer_in": 1, "transfer_out": -1,
}


class WarehouseIn(BaseModel):
    code: str = Field(min_length=1, max_length=50)
    name: str = Field(min_length=1, max_length=200)
    kind: str = "general"
    department_id: uuid.UUID | None = None


def create_warehouse(session: Session, ctx: RequestContext, data: WarehouseIn) -> Warehouse:
    ctx.require("inventory.write", "master.write")
    if session.execute(select(Warehouse.id).where(Warehouse.company_id == ctx.company_id, Warehouse.code == data.code)).first():
        raise Conflict(f"Warehouse code '{data.code}' already exists")
    check_reference(session, ctx, Department, data.department_id, "department", "department_id")
    wh = Warehouse(company_id=ctx.company_id, code=data.code, name=clean_text(data.name), kind=data.kind,
                   department_id=data.department_id)
    session.add(wh)
    session.flush()
    audit.record(session, "warehouse.created", ctx=ctx, entity_type="warehouse", entity_id=wh.id,
                 summary=f"Warehouse {wh.code} — {wh.name} created",
                 changes={"code": wh.code, "name": wh.name, "kind": wh.kind})
    return wh


class MovementIn(BaseModel):
    movement_date: date
    movement_type: str
    warehouse_id: uuid.UUID
    product_id: uuid.UUID
    quantity: Decimal = Field(gt=0)
    unit: str
    batch_code: str | None = Field(default=None, max_length=64)
    reference: str | None = Field(default=None, max_length=100)
    notes: str | None = Field(default=None, max_length=2000)

    @field_validator("movement_type")
    @classmethod
    def _type(cls, v: str) -> str:
        if v not in MOVEMENT_DIRECTIONS:
            raise ValueError(f"must be one of {', '.join(MOVEMENT_DIRECTIONS)}")
        return v


def _warehouse(session: Session, company_id: uuid.UUID, warehouse_id: uuid.UUID) -> Warehouse:
    wh = session.execute(select(Warehouse).where(Warehouse.id == warehouse_id, Warehouse.company_id == company_id)).scalar_one_or_none()
    if wh is None or not wh.is_active:
        raise ValidationFailed("Unknown or inactive warehouse", extra={"field": "warehouse"})
    return wh


def _apply_balance(session: Session, company_id: uuid.UUID, warehouse_id: uuid.UUID, product_id: uuid.UUID,
                   batch_code: str | None, delta: Decimal, allow_negative: bool) -> Decimal:
    new_qty = session.execute(
        text(
            "INSERT INTO inventory.stock_balances (id, company_id, warehouse_id, product_id, batch_code, qty) "
            "VALUES (:id, :c, :w, :p, :b, :d) "
            "ON CONFLICT (company_id, warehouse_id, product_id, batch_code) "
            "DO UPDATE SET qty = inventory.stock_balances.qty + EXCLUDED.qty, updated_at = now() "
            "RETURNING qty"
        ),
        {"id": uuid.uuid4(), "c": company_id, "w": warehouse_id, "p": product_id, "b": batch_code, "d": delta},
    ).scalar_one()
    if new_qty < 0 and not allow_negative:
        raise ValidationFailed(
            f"Insufficient stock: the balance would become {fmt_qty(new_qty)}", code="insufficient_stock",
            extra={"field": "quantity"},
        )
    return new_qty


def post_movement(
    session: Session, ctx: RequestContext, data: MovementIn, *, source_record_id: uuid.UUID | None = None,
    ref_type: str | None = None, ref_id: uuid.UUID | None = None, reverses_id: uuid.UUID | None = None,
    allow_negative: bool = False, permission: str = "inventory.write",
) -> StockMovement:
    ctx.require(permission)
    wh = _warehouse(session, ctx.company_id, data.warehouse_id)
    product = session.execute(select(Product).where(Product.id == data.product_id, Product.company_id == ctx.company_id)).scalar_one_or_none()
    if product is None:
        raise ValidationFailed("Unknown product", extra={"field": "product"})
    unit = require_unit(session, data.unit)
    base_qty = convert(data.quantity, unit, product.base_unit)
    if base_qty is None:
        raise ValidationFailed(
            f"Unit '{unit.code}' cannot be converted to the product's base unit '{product.base_unit.code}'",
            code="unit_incompatible", extra={"field": "unit"},
        )
    direction = MOVEMENT_DIRECTIONS[data.movement_type]
    movement = StockMovement(
        company_id=ctx.company_id, number=next_number(session, ctx.company_id, "SM", data.movement_date),
        movement_date=data.movement_date, movement_type=data.movement_type, direction=direction,
        warehouse_id=wh.id, product_id=product.id, qty=data.quantity * direction, unit_id=unit.id,
        base_qty=base_qty * direction, batch_code=clean_text(data.batch_code, 64), reference=clean_text(data.reference, 100),
        notes=clean_text(data.notes, 2000), ref_type=ref_type, ref_id=ref_id, reverses_id=reverses_id,
        created_by=ctx.user_id, source_record_id=source_record_id,
    )
    session.add(movement)
    session.flush()
    balance = _apply_balance(session, ctx.company_id, wh.id, product.id, movement.batch_code, movement.base_qty, allow_negative)
    audit.record(session, "stock_movement.posted", ctx=ctx, entity_type="stock_movement", entity_id=movement.id,
                 summary=f"{movement.number}: {data.movement_type} {data.quantity} {unit.code} of {product.code} at {wh.code}",
                 changes={"type": data.movement_type, "qty": movement.qty, "unit": unit.code, "warehouse": wh.code,
                          "product": product.code, "balance_after": balance, "ref_type": ref_type, "ref_id": ref_id})
    return movement


def reverse_movement(session: Session, ctx: RequestContext, movement_id: uuid.UUID, reason: str,
                     permission: str = "inventory.write", *, ref_type: str | None = None,
                     ref_id: uuid.UUID | None = None) -> StockMovement:
    """Compensate a movement. A movement posted by a document (e.g. a production report) is reversed only
    by that document's own cancel/correct (which passes its ref), a reversal is final, and stock never
    goes negative."""
    ctx.require(permission)
    original = session.execute(
        select(StockMovement).where(StockMovement.id == movement_id, StockMovement.company_id == ctx.company_id)
    ).scalar_one_or_none()
    if original is None:
        raise NotFound("Movement not found")
    if original.reverses_id is not None:
        raise ValidationFailed(f"{original.number} is a reversal and cannot be reversed itself", code="reversal_not_reversible")
    if original.ref_type and (original.ref_type, original.ref_id) != (ref_type, ref_id):
        raise ValidationFailed(
            f"{original.number} was posted by {original.ref_type.replace('_', ' ')} {original.reference or original.ref_id}; "
            "cancel or correct that document instead", code="posted_by_document",
            extra={"ref_type": original.ref_type, "ref_id": str(original.ref_id) if original.ref_id else None},
        )
    if session.execute(select(StockMovement.id).where(StockMovement.reverses_id == original.id)).first():
        raise Conflict("Movement already reversed")
    reversal = StockMovement(
        company_id=ctx.company_id, number=next_number(session, ctx.company_id, "SM", original.movement_date),
        movement_date=original.movement_date, movement_type=original.movement_type, direction=-original.direction,
        warehouse_id=original.warehouse_id, product_id=original.product_id, qty=-original.qty, unit_id=original.unit_id,
        base_qty=-original.base_qty, batch_code=original.batch_code, reference=original.reference,
        ref_type=original.ref_type, ref_id=original.ref_id, reverses_id=original.id, notes=f"Reversal: {reason}"[:2000],
        created_by=ctx.user_id,
    )
    session.add(reversal)
    session.flush()
    _apply_balance(session, ctx.company_id, original.warehouse_id, original.product_id, original.batch_code,
                   reversal.base_qty, allow_negative=False)
    audit.record(session, "stock_movement.reversed", ctx=ctx, entity_type="stock_movement", entity_id=original.id,
                 summary=f"{original.number} reversed by {reversal.number}", changes={"reason": reason, "reversal": reversal.number})
    return reversal


def movements_for_ref(session: Session, company_id: uuid.UUID, ref_type: str, ref_id: uuid.UUID) -> list[StockMovement]:
    return list(session.execute(
        select(StockMovement).where(StockMovement.company_id == company_id, StockMovement.ref_type == ref_type,
                                    StockMovement.ref_id == ref_id).order_by(StockMovement.created_at)
    ).scalars())


def write_movement_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    p = data.model_dump()
    movement = post_movement(session, ctx, MovementIn(
        movement_date=p["movement_date"], movement_type=p["movement_type"], warehouse_id=p["warehouse_id"],
        product_id=p["product_id"], quantity=p["quantity"], unit=p["unit"], batch_code=p.get("batch_code"),
        reference=p.get("reference"), notes=p.get("notes"),
    ), source_record_id=source_record_id)
    return WriteResult("inventory.stock_movements", movement.id, movement.number,
                       f"{movement.number} — {movement.movement_type} {fmt_qty(abs(movement.qty))} {movement.unit.code}")


def shortages(session: Session, company_id: uuid.UUID) -> list[dict[str, Any]]:
    """Products whose total stock (all warehouses, base unit) is below their reorder level."""
    totals = (
        select(StockBalance.product_id, func.sum(StockBalance.qty).label("qty"))
        .where(StockBalance.company_id == company_id).group_by(StockBalance.product_id).subquery()
    )
    rows = session.execute(
        select(Product, func.coalesce(totals.c.qty, 0))
        .outerjoin(totals, totals.c.product_id == Product.id)
        .where(Product.company_id == company_id, Product.reorder_level.is_not(None), Product.is_active.is_(True),
               func.coalesce(totals.c.qty, 0) < Product.reorder_level)
        .order_by(Product.code)
    ).all()
    return [
        {"product_id": str(p.id), "code": p.code, "name": p.name, "unit": p.base_unit.code,
         "on_hand": qty, "reorder_level": p.reorder_level, "missing": p.reorder_level - qty}
        for p, qty in rows
    ]
