"""Master data: units, product categories, products (with aliases), departments (with aliases).

Services are the only write path, for people and for BIWORK Agent alike. They run inside the
caller's transaction (request or Agent commit batch), check permissions, and write audit events.
"""

from __future__ import annotations

import uuid
from decimal import Decimal
from typing import Any

from pydantic import BaseModel, Field, field_validator
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict, NotFound, PreconditionFailed, ValidationFailed
from app.core.manifest import WriteResult
from app.core.matching import AliasTableResolver, ListResolver, register_resolver
from app.core.models import Department, DepartmentAlias, Product, ProductAlias, ProductCategory, Unit, UnitAlias
from app.core.text import clean_text, normalize_key

PRODUCT_TYPES = ("finished_good", "semi_finished", "raw_material", "consumable", "packaging", "service")
DEPARTMENT_KINDS = ("workshop", "warehouse", "office", "other")

# --------------------------------------------------------------------------- units


def unit_alias_key(value: str) -> str:
    return " ".join(value.strip().lower().replace(".", " ").split())


def find_unit(session: Session, value: str | None) -> Unit | None:
    if not value:
        return None
    key = unit_alias_key(value)
    unit = session.execute(select(Unit).where(func.lower(Unit.code) == key)).scalar_one_or_none()
    if unit:
        return unit
    return session.execute(
        select(Unit).join(UnitAlias, UnitAlias.unit_id == Unit.id).where(UnitAlias.alias == key)
    ).scalar_one_or_none()


def require_unit(session: Session, code: str) -> Unit:
    unit = find_unit(session, code)
    if unit is None:
        raise ValidationFailed(f"Unknown unit of measure '{code}'", extra={"field": "unit"})
    return unit


def convert(qty: Decimal, from_unit: Unit, to_unit: Unit) -> Decimal | None:
    """Convert between units of the same dimension; None when not convertible."""
    if from_unit.id == to_unit.id:
        return qty
    if from_unit.dimension != to_unit.dimension:
        return None
    return qty * Decimal(from_unit.factor_to_base) / Decimal(to_unit.factor_to_base)


def unit_map(session: Session) -> dict[str, dict[str, Any]]:
    """alias/code -> {code, dimension} for fast in-memory normalization."""
    units = {u.id: u for u in session.execute(select(Unit)).scalars()}
    out = {u.code.lower(): {"code": u.code, "dimension": u.dimension} for u in units.values()}
    for alias in session.execute(select(UnitAlias)).scalars():
        u = units[alias.unit_id]
        out[alias.alias] = {"code": u.code, "dimension": u.dimension}
    return out


# --------------------------------------------------------------------------- product categories


class CategoryIn(BaseModel):
    code: str = Field(min_length=1, max_length=50)
    name: str = Field(min_length=1, max_length=200)
    parent_id: uuid.UUID | None = None


def create_category(session: Session, ctx: RequestContext, data: CategoryIn) -> ProductCategory:
    ctx.require("master.write")
    if session.execute(
        select(ProductCategory.id).where(ProductCategory.company_id == ctx.company_id, ProductCategory.code == data.code)
    ).first():
        raise Conflict(f"Category code '{data.code}' already exists")
    cat = ProductCategory(company_id=ctx.company_id, code=data.code.strip(), name=clean_text(data.name), parent_id=data.parent_id)
    session.add(cat)
    session.flush()
    audit.record(session, "product_category.created", ctx=ctx, entity_type="product_category", entity_id=cat.id,
                 summary=f"Category {cat.code} created", changes={"code": cat.code, "name": cat.name})
    return cat


# --------------------------------------------------------------------------- products


class ProductIn(BaseModel):
    code: str = Field(min_length=1, max_length=64)
    name: str = Field(min_length=1, max_length=250)
    base_unit: str = Field(min_length=1, max_length=16, description="Unit code or alias, e.g. pcs, kg, dona")
    product_type: str = "finished_good"
    category_id: uuid.UUID | None = None
    reorder_level: Decimal | None = Field(default=None, ge=0)
    aliases: list[str] = Field(default_factory=list, max_length=50)

    @field_validator("product_type")
    @classmethod
    def _type(cls, v: str) -> str:
        if v not in PRODUCT_TYPES:
            raise ValueError(f"must be one of {', '.join(PRODUCT_TYPES)}")
        return v


class ProductUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=250)
    base_unit: str | None = None
    product_type: str | None = None
    category_id: uuid.UUID | None = None
    reorder_level: Decimal | None = Field(default=None, ge=0)
    aliases: list[str] | None = Field(default=None, max_length=50)
    is_active: bool | None = None


def product_snapshot(p: Product) -> dict[str, Any]:
    return {
        "code": p.code, "name": p.name, "base_unit": p.base_unit.code if p.base_unit else None,
        "product_type": p.product_type, "category_id": p.category_id, "reorder_level": p.reorder_level,
        "is_active": p.is_active, "aliases": sorted(a.alias for a in p.aliases),
    }


def _sync_product_aliases(session: Session, product: Product, extra: list[str]) -> None:
    wanted: dict[str, str] = {}
    for alias in [product.name, product.code, *extra]:
        alias = clean_text(alias, 250)
        if alias and normalize_key(alias):
            wanted.setdefault(normalize_key(alias), alias)
    existing = {a.normalized_alias: a for a in product.aliases}
    for key, alias in wanted.items():
        if key not in existing:
            product.aliases.append(ProductAlias(company_id=product.company_id, alias=alias, normalized_alias=key))
    for key, row in existing.items():
        if key not in wanted:
            product.aliases.remove(row)


def get_product(session: Session, ctx: RequestContext, product_id: uuid.UUID) -> Product:
    product = session.execute(
        select(Product).where(Product.id == product_id, Product.company_id == ctx.company_id, Product.deleted_at.is_(None))
    ).scalar_one_or_none()
    if product is None:
        raise NotFound("Product not found")
    return product


def create_product(
    session: Session, ctx: RequestContext, data: ProductIn, source_record_id: uuid.UUID | None = None
) -> Product:
    ctx.require("master.write")
    code = data.code.strip()
    if session.execute(select(Product.id).where(Product.company_id == ctx.company_id, func.lower(Product.code) == code.lower())).first():
        raise Conflict(f"Product code '{code}' already exists", code="duplicate_product_code")
    unit = require_unit(session, data.base_unit)
    product = Product(
        company_id=ctx.company_id, code=code, name=clean_text(data.name, 250), base_unit_id=unit.id, base_unit=unit,
        product_type=data.product_type, category_id=data.category_id, reorder_level=data.reorder_level,
        created_by=ctx.user_id, updated_by=ctx.user_id, source_record_id=source_record_id,
    )
    session.add(product)
    _sync_product_aliases(session, product, data.aliases)
    session.flush()
    audit.record(session, "product.created", ctx=ctx, entity_type="product", entity_id=product.id,
                 summary=f"Product {product.code} — {product.name} created", changes=product_snapshot(product))
    return product


def update_product(
    session: Session, ctx: RequestContext, product_id: uuid.UUID, data: ProductUpdate, expected_version: int | None = None
) -> Product:
    ctx.require("master.write")
    product = get_product(session, ctx, product_id)
    if expected_version is not None and product.version != expected_version:
        raise PreconditionFailed("Product was modified by someone else; reload and try again")
    before = product_snapshot(product)
    values = data.model_dump(exclude_unset=True)
    if "name" in values and values["name"]:
        product.name = clean_text(values["name"], 250)
    if values.get("base_unit"):
        unit = require_unit(session, values["base_unit"])
        product.base_unit_id, product.base_unit = unit.id, unit
    for attr in ("product_type", "category_id", "reorder_level", "is_active"):
        if attr in values:
            if attr == "product_type" and values[attr] not in PRODUCT_TYPES:
                raise ValidationFailed(f"product_type must be one of {', '.join(PRODUCT_TYPES)}")
            setattr(product, attr, values[attr])
    if values.get("aliases") is not None or "name" in values:
        extra = values.get("aliases")
        if extra is None:
            extra = [a.alias for a in product.aliases if a.normalized_alias not in {normalize_key(product.code)}]
        _sync_product_aliases(session, product, extra)
    product.updated_by = ctx.user_id
    session.flush()
    changes = audit.diff(before, product_snapshot(product))
    if changes:
        audit.record(session, "product.updated", ctx=ctx, entity_type="product", entity_id=product.id,
                     summary=f"Product {product.code} updated", changes=changes)
    return product


