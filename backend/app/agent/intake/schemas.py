"""Typed schemas for AI document intake.

Two layers live here:

* Extraction schemas (``ExtractionEnvelope`` and everything it references) are
  sent to the model as a JSON-schema-constrained output format. They contain
  only what the model can report from the document: values, a confidence flag
  and a pointer back to the source. Every field is required and nullable,
  because structured outputs cannot express "optional", and a missing value
  must be an explicit ``null`` rather than an invented one.
* Result schemas (``IntakeResult`` and friends) are what the API returns after
  deterministic validation. They are never produced by the model.
"""

from __future__ import annotations

from datetime import date, datetime
from enum import StrEnum
from typing import Generic, Literal, TypeVar
from uuid import UUID

from pydantic import BaseModel, Field

T = TypeVar("T")


class DocumentType(StrEnum):
    INVOICE = "invoice"
    WORK_ORDER = "work_order"
    SPECIFICATION = "specification"
    UNKNOWN = "unknown"


Confidence = Literal["high", "low"]


# ---------------------------------------------------------------------------
# Extraction schemas (model output)
# ---------------------------------------------------------------------------


class Extracted(BaseModel, Generic[T]):
    """A single extracted value with provenance."""

    value: T | None = Field(
        description="The value as stated in the document, normalized to the field type. "
        "null when the document does not contain it. Never guess."
    )
    confidence: Confidence = Field(
        description="'high' only when the value is printed clearly and unambiguously. "
        "'low' when it is illegible, handwritten, inferred, ambiguous, or null."
    )
    source_page: int | None = Field(description="1-based page where the value appears, or null.")
    source_text: str | None = Field(
        description="Short verbatim snippet (max ~120 chars) the value was read from, or null."
    )


class InvoiceLine(BaseModel):
    description: str | None
    product_code: str | None = Field(description="SKU, article or part number, verbatim.")
    quantity: float | None
    unit: str | None = Field(description="Unit of measure as written, e.g. 'pcs', 'kg', 'dona'.")
    unit_price: float | None
    line_total: float | None
    confidence: Confidence
    source_page: int | None


class InvoiceExtraction(BaseModel):
    invoice_number: Extracted[str]
    issue_date: Extracted[date]
    due_date: Extracted[date]
    supplier_name: Extracted[str]
    supplier_tax_id: Extracted[str]
    buyer_name: Extracted[str]
    buyer_tax_id: Extracted[str]
    currency: Extracted[str] = Field(description="ISO 4217 code, e.g. UZS, USD, EUR, RUB.")
    line_items: list[InvoiceLine]
    subtotal: Extracted[float] = Field(description="Total before tax.")
    tax_amount: Extracted[float]
    total_amount: Extracted[float] = Field(description="Grand total payable, including tax.")


class WorkOrderOperation(BaseModel):
    sequence: int | None
    name: str | None
    work_center: str | None
    planned_hours: float | None
    confidence: Confidence
    source_page: int | None


class WorkOrderExtraction(BaseModel):
    order_number: Extracted[str]
    product_name: Extracted[str]
    product_code: Extracted[str]
    quantity_ordered: Extracted[float]
    quantity_completed: Extracted[float]
    quantity_rejected: Extracted[float]
    unit: Extracted[str]
    production_unit: Extracted[str] = Field(description="Workshop, line or department, e.g. '1-sex'.")
    responsible_person: Extracted[str]
    start_date: Extracted[date]
    due_date: Extracted[date]
    operations: list[WorkOrderOperation]


class SpecParameter(BaseModel):
    name: str | None
    nominal: float | None
    min_value: float | None
    max_value: float | None
    unit: str | None
    text_requirement: str | None = Field(
        description="Non-numeric requirement as written, e.g. 'no visible cracks'. null for numeric ones."
    )
    confidence: Confidence
    source_page: int | None


class SpecificationExtraction(BaseModel):
    document_number: Extracted[str]
    revision: Extracted[str]
    product_name: Extracted[str]
    product_code: Extracted[str]
    material: Extracted[str]
    issue_date: Extracted[date]
    parameters: list[SpecParameter]


class ExtractionEnvelope(BaseModel):
    """Top-level structured output returned by the extractor."""

    document_type: DocumentType
    document_type_confidence: Confidence
    detected_language: str | None = Field(description="ISO 639-1 code of the main language, e.g. 'uz', 'ru', 'en'.")
    invoice: InvoiceExtraction | None = Field(description="Filled only when document_type is 'invoice'.")
    work_order: WorkOrderExtraction | None = Field(description="Filled only when document_type is 'work_order'.")
    specification: SpecificationExtraction | None = Field(
        description="Filled only when document_type is 'specification'."
    )
    extraction_notes: list[str] = Field(
        description="Uncertainties, contradictions or missing information worth a reviewer's attention."
    )


# ---------------------------------------------------------------------------
# Result schemas (API output)
# ---------------------------------------------------------------------------


class FieldStatus(StrEnum):
    HIGH_CONFIDENCE = "high_confidence"
    LOW_CONFIDENCE = "low_confidence"
    MISSING = "missing"
    CONFLICTING = "conflicting"
    INVALID = "invalid"


class IssueSeverity(StrEnum):
    ERROR = "error"
    WARNING = "warning"


class ValidationIssue(BaseModel):
    field: str | None = Field(description="Dotted path into the extraction, e.g. 'invoice.total_amount'.")
    code: str
    severity: IssueSeverity
    message: str


class FieldReport(BaseModel):
    field: str
    value: object | None
    status: FieldStatus
    source_page: int | None = None
    source_text: str | None = None


class IntakeStatus(StrEnum):
    READY_FOR_APPROVAL = "ready_for_approval"
    NEEDS_REVIEW = "needs_review"
    NEEDS_CORRECTION = "needs_correction"
    UNSUPPORTED_DOCUMENT = "unsupported_document"


class SourceInfo(BaseModel):
    kind: Literal["file", "text"]
    filename: str | None
    media_type: str
    size_bytes: int
    sha256: str


class ExtractorInfo(BaseModel):
    provider: str
    model: str
    prompt_version: str
    is_mock: bool
    input_tokens: int | None = None
    output_tokens: int | None = None


class IntakeSummary(BaseModel):
    fields_extracted: int
    fields_high_confidence: int
    fields_needing_attention: int
    errors: int
    warnings: int


class IntakeResult(BaseModel):
    ingestion_id: UUID
    received_at: datetime
    source: SourceInfo
    extractor: ExtractorInfo
    document_type: DocumentType
    status: IntakeStatus
    requires_review: bool = Field(
        description="Always true until an administrator enables auto-approval for a workflow."
    )
    summary: IntakeSummary
    fields: list[FieldReport]
    issues: list[ValidationIssue]
    extraction: ExtractionEnvelope
