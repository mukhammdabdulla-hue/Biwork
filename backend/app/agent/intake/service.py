"""Document intake pipeline: prepare -> extract -> validate -> preview.

This stops at the review stage (stage 7). Approved records are written later
through the owning module's service (Production, Supply, Quality, ...), never
directly from an extraction.
"""

from __future__ import annotations

import uuid
from datetime import UTC, datetime

from app.agent.intake.documents import PreparedDocument
from app.agent.intake.extractors.base import DocumentExtractor
from app.agent.intake.schemas import (
    DocumentType,
    FieldStatus,
    IntakeResult,
    IntakeStatus,
    IntakeSummary,
    IssueSeverity,
    SourceInfo,
)
from app.agent.intake.validation import validate_extraction


class IntakeService:
    def __init__(self, extractor: DocumentExtractor) -> None:
        self._extractor = extractor

    async def process(self, document: PreparedDocument, type_hint: DocumentType | None = None) -> IntakeResult:
        ingestion_id = uuid.uuid4()
        received_at = datetime.now(UTC)

        outcome = await self._extractor.extract(document, type_hint)
        envelope = outcome.envelope
        report = validate_extraction(envelope)

        errors = sum(1 for i in report.issues if i.severity is IssueSeverity.ERROR)
        warnings = len(report.issues) - errors
        if envelope.document_type is DocumentType.UNKNOWN:
            status = IntakeStatus.UNSUPPORTED_DOCUMENT
        elif errors:
            status = IntakeStatus.NEEDS_CORRECTION
        elif warnings:
            status = IntakeStatus.NEEDS_REVIEW
        else:
            status = IntakeStatus.READY_FOR_APPROVAL

        flagged_paths = {i.field for i in report.issues if i.field is not None}
        summary = IntakeSummary(
            fields_extracted=sum(1 for f in report.fields if f.status is not FieldStatus.MISSING),
            fields_high_confidence=sum(1 for f in report.fields if f.status is FieldStatus.HIGH_CONFIDENCE),
            fields_needing_attention=len(flagged_paths),
            errors=errors,
            warnings=warnings,
        )

        return IntakeResult(
            ingestion_id=ingestion_id,
            received_at=received_at,
            source=SourceInfo(
                kind=document.kind,
                filename=document.filename,
                media_type=document.media_type,
                size_bytes=len(document.data),
                sha256=document.sha256,
            ),
            extractor=outcome.extractor,
            document_type=envelope.document_type,
            status=status,
            requires_review=True,
            summary=summary,
            fields=report.fields,
            issues=report.issues,
            extraction=envelope,
        )
