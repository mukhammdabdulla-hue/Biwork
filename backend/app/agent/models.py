"""Agent schema (docs/architecture.md §4.3): every saved business record can be walked back to the
input that produced it."""

from __future__ import annotations

import uuid
from datetime import datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import BigInteger, Boolean, ForeignKey, Index, Integer, Numeric, String, Text, UniqueConstraint, func, text
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base, Tenant, Timestamps, UUIDPk

AGENT = {"schema": "agent"}

# Record status lifecycle (§4.3): draft -> needs_review -> approved | rejected -> committed | failed
RECORD_STATUSES = ("draft", "needs_review", "approved", "rejected", "committed", "failed")
# Review groups shown in the workspace (§9)
REVIEW_GROUPS = ("ready", "needs_correction", "needs_approval")
# Field states (§6.5)
FIELD_STATES = ("high_confidence", "low_confidence", "missing", "conflicting", "invalid", "needs_approval")


class IngestionJob(UUIDPk, Timestamps, Tenant, Base):
    __tablename__ = "ingestion_jobs"
    __table_args__ = (
        UniqueConstraint("company_id", "idempotency_key"),
        Index("ix_ingestion_jobs_company_created", "company_id", "created_at"),
        # Whole-upload duplicate protection (Scenario D): one live job per content fingerprint,
        # unless the user explicitly forced re-processing.
        Index(
            "uq_ingestion_jobs_fingerprint_live",
            "company_id",
            "content_fingerprint",
            unique=True,
            postgresql_where=text("status NOT IN ('failed', 'cancelled') AND NOT force_reprocess"),
        ),
        AGENT,
    )

    submitted_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    api_client_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.api_clients.id", ondelete="SET NULL"))
    channel: Mapped[str] = mapped_column(String(20))  # web|api|email|telegram
    source_kind: Mapped[str] = mapped_column(String(20))  # text|file|form|api
    title: Mapped[str | None] = mapped_column(String(300))
    # queued|processing|awaiting_review|committing|completed|failed|cancelled
    status: Mapped[str] = mapped_column(String(20), default="queued", index=True)
    current_stage: Mapped[str | None] = mapped_column(String(20))
    import_policy: Mapped[str] = mapped_column(String(24), default="valid_rows_only")
    target_record_type: Mapped[str | None] = mapped_column(String(50))
    idempotency_key: Mapped[str | None] = mapped_column(String(120))
    content_fingerprint: Mapped[str] = mapped_column(String(64))
    force_reprocess: Mapped[bool] = mapped_column(Boolean, default=False)
    duplicate_of_job_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("agent.ingestion_jobs.id"))
    pipeline_version: Mapped[str] = mapped_column(String(80))
    ai_provider: Mapped[str | None] = mapped_column(String(30))
    ai_model: Mapped[str | None] = mapped_column(String(60))
    ai_is_mock: Mapped[bool] = mapped_column(Boolean, default=False)
    prompt_version: Mapped[str | None] = mapped_column(String(60))
    stats: Mapped[dict[str, Any]] = mapped_column(default=dict)
    warnings: Mapped[list[Any]] = mapped_column(default=list)
    error: Mapped[dict[str, Any] | None]
    attempt: Mapped[int] = mapped_column(Integer, default=1)
    started_at: Mapped[datetime | None]
    finished_at: Mapped[datetime | None]

    items: Mapped[list[IngestionItem]] = relationship(
        back_populates="job", cascade="all, delete-orphan", order_by="IngestionItem.position"
    )


class IngestionItem(UUIDPk, Timestamps, Tenant, Base):
    """One file or text block of a job."""

    __tablename__ = "ingestion_items"
    __table_args__ = AGENT

    job_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.ingestion_jobs.id", ondelete="CASCADE"), index=True)
    position: Mapped[int] = mapped_column(Integer, default=0)
    file_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.files.id"))
    raw_text: Mapped[str | None] = mapped_column(Text)
    raw_payload: Mapped[dict[str, Any] | None]  # structured form / API payload
    detected_format: Mapped[str] = mapped_column(String(10))  # text|csv|xlsx|xls|docx|pdf|image|form
    sha256: Mapped[str] = mapped_column(String(64))
    # pending|parsed|interpreted|failed
    status: Mapped[str] = mapped_column(String(20), default="pending")
    error: Mapped[str | None] = mapped_column(Text)
    parser: Mapped[str | None] = mapped_column(String(60))
    ocr_used: Mapped[bool] = mapped_column(Boolean, default=False)
    ocr_confidence: Mapped[Decimal | None] = mapped_column(Numeric(5, 2))
    page_count: Mapped[int | None] = mapped_column(Integer)
    meta: Mapped[dict[str, Any]] = mapped_column(default=dict)
    warnings: Mapped[list[Any]] = mapped_column(default=list)
    detected_record_type: Mapped[str | None] = mapped_column(String(50))

    job: Mapped[IngestionJob] = relationship(back_populates="items")


