"""Supply module manifest."""

from __future__ import annotations

import uuid
from collections.abc import Iterable
from datetime import date
from decimal import Decimal

from pydantic import BaseModel, Field
from sqlalchemy import select

from app.core.manifest import DuplicateCandidate, FieldSpec, Issue, ModuleManifest, NavEntry, RecordTypeSpec, RuleInput
from app.core.matching import AliasTableResolver, register_resolver
from app.core.models import Product, Unit
from app.core.rules import today_for
from app.core.services.master_data import convert, find_unit
from app.modules.supply import service
from app.modules.supply.models import PurchaseRequest, Supplier, SupplierAlias


class PurchaseRequestLineWrite(BaseModel):
    item: str = Field(min_length=1, max_length=300)
    item_id: uuid.UUID | None = None  # matched product, when the item is in the catalog
    quantity: Decimal = Field(gt=0)
    unit: str
    estimated_unit_price: Decimal | None = Field(default=None, ge=0)


class PurchaseRequestWrite(BaseModel):
    request_date: date
    lines: list[PurchaseRequestLineWrite] = Field(min_length=1)
    needed_by: date | None = None
    department_id: uuid.UUID | None = None
    requested_by: str | None = Field(default=None, max_length=200)
    supplier_id: uuid.UUID | None = None
    priority: str | None = None
    currency: str | None = None
    external_number: str | None = Field(default=None, max_length=60)
    notes: str | None = Field(default=None, max_length=5000)
    source_file_id: uuid.UUID | None = None


def pr_rules(inp: RuleInput) -> Iterable[Issue]:
    p = inp.payload
    req, need = p.get("request_date"), p.get("needed_by")
    if req and need and date.fromisoformat(need) < date.fromisoformat(req):
        yield Issue("needed_by_before_request", "error", "Needed-by date is before the request date", "needed_by")
    elif need and date.fromisoformat(need) < today_for(inp):
        yield Issue("needed_by_passed", "warning", f"Needed-by date {need} is already in the past", "needed_by")
    if req and date.fromisoformat(req) > today_for(inp):
        yield Issue("date_in_future", "error", f"Request date {req} is in the future", "request_date")
    lines = p.get("lines") or []
    if not lines:
        yield Issue("no_lines", "error", "The request has no items", "lines")
    if not p.get("supplier"):
        yield Issue("supplier_not_specified", "info", "No supplier specified; procurement will select one", "supplier")
    elif not inp.ref_id("supplier"):
        yield Issue("supplier_not_in_master_data", "warning",
                    f"Supplier '{p['supplier']}' is not in the supplier list", "supplier")
    if lines and all(li.get("estimated_unit_price") in (None, "") for li in lines):
        yield Issue("prices_missing", "info", "No estimated prices given", "lines")
    for i, li in enumerate(lines):
        ref = inp.references.get(f"lines.{i}.item")
        if li.get("item") and not (ref and ref.get("id")):
            yield Issue("item_not_in_catalog", "info", f"Line {i + 1}: '{li['item']}' is not in the product catalog; "
                        "it will be requested by description", f"lines.{i}.item")
        if ref and ref.get("id") and li.get("unit"):
            product = inp.session.get(Product, uuid.UUID(ref["id"]))
            unit = find_unit(inp.session, li["unit"])
            if product and unit and convert(Decimal(1), unit, inp.session.get(Unit, product.base_unit_id)) is None:
                yield Issue("unit_incompatible", "error", f"Line {i + 1}: unit '{unit.code}' does not fit {product.code}",
                            f"lines.{i}.unit")


