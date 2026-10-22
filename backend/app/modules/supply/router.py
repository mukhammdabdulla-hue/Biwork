"""Supply API (/api/v1/supply)."""

from __future__ import annotations

import uuid
from typing import Any

from fastapi import APIRouter, Query, Response
from pydantic import BaseModel, Field
from sqlalchemy import select

from app.core.api import DB, Ctx, Idem, IfMatch, etag_for, paginate
from app.modules.supply import service
from app.modules.supply.models import PurchaseRequest, Supplier

router = APIRouter(prefix="/supply", tags=["supply"])


@router.get("/suppliers")
def list_suppliers(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("supply.read")
    return [{"id": str(s.id), "code": s.code, "name": s.name, "tin": s.tin, "contact_person": s.contact_person, "phone": s.phone,
             "email": s.email, "address": s.address, "is_active": s.is_active}
            for s in db.execute(select(Supplier).where(Supplier.company_id == ctx.company_id).order_by(Supplier.name)).scalars()]


@router.post("/suppliers", status_code=201)
def create_supplier(data: service.SupplierIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    s = service.create_supplier(db, ctx, data)
    db.commit()
    return {"id": str(s.id), "code": s.code, "name": s.name}


def pr_out(pr: PurchaseRequest) -> dict[str, Any]:
    return {"id": str(pr.id), "number": pr.number, "external_number": pr.external_number, "request_date": pr.request_date,
            "needed_by": pr.needed_by, "department": {"id": str(pr.department_id), "name": pr.department.name} if pr.department else None,
            "requested_by": pr.requested_by_employee, "supplier": {"id": str(pr.supplier_id), "name": pr.supplier.name} if pr.supplier else None,
            "status": pr.status, "priority": pr.priority, "currency": pr.currency, "total_estimated": pr.total_estimated,
            "notes": pr.notes, "source_file_id": str(pr.source_file_id) if pr.source_file_id else None,
            "decided_at": pr.decided_at, "decision_comment": pr.decision_comment, "version": pr.version, "created_at": pr.created_at,
            "source_record_id": str(pr.source_record_id) if pr.source_record_id else None,
            "lines": [{"line_no": li.line_no, "description": li.description, "qty": li.qty, "unit": li.unit.code,
                       "product": {"id": str(li.product_id), "code": li.product.code, "name": li.product.name} if li.product else None,
                       "est_price": li.est_price} for li in pr.lines]}


@router.get("/purchase-requests")
def list_prs(ctx: Ctx, db: DB, status: str | None = None, cursor: str | None = None, limit: int = Query(100, le=500)) -> dict[str, Any]:
    ctx.require("supply.read")
    stmt = select(PurchaseRequest).where(PurchaseRequest.company_id == ctx.company_id)
    if status:
        stmt = stmt.where(PurchaseRequest.status.in_(status.split(",")))
    if ctx.department_ids is not None:
        stmt = stmt.where(PurchaseRequest.department_id.is_(None) | PurchaseRequest.department_id.in_(ctx.department_ids))
    rows, next_cursor = paginate(db, stmt, PurchaseRequest, cursor, limit)
    return {"items": [pr_out(p) for p in rows], "next_cursor": next_cursor}


@router.get("/purchase-requests/{pr_id}")
def get_pr(pr_id: uuid.UUID, ctx: Ctx, db: DB, response: Response) -> dict[str, Any]:
    ctx.require("supply.read")
    pr = service.get_purchase_request(db, ctx, pr_id)
    response.headers["ETag"] = etag_for(pr.version)
    return pr_out(pr)


@router.post("/purchase-requests", status_code=201)
def create_pr(data: service.PurchaseRequestIn, ctx: Ctx, db: DB, idem: Idem):
    replay = idem.replay(db, ctx, data.model_dump(mode="json"))
    if replay:
        return replay
    pr = service.create_purchase_request(db, ctx, data)
    out = pr_out(pr)
    idem.store(db, ctx, 201, out)
    db.commit()
    return out


class DecisionIn(BaseModel):
    status: str
    comment: str | None = Field(default=None, max_length=2000)


@router.post("/purchase-requests/{pr_id}/status")
def decide(pr_id: uuid.UUID, data: DecisionIn, ctx: Ctx, db: DB, expected: IfMatch, response: Response) -> dict[str, Any]:
    pr = service.decide_purchase_request(db, ctx, pr_id, data.status, data.comment, expected)
    db.commit()
    response.headers["ETag"] = etag_for(pr.version)
    return pr_out(pr)