class ParsedSegment(UUIDPk, Tenant, Base):
    """Normalized parser output: text pages, tables, rows, OCR regions."""

    __tablename__ = "parsed_segments"
    __table_args__ = (Index("ix_parsed_segments_item_seq", "item_id", "seq"), AGENT)

    item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.ingestion_items.id", ondelete="CASCADE"))
    seq: Mapped[int] = mapped_column(Integer)
    kind: Mapped[str] = mapped_column(String(20))  # text|table|row|image_region
    page: Mapped[int | None] = mapped_column(Integer)
    sheet: Mapped[str | None] = mapped_column(String(120))
    row_index: Mapped[int | None] = mapped_column(Integer)
    table_seq: Mapped[int | None] = mapped_column(Integer)  # rows point at their table segment
    content: Mapped[dict[str, Any]] = mapped_column(default=dict)
    ocr_confidence: Mapped[Decimal | None] = mapped_column(Numeric(5, 2))


class ColumnMapping(UUIDPk, Tenant, Base):
    __tablename__ = "column_mappings"
    __table_args__ = (UniqueConstraint("item_id", "table_seq", "source_index"), AGENT)

    item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.ingestion_items.id", ondelete="CASCADE"), index=True)
    table_seq: Mapped[int] = mapped_column(Integer)
    record_type: Mapped[str] = mapped_column(String(50))
    source_index: Mapped[int] = mapped_column(Integer)
    source_column: Mapped[str] = mapped_column(String(200))
    target_field: Mapped[str | None] = mapped_column(String(80))  # None = ignored column
    confidence: Mapped[Decimal] = mapped_column(Numeric(4, 3))
    mapped_by: Mapped[str] = mapped_column(String(10))  # rule|template|ai|user
    confirmed_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    confirmed_at: Mapped[datetime | None]


class MappingTemplate(UUIDPk, Timestamps, Tenant, Base):
    """Per-company saved column mapping, reused when the same spreadsheet layout arrives again."""

    __tablename__ = "mapping_templates"
    __table_args__ = (UniqueConstraint("company_id", "record_type", "header_signature"), AGENT)

    record_type: Mapped[str] = mapped_column(String(50))
    header_signature: Mapped[str] = mapped_column(String(64))
    headers: Mapped[list[Any]] = mapped_column(default=list)
    mapping: Mapped[dict[str, Any]] = mapped_column(default=dict)  # normalized header -> field
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))


class ExtractedRecord(UUIDPk, Timestamps, Tenant, Base):
    __tablename__ = "extracted_records"
    __table_args__ = (
        Index("ix_extracted_records_job_status", "job_id", "status"),
        Index("ix_extracted_records_fingerprint", "company_id", "record_type", "fingerprint"),
        AGENT,
    )

    job_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.ingestion_jobs.id", ondelete="CASCADE"))
    item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.ingestion_items.id", ondelete="CASCADE"))
    seq: Mapped[int] = mapped_column(Integer)
    record_type: Mapped[str] = mapped_column(String(50))
    target_module: Mapped[str] = mapped_column(String(30))
    status: Mapped[str] = mapped_column(String(20), default="draft")
    review_group: Mapped[str | None] = mapped_column(String(20))
    requires_review: Mapped[bool] = mapped_column(Boolean, default=True)
    payload: Mapped[dict[str, Any]] = mapped_column(default=dict)  # current proposed (normalized) values
    payload_version: Mapped[int] = mapped_column(Integer, default=1)
    references: Mapped[dict[str, Any]] = mapped_column(default=dict)  # field -> resolved entity id
    row_ref: Mapped[dict[str, Any]] = mapped_column(default=dict)  # {sheet,row,page} of the whole record
    overall_confidence: Mapped[Decimal | None] = mapped_column(Numeric(4, 3))
    fingerprint: Mapped[str | None] = mapped_column(String(64))
    allow_duplicate: Mapped[bool] = mapped_column(Boolean, default=False)
    summary: Mapped[str | None] = mapped_column(String(300))
    error: Mapped[str | None] = mapped_column(Text)
    reviewed_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    reviewed_at: Mapped[datetime | None]

    fields: Mapped[list[ExtractedField]] = relationship(
        back_populates="record", cascade="all, delete-orphan", order_by="ExtractedField.position"
    )
    validation_results: Mapped[list[ValidationResult]] = relationship(
        cascade="all, delete-orphan", order_by="ValidationResult.id"
    )
    match_candidates: Mapped[list[MatchCandidate]] = relationship(
        cascade="all, delete-orphan", order_by="MatchCandidate.score.desc()"
    )


