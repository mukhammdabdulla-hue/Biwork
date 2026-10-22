"""Inventory module manifest."""

from __future__ import annotations

import uuid
from collections.abc import Iterable
from datetime import date
from decimal import Decimal

from pydantic import BaseModel, Field
from sqlalchemy import select

from app.core.manifest import DuplicateCandidate, FieldSpec, Issue, ModuleManifest, NavEntry, RecordTypeSpec, RuleInput
from app.core.matching import ListResolver, register_resolver
from app.core.rules import date_plausible, unit_compatible
from app.core.text import fmt_qty
from app.modules.inventory import service
from app.modules.inventory.models import StockBalance, StockMovement, Warehouse


class StockMovementWrite(BaseModel):
    movement_date: date
    movement_type: str
    warehouse_id: uuid.UUID
    product_id: uuid.UUID
    quantity: Decimal = Field(gt=0)
    unit: str
    batch_code: str | None = Field(default=None, max_length=64)
    reference: str | None = Field(default=None, max_length=100)
    notes: str | None = Field(default=None, max_length=2000)


def sufficient_stock(inp: RuleInput) -> Iterable[Issue]:
    if service.MOVEMENT_DIRECTIONS.get(inp.payload.get("movement_type") or "", 1) > 0:
        return
    wh, product, qty = inp.ref_id("warehouse"), inp.ref_id("product"), inp.decimal("quantity")
    if not (wh and product and qty):
        return
    on_hand = sum(
        (b.qty for b in inp.session.execute(select(StockBalance).where(
            StockBalance.company_id == inp.company_id, StockBalance.warehouse_id == wh, StockBalance.product_id == product,
        )).scalars()),
        Decimal(0),
    )
    if qty > on_hand:
        yield Issue("insufficient_stock", "warning",
                    f"Only {fmt_qty(on_hand)} on hand (base unit); this issue would make the balance negative", "quantity")


def match_stock_movement(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    ref, product, wh = inp.payload.get("reference"), inp.ref_id("product"), inp.ref_id("warehouse")
    if not (product and wh and inp.payload.get("movement_date")):
        return
    stmt = select(StockMovement).where(
        StockMovement.company_id == inp.company_id, StockMovement.product_id == product, StockMovement.warehouse_id == wh,
        StockMovement.movement_type == inp.payload.get("movement_type"),
        StockMovement.movement_date == date.fromisoformat(inp.payload["movement_date"]), StockMovement.reverses_id.is_(None),
    )
    for m in inp.session.execute(stmt).scalars():
        same_ref = bool(ref) and m.reference == ref
        same_qty = inp.decimal("quantity") is not None and abs(m.qty) == inp.decimal("quantity")
        if same_ref or same_qty:
            yield DuplicateCandidate("stock_movement", m.id, f"{m.number}: {m.movement_type} {fmt_qty(abs(m.qty))} {m.unit.code}",
                                     1.0 if same_ref else 0.85, blocking=same_ref)


STOCK_MOVEMENT = RecordTypeSpec(
    code="stock_movement",
    module="inventory",
    label="Stock movement",
    description="A warehouse receipt, issue or adjustment of a quantity of a product (raw materials or finished goods).",
    fields=(
        FieldSpec("movement_date", "Date", "date", required=True, aliases=("date", "sana", "дата", "дата операции")),
        FieldSpec("movement_type", "Movement type", "enum", required=True, enum=tuple(service.MOVEMENT_DIRECTIONS),
                  description="receipt (kirim / приход), issue (chiqim / расход), adjustment_in, adjustment_out",
                  aliases=("type", "operation", "movement type", "operatsiya", "turi", "операция", "тип", "вид операции")),
        FieldSpec("warehouse", "Warehouse", "reference", required=True, reference="warehouse",
                  aliases=("warehouse", "store", "ombor", "sklad", "склад")),
        FieldSpec("product", "Product", "reference", required=True, reference="product",
                  aliases=("product", "item", "material", "mahsulot", "material", "xomashyo", "товар", "материал", "номенклатура", "наименование")),
        FieldSpec("quantity", "Quantity", "decimal", required=True, quantity_unit_field="unit",
                  aliases=("quantity", "qty", "miqdor", "soni", "количество", "кол-во")),
        FieldSpec("unit", "Unit", "unit", required=True, aliases=("unit", "uom", "birlik", "ед. изм", "единица", "ед")),
        FieldSpec("batch_code", "Batch", "string", max_length=64, aliases=("batch", "lot", "partiya", "партия")),
        FieldSpec("reference", "Document reference", "string", max_length=100,
                  aliases=("reference", "document", "doc no", "hujjat", "nakladnoy", "накладная", "документ", "№ документа")),
        FieldSpec("notes", "Notes", "text", max_length=2000, aliases=("notes", "izoh", "примечание")),
    ),
    schema=StockMovementWrite,
    writer=service.write_movement_from_agent,
    write_permission="inventory.write",
    approve_permission="inventory.approve",
    validators=(date_plausible("movement_date"), unit_compatible, sufficient_stock),
    matcher=match_stock_movement,
    fingerprint_fields=("movement_date", "movement_type", "warehouse", "product", "quantity", "reference"),
    summary=lambda p: " · ".join(str(x) for x in (p.get("movement_type"), p.get("product"),
                                                   f"{p.get('quantity') or '?'} {p.get('unit') or ''}".strip(), p.get("warehouse")) if x),
)

register_resolver(ListResolver(
    entity_type="warehouse",
    load=lambda s, cid: s.execute(select(Warehouse).where(Warehouse.company_id == cid, Warehouse.is_active.is_(True))).scalars().all(),
    label=lambda w: f"{w.name} ({w.code})", keys=lambda w: [w.code, w.name], data=lambda w: {"kind": w.kind},
    permissions=("inventory.read",),
))


def _router():
    from app.modules.inventory.router import router

    return router


MANIFEST = ModuleManifest(
    code="inventory",
    name="BIWORK Inventory",
    description="Warehouses, stock movements, balances, batches, shortages.",
    permissions=("inventory.read", "inventory.write", "inventory.approve"),
    record_types=(STOCK_MOVEMENT,),
    nav=NavEntry("/inventory", "Inventory", "warehouse"),
    phase=2,
    router_factory=_router,
)
