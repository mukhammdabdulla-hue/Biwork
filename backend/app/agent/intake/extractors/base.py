"""Provider-independent extractor interface (stage 3: AI interpretation)."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

from app.agent.intake.documents import PreparedDocument
from app.agent.intake.schemas import DocumentType, ExtractionEnvelope, ExtractorInfo


@dataclass(frozen=True)
class ExtractionOutcome:
    envelope: ExtractionEnvelope
    extractor: ExtractorInfo


class ExtractionError(Exception):
    """The extractor could not produce a usable result.

    ``retryable`` tells the caller whether the same request may succeed later
    (rate limits, overload, timeouts) or not (refusal, malformed output).
    """

    def __init__(self, code: str, message: str, *, retryable: bool) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.retryable = retryable


class DocumentExtractor(Protocol):
    async def extract(
        self, document: PreparedDocument, type_hint: DocumentType | None = None
    ) -> ExtractionOutcome: ...