class ExtractedField(UUIDPk, Tenant, Base):
    __tablename__ = "extracted_fields"
    __table_args__ = (UniqueConstraint("record_id", "field"), AGENT)

    record_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.extracted_records.id", ondelete="CASCADE"))
    position: Mapped[int] = mapped_column(Integer, default=0)
    field: Mapped[str] = mapped_column(String(120))  # dotted path, e.g. "lines.0.quantity"
    raw_value: Mapped[str | None] = mapped_column(Text)
    model_value: Mapped[Any | None] = mapped_column(JSONB, nullable=True)
    normalized_value: Mapped[Any | None] = mapped_column(JSONB, nullable=True)
    confidence: Mapped[Decimal | None] = mapped_column(Numeric(4, 3))
    state: Mapped[str] = mapped_column(String(20), default="low_confidence")
    state_reason: Mapped[str | None] = mapped_column(String(300))
    extracted_by: Mapped[str] = mapped_column(String(20))  # ai|mock|mapping|user|form|derived
    edited_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    edited_at: Mapped[datetime | None]

    record: Mapped[ExtractedRecord] = relationship(back_populates="fields")
    source_refs: Mapped[list[SourceRef]] = relationship(cascade="all, delete-orphan", lazy="selectin")


class SourceRef(UUIDPk, Tenant, Base):
    __tablename__ = "source_refs"
    __table_args__ = AGENT

    field_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.extracted_fields.id", ondelete="CASCADE"), index=True)
    item_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.ingestion_items.id", ondelete="CASCADE"))
    file_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.files.id"))
    page: Mapped[int | None] = mapped_column(Integer)
    sheet: Mapped[str | None] = mapped_column(String(120))
    cell: Mapped[str | None] = mapped_column(String(20))
    bbox: Mapped[list[Any] | None]  # [x0, top, x1, bottom] in page points
    text_start: Mapped[int | None] = mapped_column(Integer)
    text_end: Mapped[int | None] = mapped_column(Integer)
    excerpt: Mapped[str | None] = mapped_column(Text)


class ValidationResult(Tenant, Base):
    __tablename__ = "validation_results"
    __table_args__ = AGENT

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    record_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("agent.extracted_records.id", ondelete="CASCADE"), index=True
    )
    field: Mapped[str | None] = mapped_column(String(120))
    rule_code: Mapped[str] = mapped_column(String(60))
    severity: Mapped[str] = mapped_column(String(10))  # error|warning|info
    message: Mapped[str] = mapped_column(Text)
    details: Mapped[dict[str, Any] | None]
    stage: Mapped[str] = mapped_column(String(20))  # interpret|normalize|validate|match


class MatchCandidate(Tenant, Base):
    __tablename__ = "match_candidates"
    __table_args__ = AGENT

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    record_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("agent.extracted_records.id", ondelete="CASCADE"), index=True
    )
    field: Mapped[str | None] = mapped_column(String(120))  # None for duplicate_record candidates
    entity_type: Mapped[str] = mapped_column(String(60))
    entity_id: Mapped[uuid.UUID]
    label: Mapped[str] = mapped_column(String(300))
    score: Mapped[Decimal] = mapped_column(Numeric(4, 3))
    match_kind: Mapped[str] = mapped_column(String(20))  # exact|alias|fuzzy|duplicate_record|pending_record
    selected: Mapped[bool] = mapped_column(Boolean, default=False)
    details: Mapped[dict[str, Any] | None]