def match_purchase_request(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    ext = inp.payload.get("external_number")
    if not ext:
        return
    for pr in inp.session.execute(select(PurchaseRequest).where(
        PurchaseRequest.company_id == inp.company_id, PurchaseRequest.external_number == ext, PurchaseRequest.status != "cancelled",
    )).unique().scalars():
        yield DuplicateCandidate("purchase_request", pr.id, f"{pr.number} (document {ext}, {pr.status})", 1.0)


PURCHASE_REQUEST = RecordTypeSpec(
    code="purchase_request",
    module="supply",
    label="Purchase request",
    description=(
        "A request to buy materials or items ('zayavka', 'заявка на закупку', 'xarid uchun ariza'): requesting department, "
        "dates, optional supplier, and a list of items with quantities and units."
    ),
    fields=(
        FieldSpec("external_number", "Document number", "string", max_length=60,
                  aliases=("number", "request no", "№", "raqam", "ariza raqami", "номер", "номер заявки")),
        FieldSpec("request_date", "Request date", "date", required=True, aliases=("date", "sana", "дата", "дата заявки")),
        FieldSpec("needed_by", "Needed by", "date", aliases=("needed by", "deadline", "muddat", "kerak bo'lgan sana", "срок поставки", "срок")),
        FieldSpec("department", "Requesting department", "reference", reference="department", match_required=False,
                  aliases=("department", "bo'lim", "sex", "подразделение", "цех", "отдел")),
        FieldSpec("requested_by", "Requested by", "string", max_length=200,
                  aliases=("requested by", "applicant", "so'rovchi", "mas'ul", "заявитель", "ответственный")),
        FieldSpec("supplier", "Supplier", "reference", reference="supplier", match_required=False,
                  aliases=("supplier", "vendor", "yetkazib beruvchi", "поставщик")),
        FieldSpec("priority", "Priority", "enum", enum=service.PRIORITIES, aliases=("priority", "muhimlik", "приоритет", "срочность")),
        FieldSpec("currency", "Currency", "currency", aliases=("currency", "valyuta", "валюта")),
        FieldSpec("notes", "Notes / purpose", "text", max_length=5000,
                  aliases=("notes", "purpose", "justification", "izoh", "maqsad", "примечание", "обоснование", "цель")),
    ),
    line_fields=(
        FieldSpec("item", "Item", "reference", required=True, reference="product", match_required=False,
                  description="Item name as written; matched against the product catalog when possible",
                  aliases=("item", "description", "product", "material", "nomi", "mahsulot", "наименование", "товар", "материал")),
        FieldSpec("quantity", "Quantity", "decimal", required=True, quantity_unit_field="unit",
                  aliases=("quantity", "qty", "miqdor", "soni", "количество", "кол-во")),
        FieldSpec("unit", "Unit", "unit", required=True, aliases=("unit", "uom", "birlik", "ед. изм", "ед.изм.", "единица")),
        FieldSpec("estimated_unit_price", "Estimated unit price", "decimal",
                  aliases=("price", "unit price", "narx", "narxi", "цена", "цена за ед")),
    ),
    lines_label="Items",
    schema=PurchaseRequestWrite,
    writer=service.write_pr_from_agent,
    write_permission="supply.write",
    approve_permission="supply.write",
    validators=(pr_rules,),
    matcher=match_purchase_request,
    fingerprint_fields=("external_number", "request_date", "department"),
    summary=lambda p: " · ".join(str(x) for x in (p.get("external_number"), p.get("department"),
                                                   f"{len(p.get('lines') or [])} item(s)", p.get("needed_by")) if x),
)

register_resolver(AliasTableResolver(
    entity_type="supplier", model=Supplier, alias_model=SupplierAlias, alias_fk="supplier_id",
    label=lambda s: f"{s.name} ({s.code})", data=lambda s: {"tin": s.tin},
))


def _router():
    from app.modules.supply.router import router

    return router


MANIFEST = ModuleManifest(
    code="supply",
    name="BIWORK Supply",
    description="Suppliers, purchase requests and the procurement approval workflow.",
    permissions=("supply.read", "supply.write", "supply.approve"),
    record_types=(PURCHASE_REQUEST,),
    nav=NavEntry("/supply", "Supply", "truck"),
    phase=2,
    router_factory=_router,
)
