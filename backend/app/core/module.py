"""Core's own manifest: master data and tasks are ingestible record types too."""

from __future__ import annotations

import uuid
from collections.abc import Iterable
from datetime import date
from decimal import Decimal

from pydantic import BaseModel, Field
from sqlalchemy import func, select

from app.core.manifest import DuplicateCandidate, FieldSpec, Issue, ModuleManifest, RecordTypeSpec, RuleInput
from app.core.models import BusinessDocument, Employee, Product, ProductAlias, Task
from app.core.rules import today_for
from app.core.services import documents, master_data, personnel, tasks
from app.core.text import normalize_key


class ProductWrite(BaseModel):
    code: str = Field(min_length=1, max_length=64)
    name: str = Field(min_length=1, max_length=250)
    base_unit: str
    product_type: str | None = None
    category_id: uuid.UUID | None = None
    reorder_level: Decimal | None = Field(default=None, ge=0)
    alias: str | None = Field(default=None, max_length=250)


class TaskWrite(BaseModel):
    title: str = Field(min_length=1, max_length=300)
    description: str | None = Field(default=None, max_length=5000)
    assignee_id: uuid.UUID | None = None
    department_id: uuid.UUID | None = None
    due_date: date | None = None
    priority: str | None = None


def match_product(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    code, name = inp.payload.get("code"), inp.payload.get("name")
    if code:
        for p in inp.session.execute(select(Product).where(
            Product.company_id == inp.company_id, func.lower(Product.code) == code.lower(), Product.deleted_at.is_(None),
        )).scalars():
            yield DuplicateCandidate("product", p.id, f"{p.code} — {p.name}", 1.0)
    if name:
        rows = inp.session.execute(select(Product).join(ProductAlias, ProductAlias.product_id == Product.id).where(
            ProductAlias.company_id == inp.company_id, ProductAlias.normalized_alias == normalize_key(name),
            func.lower(Product.code) != (code or "").lower(),
        )).unique().scalars()
        for p in rows:
            yield DuplicateCandidate("product", p.id, f"{p.code} — {p.name} (same name, different code)", 0.8, blocking=False)


def product_rules(inp: RuleInput) -> Iterable[Issue]:
    if inp.payload.get("category") and not inp.ref_id("category"):
        yield Issue("category_not_found", "warning", f"Category '{inp.payload['category']}' does not exist; product will be "
                    "created without a category", "category")


def match_task(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    title = inp.payload.get("title")
    if not title:
        return
    for t in inp.session.execute(select(Task).where(
        Task.company_id == inp.company_id, func.lower(Task.title) == title.lower(), Task.status.in_(("open", "in_progress")),
    )).scalars():
        yield DuplicateCandidate("task", t.id, f"{t.number}: {t.title}", 0.9, blocking=False)


def task_rules(inp: RuleInput) -> Iterable[Issue]:
    due = inp.payload.get("due_date")
    if due and date.fromisoformat(due) < today_for(inp):
        yield Issue("due_date_passed", "warning", f"Due date {due} is in the past", "due_date")
    if inp.payload.get("assignee") and not inp.ref_id("assignee"):
        yield Issue("assignee_not_found", "warning", f"'{inp.payload['assignee']}' is not a user of this company; "
                    "the task will be unassigned", "assignee")


PRODUCT = RecordTypeSpec(
    code="product", module="core", label="Product (master data)",
    description="A catalog item definition: product/material code, name, unit of measure, type, category.",
    fields=(
        FieldSpec("code", "Code", "string", required=True, max_length=64,
                  aliases=("code", "product code", "sku", "item code", "kod", "artikul", "код", "артикул")),
        FieldSpec("name", "Name", "string", required=True, max_length=250,
                  aliases=("name", "product name", "title", "nomi", "mahsulot nomi", "наименование", "название")),
        FieldSpec("base_unit", "Base unit", "unit", required=True,
                  aliases=("unit", "uom", "base unit", "birlik", "o'lchov birligi", "ед. изм", "единица")),
        FieldSpec("product_type", "Type", "enum", enum=master_data.PRODUCT_TYPES, aliases=("type", "product type", "turi", "тип", "вид")),
        FieldSpec("category", "Category", "reference", reference="product_category", match_required=False,
                  aliases=("category", "group", "kategoriya", "guruh", "категория", "группа")),
        FieldSpec("reorder_level", "Reorder level", "decimal",
                  aliases=("reorder level", "min stock", "minimum", "minimal qoldiq", "мин. остаток", "минимальный остаток")),
        FieldSpec("alias", "Alternative name", "string", max_length=250, aliases=("alias", "other name", "boshqa nomi", "синоним")),
    ),
    schema=ProductWrite, writer=master_data.write_product_from_agent, write_permission="master.write",
    approve_permission="master.approve", validators=(product_rules,), matcher=match_product, fingerprint_fields=("code",),
    summary=lambda p: " · ".join(str(x) for x in (p.get("code"), p.get("name"), p.get("base_unit")) if x),
)

TASK = RecordTypeSpec(
    code="task", module="core", label="Task",
    description="An assignment or instruction for a person or department, with an optional deadline.",
    fields=(
        FieldSpec("title", "Title", "string", required=True, max_length=300, aliases=("task", "title", "vazifa", "topshiriq", "задача")),
        FieldSpec("description", "Description", "text", max_length=5000, aliases=("description", "details", "tavsif", "описание")),
        FieldSpec("assignee", "Assignee", "reference", reference="user", match_required=False,
                  aliases=("assignee", "responsible", "mas'ul", "ijrochi", "исполнитель", "ответственный")),
        FieldSpec("department", "Department", "reference", reference="department", match_required=False,
                  aliases=("department", "bo'lim", "sex", "отдел", "цех")),
        FieldSpec("due_date", "Due date", "date", aliases=("due date", "deadline", "muddat", "срок")),
        FieldSpec("priority", "Priority", "enum", enum=tasks.PRIORITIES, aliases=("priority", "muhimlik", "приоритет")),
    ),
    schema=TaskWrite, writer=tasks.write_task_from_agent, write_permission="tasks.write", approve_permission="tasks.approve",
    validators=(task_rules,), matcher=match_task, fingerprint_fields=("title", "due_date", "assignee"),
    summary=lambda p: " · ".join(str(x) for x in (p.get("title"), p.get("assignee"), p.get("due_date")) if x),
)

class EmployeeWrite(BaseModel):
    full_name: str = Field(min_length=2, max_length=250)
    personnel_number: str | None = Field(default=None, max_length=40)
    position: str | None = Field(default=None, max_length=200)
    department_id: uuid.UUID | None = None
    hire_date: date | None = None
    phone: str | None = Field(default=None, max_length=50)
    status: str | None = None


def match_employee(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    number, name = inp.payload.get("personnel_number"), inp.payload.get("full_name")
    if number:
        for e in inp.session.execute(select(Employee).where(
            Employee.company_id == inp.company_id, Employee.personnel_number == number,
        )).scalars():
            yield DuplicateCandidate("employee", e.id, f"{e.personnel_number} — {e.full_name}", 1.0)
    if name:
        for e in inp.session.execute(select(Employee).where(
            Employee.company_id == inp.company_id, Employee.normalized_name == normalize_key(name),
            func.coalesce(Employee.personnel_number, "") != (number or ""),
        )).scalars():
            yield DuplicateCandidate("employee", e.id, f"{e.full_name} ({e.position or 'no position'}) — same name", 0.85,
                                     blocking=False)


def employee_rules(inp: RuleInput) -> Iterable[Issue]:
    hire = inp.payload.get("hire_date")
    if hire and date.fromisoformat(hire) > today_for(inp):
        yield Issue("hire_date_in_future", "warning", f"Hire date {hire} is in the future", "hire_date")
    name = inp.payload.get("full_name") or ""
    if name and len(name.split()) < 2:
        yield Issue("name_incomplete", "warning", "Only one name part was found; check the full name", "full_name")
    if inp.payload.get("department") and not inp.ref_id("department"):
        yield Issue("department_not_found", "warning", f"Department '{inp.payload['department']}' does not exist", "department")


class DocumentLineWrite(BaseModel):
    item: str = Field(min_length=1, max_length=300)
    quantity: Decimal | None = Field(default=None, ge=0)
    unit: str | None = None
    unit_price: Decimal | None = Field(default=None, ge=0)
    amount: Decimal | None = Field(default=None, ge=0)


class DocumentWrite(BaseModel):
    doc_type: str | None = None
    number: str = Field(min_length=1, max_length=60)
    doc_date: date
    counterparty: str | None = Field(default=None, max_length=250)
    counterparty_id: uuid.UUID | None = None
    total_amount: Decimal | None = Field(default=None, ge=0)
    currency: str | None = None
    description: str | None = Field(default=None, max_length=5000)
    lines: list[DocumentLineWrite] = Field(default_factory=list)
    source_file_id: uuid.UUID | None = None


MONEY_TOLERANCE = Decimal("0.01")


def _dec(value) -> Decimal | None:
    return Decimal(str(value)) if value not in (None, "") else None


def document_rules(inp: RuleInput) -> Iterable[Issue]:
    p = inp.payload
    if p.get("doc_date") and date.fromisoformat(p["doc_date"]) > today_for(inp):
        yield Issue("date_in_future", "error", f"Document date {p['doc_date']} is in the future", "doc_date")
    lines = p.get("lines") or []
    total = inp.decimal("total_amount")
    amounts = []
    for i, li in enumerate(lines):
        qty, price, amount = _dec(li.get("quantity")), _dec(li.get("unit_price")), _dec(li.get("amount"))
        if qty is not None and price is not None and amount is not None:
            expected = (qty * price).quantize(Decimal("0.01"))
            if abs(expected - amount) > max(MONEY_TOLERANCE, amount * Decimal("0.005")):
                yield Issue("line_amount_mismatch", "error", f"Line {i + 1}: {qty} × {price} = {expected}, but the amount is "
                            f"{amount}", f"lines.{i}.amount")
        amounts.append(amount if amount is not None else (qty * price if qty is not None and price is not None else None))
    if total is not None and amounts and all(a is not None for a in amounts):
        line_sum = sum(amounts, Decimal(0)).quantize(Decimal("0.01"))
        if abs(line_sum - total) > max(MONEY_TOLERANCE, total * Decimal("0.005")):
            yield Issue("total_mismatch", "error", f"Line amounts add up to {line_sum}, but the document total is {total}",
                        "total_amount")
    if (total is not None or amounts) and not p.get("currency"):
        yield Issue("currency_missing", "warning", "The document has amounts but no currency", "currency")
    if p.get("counterparty") and not inp.ref_id("counterparty"):
        yield Issue("counterparty_not_in_master_data", "warning",
                    f"Counterparty '{p['counterparty']}' is not in the supplier list", "counterparty")


def match_document(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    number = inp.payload.get("number")
    if not number:
        return
    key = normalize_key(inp.payload.get("counterparty") or "")
    for d in inp.session.execute(select(BusinessDocument).where(
        BusinessDocument.company_id == inp.company_id, func.lower(BusinessDocument.number) == number.lower(),
    )).scalars():
        doc_type = inp.payload.get("doc_type")
        same = d.counterparty_key == key and (doc_type is None or d.doc_type == doc_type)  # unknown type: same number + party
        yield DuplicateCandidate("business_document", d.id, f"{d.doc_type} {d.number} of {d.doc_date.isoformat()}"
                                 f"{' from ' + d.counterparty if d.counterparty else ''}", 1.0 if same else 0.7, blocking=same)


EMPLOYEE = RecordTypeSpec(
    code="employee", module="core", label="Employee",
    description="A person in the company's personnel register: full name, personnel (tabel) number, position, department, "
                "hire date. Not a login account.",
    fields=(
        FieldSpec("full_name", "Full name", "string", required=True, max_length=250,
                  aliases=("full name", "name", "employee", "f.i.sh", "fish", "xodim", "ф.и.о", "фио", "сотрудник")),
        FieldSpec("personnel_number", "Personnel number", "string", max_length=40,
                  aliases=("personnel number", "employee id", "tabel", "tabel raqami", "табельный номер", "таб. №", "таб №")),
        FieldSpec("position", "Position", "string", max_length=200, aliases=("position", "job title", "lavozim", "должность")),
        FieldSpec("department", "Department", "reference", reference="department", match_required=False,
                  aliases=("department", "bo'lim", "sex", "отдел", "цех", "подразделение")),
        FieldSpec("hire_date", "Hire date", "date",
                  aliases=("hire date", "hired", "ishga qabul qilingan sana", "ishga kirgan sana", "дата приёма", "дата приема")),
        FieldSpec("phone", "Phone", "string", max_length=50, aliases=("phone", "telefon", "телефон")),
        FieldSpec("status", "Status", "enum", enum=personnel.EMPLOYEE_STATUSES, aliases=("status", "holat", "статус")),
    ),
    schema=EmployeeWrite, writer=personnel.write_employee_from_agent, write_permission="master.write",
    approve_permission="master.approve", validators=(employee_rules,), matcher=match_employee,
    fingerprint_fields=("personnel_number", "full_name"),
    summary=lambda p: " · ".join(str(x) for x in (p.get("personnel_number"), p.get("full_name"), p.get("position")) if x),
)

BUSINESS_DOCUMENT = RecordTypeSpec(
    code="business_document", module="core", label="Business document",
    description="A business document to register: invoice (hisob-faktura, счёт-фактура), waybill (nakladnoy, накладная), "
                "contract (shartnoma, договор), act (dalolatnoma, акт), specification; number, date, counterparty, total, "
                "currency and optional item lines.",
    fields=(
        FieldSpec("doc_type", "Document type", "enum", enum=documents.DOCUMENT_TYPES,
                  aliases=("document type", "type", "hujjat turi", "тип документа", "вид документа")),
        FieldSpec("number", "Number", "string", required=True, max_length=60,
                  aliases=("number", "no", "№", "raqam", "raqami", "номер")),
        FieldSpec("doc_date", "Date", "date", required=True, aliases=("date", "sana", "дата", "от")),
        FieldSpec("counterparty", "Counterparty", "reference", reference="supplier", match_required=False,
                  aliases=("supplier", "seller", "counterparty", "vendor", "yetkazib beruvchi", "sotuvchi", "поставщик",
                           "продавец", "контрагент")),
        FieldSpec("total_amount", "Total amount", "decimal",
                  aliases=("total", "total amount", "jami", "umumiy summa", "итого", "всего", "сумма")),
        FieldSpec("currency", "Currency", "currency", aliases=("currency", "valyuta", "валюта")),
        FieldSpec("description", "Description", "text", max_length=5000, aliases=("description", "subject", "izoh", "описание",
                                                                                  "предмет")),
    ),
    line_fields=(
        FieldSpec("item", "Item", "string", required=True, max_length=300,
                  aliases=("item", "description", "product", "nomi", "mahsulot", "наименование", "товар")),
        FieldSpec("quantity", "Quantity", "decimal", quantity_unit_field="unit",
                  aliases=("quantity", "qty", "miqdor", "soni", "количество", "кол-во")),
        FieldSpec("unit", "Unit", "unit", aliases=("unit", "uom", "birlik", "ед. изм", "ед.изм.", "единица")),
        FieldSpec("unit_price", "Unit price", "decimal", aliases=("price", "unit price", "narx", "narxi", "цена")),
        FieldSpec("amount", "Amount", "decimal", aliases=("amount", "sum", "summa", "сумма", "стоимость")),
    ),
    lines_label="Items",
    schema=DocumentWrite, writer=documents.write_document_from_agent, write_permission="master.write",
    approve_permission="master.approve", validators=(document_rules,), matcher=match_document,
    fingerprint_fields=("doc_type", "number", "counterparty"),
    summary=lambda p: " · ".join(str(x) for x in (p.get("doc_type"), p.get("number"), p.get("doc_date"), p.get("counterparty"),
                                                   f"{p.get('total_amount')} {p.get('currency') or ''}".strip()
                                                   if p.get("total_amount") else None) if x),
)

CORE_MANIFEST = ModuleManifest(
    code="core", name="BIWORK Core", description="Identity, companies, master data, files, tasks, audit.",
    record_types=(PRODUCT, TASK, EMPLOYEE, BUSINESS_DOCUMENT), nav=None, phase=1,
)