def write_product_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    payload = data.model_dump()
    product = create_product(
        session, ctx,
        ProductIn(code=payload["code"], name=payload["name"], base_unit=payload["base_unit"],
                  product_type=payload.get("product_type") or "finished_good",
                  category_id=payload.get("category_id"), reorder_level=payload.get("reorder_level"),
                  aliases=[a for a in [payload.get("alias")] if a]),
        source_record_id=source_record_id,
    )
    return WriteResult("core.products", product.id, product.code, f"{product.code} — {product.name}")


# --------------------------------------------------------------------------- departments


class DepartmentIn(BaseModel):
    code: str = Field(min_length=1, max_length=50)
    name: str = Field(min_length=1, max_length=200)
    kind: str = "office"
    parent_id: uuid.UUID | None = None
    aliases: list[str] = Field(default_factory=list, max_length=30)

    @field_validator("kind")
    @classmethod
    def _kind(cls, v: str) -> str:
        if v not in DEPARTMENT_KINDS:
            raise ValueError(f"must be one of {', '.join(DEPARTMENT_KINDS)}")
        return v


def _sync_department_aliases(session: Session, dep: Department, extra: list[str]) -> None:
    existing = {
        a.normalized_alias: a
        for a in session.execute(select(DepartmentAlias).where(DepartmentAlias.department_id == dep.id)).scalars()
    }
    wanted: dict[str, str] = {}
    for alias in [dep.name, dep.code, *extra]:
        alias = clean_text(alias, 200)
        if alias and normalize_key(alias):
            wanted.setdefault(normalize_key(alias), alias)
    for key, alias in wanted.items():
        if key not in existing:
            session.add(DepartmentAlias(company_id=dep.company_id, department_id=dep.id, alias=alias, normalized_alias=key))
    for key, row in existing.items():
        if key not in wanted:
            session.delete(row)


def create_department(session: Session, ctx: RequestContext, data: DepartmentIn) -> Department:
    ctx.require("admin.company")
    if session.execute(select(Department.id).where(Department.company_id == ctx.company_id, Department.code == data.code)).first():
        raise Conflict(f"Department code '{data.code}' already exists")
    dep = Department(company_id=ctx.company_id, code=data.code.strip(), name=clean_text(data.name, 200), kind=data.kind,
                     parent_id=data.parent_id)
    session.add(dep)
    session.flush()
    _sync_department_aliases(session, dep, data.aliases)
    session.flush()
    audit.record(session, "department.created", ctx=ctx, entity_type="department", entity_id=dep.id,
                 summary=f"Department {dep.code} — {dep.name} created",
                 changes={"code": dep.code, "name": dep.name, "kind": dep.kind, "aliases": data.aliases})
    return dep


def update_department_aliases(session: Session, ctx: RequestContext, department_id: uuid.UUID, aliases: list[str]) -> Department:
    ctx.require("admin.company")
    dep = session.execute(
        select(Department).where(Department.id == department_id, Department.company_id == ctx.company_id)
    ).scalar_one_or_none()
    if dep is None:
        raise NotFound("Department not found")
    _sync_department_aliases(session, dep, aliases)
    session.flush()
    audit.record(session, "department.aliases_updated", ctx=ctx, entity_type="department", entity_id=dep.id,
                 changes={"aliases": aliases})
    return dep


def department_aliases(session: Session, company_id: uuid.UUID, department_id: uuid.UUID) -> list[str]:
    return [
        a.alias
        for a in session.execute(
            select(DepartmentAlias).where(DepartmentAlias.company_id == company_id, DepartmentAlias.department_id == department_id)
        ).scalars()
    ]


# --------------------------------------------------------------------------- resolvers (entity matching)

register_resolver(AliasTableResolver(
    entity_type="product", model=Product, alias_model=ProductAlias, alias_fk="product_id",
    label=lambda p: f"{p.code} — {p.name}",
    data=lambda p: {"base_unit": p.base_unit.code if p.base_unit else None, "code": p.code},
))
register_resolver(AliasTableResolver(
    entity_type="department", model=Department, alias_model=DepartmentAlias, alias_fk="department_id",
    label=lambda d: f"{d.name} ({d.code})", data=lambda d: {"kind": d.kind, "code": d.code},
))
register_resolver(ListResolver(
    entity_type="product_category",
    load=lambda s, cid: s.execute(select(ProductCategory).where(ProductCategory.company_id == cid)).scalars().all(),
    label=lambda c: f"{c.name} ({c.code})", keys=lambda c: [c.code, c.name],
))
