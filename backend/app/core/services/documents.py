"""Register of business documents (invoices, waybills, contracts, acts, specifications)."""

from __future__ import annotations

import uuid
from datetime import date
from decimal import Decimal
from typing import Any

from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict
from app.core.manifest import WriteResult
from app.core.models import BusinessDocument
from app.core.text import clean_text, normalize_key

DOCUMENT_TYPES = ("invoice", "waybill", "contract", "act", "specification", "other")


class DocumentLineIn(BaseModel):
    item: str = Field(min_length=1, max_length=300)
    quantity: Decimal | None = Field(default=None, ge=0)
    unit: str | None = None
    unit_price: Decimal | None = Field(default=None, ge=0)
    amount: Decimal | None = Field(default=None, ge=0)


class DocumentIn(BaseModel):
    doc_type: str
    number: str = Field(min_length=1, max_length=60)
    doc_date: date
    counterparty: str | None = Field(default=None, max_length=250)
    supplier_id: uuid.UUID | None = None
    total_amount: Decimal | None = Field(default=None, ge=0)
    currency: str | None = Field(default=None, max_length=3)
    description: str | None = Field(default=None, max_length=5000)
    lines: list[DocumentLineIn] = Field(default_factory=list)
    source_file_id: uuid.UUID | None = None


def _json(value: Any) -> Any:
    return str(value) if isinstance(value, Decimal) else value


def create_document(session: Session, ctx: RequestContext, data: DocumentIn, source_record_id: uuid.UUID | None = None) -> BusinessDocument:
    ctx.require("master.write")
    doc_type = data.doc_type if data.doc_type in DOCUMENT_TYPES else "other"
    key = normalize_key(data.counterparty or "")[:250]
    if session.execute(select(BusinessDocument.id).where(
        BusinessDocument.company_id == ctx.company_id, BusinessDocument.doc_type == doc_type,
        BusinessDocument.number == data.number, BusinessDocument.counterparty_key == key,
    )).first():
        raise Conflict(f"{doc_type.title()} {data.number} from this counterparty is already registered", code="duplicate_document")
    doc = BusinessDocument(
        company_id=ctx.company_id, doc_type=doc_type, number=data.number, doc_date=data.doc_date,
        counterparty=clean_text(data.counterparty, 250), counterparty_key=key, supplier_id=data.supplier_id,
        total_amount=data.total_amount, currency=data.currency, description=clean_text(data.description),
        lines=[{k: _json(v) for k, v in line.model_dump().items()} for line in data.lines],
        source_file_id=data.source_file_id, created_by=ctx.user_id, updated_by=ctx.user_id, source_record_id=source_record_id,
    )
    session.add(doc)
    session.flush()
    audit.record(session, "document.registered", ctx=ctx, entity_type="business_document", entity_id=doc.id,
                 summary=f"Document {doc.number} ({doc.doc_type}) registered",
                 changes={"doc_type": doc.doc_type, "number": doc.number, "doc_date": doc.doc_date.isoformat(),
                          "counterparty": doc.counterparty, "total_amount": _json(doc.total_amount), "currency": doc.currency})
    return doc


def write_document_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    p = data.model_dump()
    doc = create_document(session, ctx, DocumentIn(
        doc_type=p.get("doc_type") or "other", number=p["number"], doc_date=p["doc_date"], counterparty=p.get("counterparty"),
        supplier_id=p.get("counterparty_id"), total_amount=p.get("total_amount"), currency=p.get("currency"),
        description=p.get("description"), source_file_id=p.get("source_file_id"),
        lines=[DocumentLineIn(**{k: li.get(k) for k in ("item", "quantity", "unit", "unit_price", "amount")}) for li in p.get("lines") or []],
    ), source_record_id=source_record_id)
    return WriteResult("core.business_documents", doc.id, doc.number, f"{doc.doc_type} {doc.number} ({doc.doc_date.isoformat()})")
