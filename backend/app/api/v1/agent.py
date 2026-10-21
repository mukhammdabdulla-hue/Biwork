from __future__ import annotations

from functools import lru_cache
from typing import Annotated

import anthropic
from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile, status

from app.agent.intake.documents import (
    DocumentTooLargeError,
    PreparedDocument,
    UnsupportedDocumentError,
    prepare_text,
    prepare_upload,
)
from app.agent.intake.extractors.anthropic import AnthropicExtractor
from app.agent.intake.extractors.base import DocumentExtractor, ExtractionError
from app.agent.intake.extractors.mock import MockExtractor
from app.agent.intake.schemas import DocumentType, IntakeResult
from app.agent.intake.service import IntakeService
from app.core.config import Settings, get_settings

router = APIRouter(prefix="/agent", tags=["agent"])


@lru_cache
def _build_extractor(settings: Settings) -> DocumentExtractor:
    if settings.ai_provider == "mock":
        return MockExtractor()
    client = anthropic.AsyncAnthropic(timeout=settings.ai_timeout_seconds, max_retries=settings.ai_max_retries)
    return AnthropicExtractor(
        client,
        model=settings.ai_model,
        effort=settings.ai_effort,
        max_output_tokens=settings.ai_max_output_tokens,
    )


def get_extractor(settings: Annotated[Settings, Depends(get_settings)]) -> DocumentExtractor:
    return _build_extractor(settings)


def get_intake_service(extractor: Annotated[DocumentExtractor, Depends(get_extractor)]) -> IntakeService:
    return IntakeService(extractor)


def _error(status_code: int, code: str, message: str, *, retryable: bool = False) -> HTTPException:
    return HTTPException(status_code, detail={"code": code, "message": message, "retryable": retryable})


@router.post("/intake", response_model=IntakeResult, summary="Extract structured data from a document")
async def intake_document(
    service: Annotated[IntakeService, Depends(get_intake_service)],
    settings: Annotated[Settings, Depends(get_settings)],
    file: Annotated[UploadFile | None, File(description="PDF, image (PNG/JPEG/GIF/WEBP), CSV or plain text.")] = None,
    text: Annotated[str | None, Form(description="Text pasted or typed by the user.")] = None,
    document_type: Annotated[DocumentType | None, Form(description="Optional hint from the submitter.")] = None,
) -> IntakeResult:
    """Run a document through extraction and validation and return a preview for review.

    Nothing is saved. The result must be approved before a module service writes it.
    """
    if (file is None) == (text is None):
        raise _error(status.HTTP_400_BAD_REQUEST, "invalid_input", "Provide exactly one of 'file' or 'text'.")

    try:
        document: PreparedDocument
        if file is not None:
            data = await file.read(settings.intake_max_upload_bytes + 1)
            document = prepare_upload(
                data, file.filename, file.content_type, max_bytes=settings.intake_max_upload_bytes
            )
        else:
            document = prepare_text(text or "", max_chars=settings.intake_max_text_chars)
    except DocumentTooLargeError as exc:
        raise _error(status.HTTP_413_CONTENT_TOO_LARGE, "too_large", str(exc)) from exc
    except UnsupportedDocumentError as exc:
        raise _error(status.HTTP_415_UNSUPPORTED_MEDIA_TYPE, "unsupported_document", str(exc)) from exc

    try:
        return await service.process(document, document_type)
    except ExtractionError as exc:
        if exc.code == "refused":
            code = status.HTTP_422_UNPROCESSABLE_CONTENT
        elif exc.retryable:
            code = status.HTTP_503_SERVICE_UNAVAILABLE
        else:
            code = status.HTTP_502_BAD_GATEWAY
        raise _error(code, exc.code, exc.message, retryable=exc.retryable) from exc