class Approval(UUIDPk, Tenant, Base):
    __tablename__ = "approvals"
    __table_args__ = AGENT

    record_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("agent.extracted_records.id", ondelete="CASCADE"), index=True
    )
    decided_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    decision: Mapped[str] = mapped_column(String(10))  # approve|reject|correct
    auto: Mapped[bool] = mapped_column(Boolean, default=False)
    comment: Mapped[str | None] = mapped_column(Text)
    diff: Mapped[dict[str, Any] | None]
    payload_version: Mapped[int] = mapped_column(Integer)
    decided_at: Mapped[datetime] = mapped_column(server_default=func.now())


class CommittedWrite(UUIDPk, Tenant, Base):
    """Written in the same transaction as the business row: the commit idempotency guarantee."""

    __tablename__ = "committed_writes"
    __table_args__ = AGENT

    record_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("agent.extracted_records.id", ondelete="CASCADE"), unique=True
    )
    target_table: Mapped[str] = mapped_column(String(80))
    target_id: Mapped[uuid.UUID]
    target_number: Mapped[str | None] = mapped_column(String(40))
    operation: Mapped[str] = mapped_column(String(10), default="insert")
    idempotency_key: Mapped[str] = mapped_column(String(64), unique=True)
    committed_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    committed_at: Mapped[datetime] = mapped_column(server_default=func.now())


class StageRun(UUIDPk, Tenant, Base):
    __tablename__ = "stage_runs"
    __table_args__ = (Index("ix_stage_runs_job", "job_id", "started_at"), AGENT)

    job_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.ingestion_jobs.id", ondelete="CASCADE"))
    item_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("agent.ingestion_items.id", ondelete="CASCADE"))
    stage: Mapped[str] = mapped_column(String(20))
    attempt: Mapped[int] = mapped_column(Integer, default=1)
    status: Mapped[str] = mapped_column(String(20))  # running|succeeded|failed
    started_at: Mapped[datetime] = mapped_column(server_default=func.now())
    finished_at: Mapped[datetime | None]
    duration_ms: Mapped[int | None] = mapped_column(Integer)
    error: Mapped[str | None] = mapped_column(Text)
    metrics: Mapped[dict[str, Any]] = mapped_column(default=dict)


class AICall(UUIDPk, Tenant, Base):
    __tablename__ = "ai_calls"
    __table_args__ = (Index("ix_ai_calls_company_created", "company_id", "created_at"), AGENT)

    job_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("agent.ingestion_jobs.id", ondelete="CASCADE"))
    item_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("agent.ingestion_items.id", ondelete="CASCADE"))
    stage: Mapped[str] = mapped_column(String(20))
    task: Mapped[str] = mapped_column(String(40))  # classify|extract|map_columns
    provider: Mapped[str] = mapped_column(String(30))
    model: Mapped[str] = mapped_column(String(60))
    is_mock: Mapped[bool] = mapped_column(Boolean, default=False)
    prompt_id: Mapped[str] = mapped_column(String(60))
    prompt_version: Mapped[str] = mapped_column(String(20))
    schema_version: Mapped[str | None] = mapped_column(String(20))
    input_tokens: Mapped[int | None] = mapped_column(Integer)
    output_tokens: Mapped[int | None] = mapped_column(Integer)
    cost_usd: Mapped[Decimal | None] = mapped_column(Numeric(12, 6))
    latency_ms: Mapped[int | None] = mapped_column(Integer)
    status: Mapped[str] = mapped_column(String(20))  # ok|error|refused|invalid_output
    error: Mapped[str | None] = mapped_column(Text)
    provider_request_id: Mapped[str | None] = mapped_column(String(100))
    data_categories: Mapped[list[Any]] = mapped_column(default=list)  # what left the system: text|table_sample|image
    request_redacted: Mapped[dict[str, Any] | None]
    response: Mapped[dict[str, Any] | None]
    created_at: Mapped[datetime] = mapped_column(server_default=func.now())


class ExtractionFeedback(UUIDPk, Tenant, Base):
    """Human corrections during review; promotable into evaluation datasets (§7.6)."""

    __tablename__ = "extraction_feedback"
    __table_args__ = AGENT

    record_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("agent.extracted_records.id", ondelete="CASCADE"))
    record_type: Mapped[str] = mapped_column(String(50))
    field: Mapped[str] = mapped_column(String(120))
    model_value: Mapped[Any | None] = mapped_column(JSONB, nullable=True)
    corrected_value: Mapped[Any | None] = mapped_column(JSONB, nullable=True)
    extracted_by: Mapped[str] = mapped_column(String(20))
    prompt_version: Mapped[str | None] = mapped_column(String(60))
    corrected_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(server_default=func.now())
