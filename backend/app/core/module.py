"""Core's own manifest: master data and tasks are ingestible record types too."""

from __future__ import annotations

import uuid
from collections.abc import Iterable
from datetime import date
from decimal import Decimal

from pydantic import BaseModel, Field
from sqlalchemy import func, select

from app.core.manifest import DuplicateCandidate, FieldSpec, Issue, ModuleManifest, RecordTypeSpec, RuleInput
from app.core.models import Product, ProductAlias, Task
from app.core.rules import today_for
from app.core.services import master_data, tasks
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

CORE_MANIFEST = ModuleManifest(
    code="core", name="BIWORK Core", description="Identity, companies, master data, files, tasks, audit.",
    record_types=(PRODUCT, TASK), nav=None, phase=1,
)
