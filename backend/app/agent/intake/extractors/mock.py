"""Development-only extractor. It performs no AI analysis.

It guesses the document type from keywords and returns an empty extraction so
the rest of the pipeline and the UI can be exercised without credentials.
Every result is flagged ``is_mock`` and carries a note saying so.
"""

from __future__ import annotations

from app.agent.intake.documents import PreparedDocument
from app.agent.intake.extractors.base import ExtractionOutcome
from app.agent.intake.prompts import PROMPT_VERSION
from app.agent.intake.schemas import (
    DocumentType,
    Extracted,
    ExtractionEnvelope,
    ExtractorInfo,
    InvoiceExtraction,
    SpecificationExtraction,
    WorkOrderExtraction,
)

MOCK_NOTE = "MOCK EXTRACTOR: no AI analysis was performed. All values are empty placeholders."

_KEYWORDS = {
    DocumentType.INVOICE: ("invoice", "faktura", "фактура", "счет", "счёт", "hisob"),
    DocumentType.WORK_ORDER: ("work order", "production order", "заказ-наряд", "наряд", "buyurtma", "ishlab chiqar"),
    DocumentType.SPECIFICATION: ("specification", "спецификац", "tolerance", "допуск", "texnik shart"),
}


def _empty() -> Extracted:
    return Extracted(value=None, confidence="low", source_page=None, source_text=None)


def _guess_type(document: PreparedDocument, type_hint: DocumentType | None) -> DocumentType:
    if type_hint is not None:
        return type_hint
    if not document.is_text:
        return DocumentType.UNKNOWN
    lowered = document.text().lower()
    for doc_type, words in _KEYWORDS.items():
        if any(word in lowered for word in words):
            return doc_type
    return DocumentType.UNKNOWN


def _empty_payload(model: type) -> object:
    values = {}
    for name, field in model.model_fields.items():
        values[name] = [] if getattr(field.annotation, "__origin__", None) is list else _empty()
    return model(**values)


class MockExtractor:
    provider = "mock"

    async def extract(
        self, document: PreparedDocument, type_hint: DocumentType | None = None
    ) -> ExtractionOutcome:
        doc_type = _guess_type(document, type_hint)
        envelope = ExtractionEnvelope(
            document_type=doc_type,
            document_type_confidence="low",
            detected_language=None,
            invoice=_empty_payload(InvoiceExtraction) if doc_type is DocumentType.INVOICE else None,
            work_order=_empty_payload(WorkOrderExtraction) if doc_type is DocumentType.WORK_ORDER else None,
            specification=_empty_payload(SpecificationExtraction)
            if doc_type is DocumentType.SPECIFICATION
            else None,
            extraction_notes=[MOCK_NOTE],
        )
        return ExtractionOutcome(
            envelope=envelope,
            extractor=ExtractorInfo(
                provider=self.provider, model="mock", prompt_version=PROMPT_VERSION, is_mock=True
            ),
        )
