"""Inventory API (/api/v1/inventory)."""

from __future__ import annotations

import uuid
from datetime import date
from typing import Any

from fastapi import APIRouter, Query
from pydantic import BaseModel, Field
from sqlalchemy import select

from app.core.api import DB, Ctx, Idem, paginate
from app.core.models import Product
from app.modules.inventory import service
from app.modules.inventory.models import StockBalance, StockMovement, Warehouse

router = APIRouter(prefix="/inventory", tags=["inventory"])


@router.get("/warehouses")
def list_warehouses(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("inventory.read")
    return [{"id": str(w.id), "code": w.code, "name": w.name, "kind": w.kind, "is_active": w.is_active,
             "department_id": str(w.department_id) if w.department_id else None}
            for w in db.execute(select(Warehouse).where(Warehouse.company_id == ctx.company_id).order_by(Warehouse.code)).scalars()]


@router.post("/warehouses", status_code=201)
def create_warehouse(data: service.WarehouseIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    w = service.create_warehouse(db, ctx, data)
    db.commit()
    return {"id": str(w.id), "code": w.code, "name": w.name, "kind": w.kind}


@router.get("/balances")
def balances(ctx: Ctx, db: DB, warehouse_id: uuid.UUID | None = None, product_id: uuid.UUID | None = None,
             include_zero: bool = False) -> list[dict[str, Any]]:
    ctx.require("inventory.read")
    stmt = (select(StockBalance, Product, Warehouse).join(Product, Product.id == StockBalance.product_id)
            .join(Warehouse, Warehouse.id == StockBalance.warehouse_id).where(StockBalance.company_id == ctx.company_id))
    if warehouse_id:
        stmt = stmt.where(StockBalance.warehouse_id == warehouse_id)
    if product_id:
        stmt = stmt.where(StockBalance.product_id == product_id)
    if not include_zero:
        stmt = stmt.where(StockBalance.qty != 0)
    rows = db.execute(stmt.order_by(Warehouse.code, Product.code)).all()
    return [{"warehouse": {"id": str(w.id), "code": w.code, "name": w.name},
             "product": {"id": str(p.id), "code": p.code, "name": p.name, "type": p.product_type},
             "batch_code": b.batch_code, "qty": b.qty, "unit": p.base_unit.code, "reorder_level": p.reorder_level,
             "updated_at": b.updated_at} for b, p, w in rows]


@router.get("/shortages")
def shortages(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("inventory.read")
    return service.shortages(db, ctx.company_id)


def movement_out(m: StockMovement) -> dict[str, Any]:
    return {"id": str(m.id), "number": m.number, "movement_date": m.movement_date, "movement_type": m.movement_type,
            "direction": m.direction, "warehouse": {"id": str(m.warehouse_id), "code": m.warehouse.code, "name": m.warehouse.name},
            "product": {"id": str(m.product_id), "code": m.product.code, "name": m.product.name}, "qty": m.qty, "unit": m.unit.code,
            "base_qty": m.base_qty, "batch_code": m.batch_code, "reference": m.reference, "ref_type": m.ref_type,
            "ref_id": str(m.ref_id) if m.ref_id else None, "reverses_id": str(m.reverses_id) if m.reverses_id else None,
            "notes": m.notes, "created_at": m.created_at, "source_record_id": str(m.source_record_id) if m.source_record_id else None}


@router.get("/movements")
def list_movements(ctx: Ctx, db: DB, warehouse_id: uuid.UUID | None = None, product_id: uuid.UUID | None = None,
                   movement_type: str | None = None, date_from: date | None = None, date_to: date | None = None,
                   cursor: str | None = None, limit: int = Query(100, le=500)) -> dict[str, Any]:
    ctx.require("inventory.read")
    stmt = select(StockMovement).where(StockMovement.company_id == ctx.company_id)
    if warehouse_id:
        stmt = stmt.where(StockMovement.warehouse_id == warehouse_id)
    if product_id:
        stmt = stmt.where(StockMovement.product_id == product_id)
    if movement_type:
        stmt = stmt.where(StockMovement.movement_type == movement_type)
    if date_from:
        stmt = stmt.where(StockMovement.movement_date >= date_from)
    if date_to:
        stmt = stmt.where(StockMovement.movement_date <= date_to)
    rows, next_cursor = paginate(db, stmt, StockMovement, cursor, limit)
    return {"items": [movement_out(m) for m in rows], "next_cursor": next_cursor}


@router.post("/movements", status_code=201)
def post_movement(data: service.MovementIn, ctx: Ctx, db: DB, idem: Idem):
    replay = idem.replay(db, ctx, data.model_dump(mode="json"))
    if replay:
        return replay
    m = service.post_movement(db, ctx, data)
    out = movement_out(m)
    idem.store(db, ctx, 201, out)
    db.commit()
    return out


class ReverseIn(BaseModel):
    reason: str = Field(min_length=3, max_length=500)


@router.post("/movements/{movement_id}/reverse", status_code=201)
def reverse(movement_id: uuid.UUID, data: ReverseIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    m = service.reverse_movement(db, ctx, movement_id, data.reason)
    db.commit()
    return movement_out(m)
