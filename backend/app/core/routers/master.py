"""Master data API: units, product categories, products, entity search for reference pickers."""

from __future__ import annotations

import uuid
from typing import Any

from fastapi import APIRouter, Query, Response
from sqlalchemy import func, or_, select

from app.core.api import DB, Ctx, Idem, IfMatch, etag_for, paginate
from app.core.errors import NotFound
from app.core.matching import get_resolver, registered_entity_types
from app.core.models import Product, ProductAlias, ProductCategory, Unit, UnitAlias
from app.core.services import master_data
from app.core.text import normalize_key

router = APIRouter(prefix="/master", tags=["master data"])


@router.get("/units")
def list_units(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    aliases: dict[uuid.UUID, list[str]] = {}
    for a in db.execute(select(UnitAlias)).scalars():
        aliases.setdefault(a.unit_id, []).append(a.alias)
    return [{"code": u.code, "name": u.name, "dimension": u.dimension, "factor_to_base": u.factor_to_base, "is_base": u.is_base,
             "aliases": sorted(aliases.get(u.id, []))} for u in db.execute(select(Unit).order_by(Unit.dimension, Unit.code)).scalars()]


@router.get("/product-categories")
def list_categories(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("master.read")
    return [{"id": str(c.id), "code": c.code, "name": c.name, "parent_id": str(c.parent_id) if c.parent_id else None}
            for c in db.execute(select(ProductCategory).where(ProductCategory.company_id == ctx.company_id).order_by(ProductCategory.code)).scalars()]


@router.post("/product-categories", status_code=201)
def create_category(data: master_data.CategoryIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    c = master_data.create_category(db, ctx, data)
    db.commit()
    return {"id": str(c.id), "code": c.code, "name": c.name}


def product_out(p: Product) -> dict[str, Any]:
    return {"id": str(p.id), "code": p.code, "name": p.name, "base_unit": p.base_unit.code, "product_type": p.product_type,
            "category_id": str(p.category_id) if p.category_id else None, "reorder_level": p.reorder_level, "is_active": p.is_active,
            "aliases": sorted(a.alias for a in p.aliases if a.normalized_alias not in (normalize_key(p.name), normalize_key(p.code))),
            "version": p.version, "source_record_id": str(p.source_record_id) if p.source_record_id else None,
            "created_at": p.created_at, "updated_at": p.updated_at}


@router.get("/products")
def list_products(ctx: Ctx, db: DB, q: str | None = None, product_type: str | None = None, active: bool | None = True,
                  cursor: str | None = None, limit: int = Query(100, le=500)) -> dict[str, Any]:
    ctx.require("master.read")
    stmt = select(Product).where(Product.company_id == ctx.company_id, Product.deleted_at.is_(None))
    if q:
        key = normalize_key(q)
        alias_hit = select(ProductAlias.product_id).where(ProductAlias.company_id == ctx.company_id,
                                                          ProductAlias.normalized_alias.contains(key))
        stmt = stmt.where(or_(func.lower(Product.code).contains(q.lower()), Product.id.in_(alias_hit)))
    if product_type:
        stmt = stmt.where(Product.product_type == product_type)
    if active is not None:
        stmt = stmt.where(Product.is_active.is_(active))
    rows, next_cursor = paginate(db, stmt, Product, cursor, limit)
    return {"items": [product_out(p) for p in rows], "next_cursor": next_cursor}


@router.get("/products/{product_id}")
def get_product(product_id: uuid.UUID, ctx: Ctx, db: DB, response: Response) -> dict[str, Any]:
    ctx.require("master.read")
    p = master_data.get_product(db, ctx, product_id)
    response.headers["ETag"] = etag_for(p.version)
    return product_out(p)


@router.post("/products", status_code=201)
def create_product(data: master_data.ProductIn, ctx: Ctx, db: DB, idem: Idem, response: Response):
    replay = idem.replay(db, ctx, data.model_dump(mode="json"))
    if replay:
        return replay
    p = master_data.create_product(db, ctx, data)
    out = product_out(p)
    idem.store(db, ctx, 201, out)
    db.commit()
    response.headers["ETag"] = etag_for(p.version)
    return out


@router.patch("/products/{product_id}")
def update_product(product_id: uuid.UUID, data: master_data.ProductUpdate, ctx: Ctx, db: DB, expected: IfMatch,
                   response: Response) -> dict[str, Any]:
    p = master_data.update_product(db, ctx, product_id, data, expected)
    db.commit()
    response.headers["ETag"] = etag_for(p.version)
    return product_out(p)


@router.get("/search/{entity_type}")
def search_entities(entity_type: str, ctx: Ctx, db: DB, q: str = Query(min_length=1, max_length=200)) -> list[dict[str, Any]]:
    """Candidate entities for a reference field (used by the review UI to choose a match)."""
    if entity_type not in registered_entity_types():
        raise NotFound("Unknown entity type")
    return [{"id": str(c.entity_id), "label": c.label, "score": round(c.score, 3), "match_kind": c.kind, "data": c.data}
            for c in get_resolver(entity_type).resolve(db, ctx.company_id, q)]
