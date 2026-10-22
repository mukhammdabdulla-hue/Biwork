"""Production module manifest: permissions, ingestible record types, navigation."""

from __future__ import annotations

import uuid
from datetime import date
from decimal import Decimal

from pydantic import BaseModel, Field

from app.core.manifest import FieldSpec, ModuleManifest, NavEntry, RecordTypeSpec
from app.modules.production import matchers, rules, service


class ProductionReportWrite(BaseModel):
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


class ProductionOrderWrite(BaseModel):
    product_id: uuid.UUID
    planned_qty: Decimal = Field(gt=0)
    unit: str | None = None
    department_id: uuid.UUID | None = None
    line_id: uuid.UUID | None = None
    planned_start: date | None = None
    planned_end: date | None = None
    priority: str | None = None
    external_number: str | None = Field(default=None, max_length=60)
    notes: str | None = Field(default=None, max_length=2000)


PRODUCTION_REPORT = RecordTypeSpec(
    code="production_report",
    module="production",
    label="Production report",
    description=(
        "A report of manufacturing output for one product, production unit (workshop / 'sex' / 'цех') and date: "
        "quantity produced, quantity accepted by quality control, quantity defective ('brak')."
    ),
    fields=(
        FieldSpec("production_date", "Production date", "date", required=True,
                  aliases=("date", "production date", "sana", "kun", "ishlab chiqarish sanasi", "дата", "дата производства", "дата выпуска")),
        FieldSpec("product", "Product", "reference", required=True, reference="product",
                  aliases=("product", "item", "product code", "sku", "mahsulot", "mahsulot nomi", "mahsulot kodi", "artikul",
                           "продукт", "продукция", "изделие", "наименование", "артикул", "код продукции")),
        FieldSpec("department", "Production unit", "reference", required=True, reference="department",
                  description="Workshop / production unit, e.g. '1-sex', 'Цех №1'",
                  aliases=("production unit", "workshop", "department", "sex", "sex nomi", "bo'lim", "uchastka",
                           "цех", "участок", "подразделение")),
        FieldSpec("shift", "Shift", "reference", reference="shift", aliases=("shift", "smena", "смена")),
        FieldSpec("line", "Production line", "reference", reference="production_line", aliases=("line", "liniya", "линия")),
        FieldSpec("order", "Production order", "reference", reference="production_order",
                  aliases=("order", "order no", "production order", "buyurtma", "zakaz", "заказ", "наряд", "номер заказа")),
        FieldSpec("qty_produced", "Quantity produced", "decimal", required=True, quantity_unit_field="unit",
                  aliases=("produced", "quantity", "qty", "total", "output", "ishlab chiqarildi", "ishlab chiqarilgan", "miqdor",
                           "jami", "выпущено", "произведено", "количество", "выпуск", "всего")),
        FieldSpec("qty_accepted", "Quantity accepted", "decimal", quantity_unit_field="unit",
                  aliases=("accepted", "good", "passed qc", "sifatdan o'tdi", "sifatli", "qabul qilindi", "принято", "годные", "годных", "отк")),
        FieldSpec("qty_defective", "Quantity defective", "decimal", quantity_unit_field="unit",
                  aliases=("defective", "defects", "rejected", "scrap", "brak", "yaroqsiz", "nuqsonli", "брак", "дефект", "отбраковано")),
        FieldSpec("unit", "Unit", "unit", aliases=("unit", "uom", "o'lchov birligi", "birlik", "ед. изм", "ед.изм.", "единица", "ед")),
        FieldSpec("notes", "Notes", "text", max_length=2000, aliases=("notes", "comment", "izoh", "примечание", "комментарий")),
    ),
    schema=ProductionReportWrite,
    writer=service.write_report_from_agent,
    write_permission="production.write",
    approve_permission="production.approve",
    validators=(rules.quantities_reconcile, rules.date_plausible("production_date"), rules.unit_compatible,
                rules.department_is_workshop, rules.order_consistent, rules.open_orders_for, rules.output_warehouse_set),
    matcher=matchers.match_production_report,
    fingerprint_fields=("production_date", "product", "department", "shift"),
    summary=lambda p: " · ".join(str(x) for x in (p.get("product"), f"{p.get('qty_produced') or '?'} {p.get('unit') or ''}".strip(),
                                                   p.get("department"), p.get("production_date")) if x),
)

PRODUCTION_ORDER = RecordTypeSpec(
    code="production_order",
    module="production",
    label="Production order",
    description="An instruction to manufacture a planned quantity of a product, usually with a start date and deadline.",
    fields=(
        FieldSpec("external_number", "Order number (source)", "string", max_length=60,
                  aliases=("order number", "order no", "number", "№", "buyurtma raqami", "raqam", "номер", "номер заказа")),
        FieldSpec("product", "Product", "reference", required=True, reference="product",
                  aliases=("product", "item", "mahsulot", "продукт", "продукция", "наименование", "изделие")),
        FieldSpec("planned_qty", "Planned quantity", "decimal", required=True, quantity_unit_field="unit",
                  aliases=("planned quantity", "quantity", "qty", "plan", "reja", "miqdor", "план", "количество")),
        FieldSpec("unit", "Unit", "unit", aliases=("unit", "uom", "birlik", "ед. изм", "единица")),
        FieldSpec("department", "Production unit", "reference", reference="department",
                  aliases=("production unit", "workshop", "sex", "цех", "участок")),
        FieldSpec("line", "Production line", "reference", reference="production_line", aliases=("line", "liniya", "линия")),
        FieldSpec("planned_start", "Planned start", "date", aliases=("start", "start date", "boshlanish", "начало", "дата начала")),
        FieldSpec("planned_end", "Planned end / deadline", "date",
                  aliases=("end", "deadline", "due date", "muddat", "tugash", "срок", "окончание", "дата окончания")),
        FieldSpec("priority", "Priority", "enum", enum=service.PRIORITIES, aliases=("priority", "muhimlik", "приоритет")),
        FieldSpec("notes", "Notes", "text", max_length=2000, aliases=("notes", "comment", "izoh", "примечание")),
    ),
    schema=ProductionOrderWrite,
    writer=service.write_order_from_agent,
    write_permission="production.write",
    approve_permission="production.approve",
    validators=(rules.order_dates, rules.unit_compatible, rules.department_is_workshop),
    matcher=matchers.match_production_order,
    fingerprint_fields=("external_number", "product", "planned_qty", "planned_start"),
    summary=lambda p: " · ".join(str(x) for x in (p.get("external_number"), p.get("product"),
                                                   f"{p.get('planned_qty') or '?'} {p.get('unit') or ''}".strip(), p.get("planned_end")) if x),
)


def _router():
    from app.modules.production.router import router

    return router


MANIFEST = ModuleManifest(
    code="production",
    name="BIWORK Production",
    description="Production orders, production reports, lines, shifts, plan-versus-actual monitoring.",
    permissions=("production.read", "production.write", "production.approve"),
    record_types=(PRODUCTION_REPORT, PRODUCTION_ORDER),
    nav=NavEntry("/production", "Production", "factory"),
    phase=2,
    router_factory=_router,
)
