import pytest
from fastapi.testclient import TestClient

from app.agent.intake.extractors.base import ExtractionError, ExtractionOutcome
from app.agent.intake.extractors.mock import MOCK_NOTE
from app.agent.intake.prompts import PROMPT_VERSION
from app.agent.intake.schemas import DocumentType, ExtractorInfo
from app.api.v1.agent import get_extractor
from app.core.config import Settings, get_settings
from app.main import create_app
from tests.factories import envelope, ex, invoice, work_order

URL = "/api/v1/agent/intake"


class StubExtractor:
    def __init__(self, result=None, error=None):
        self.result = result
        self.error = error
        self.calls = []

    async def extract(self, document, type_hint=None):
        self.calls.append((document, type_hint))
        if self.error:
            raise self.error
        return ExtractionOutcome(
            envelope=self.result,
            extractor=ExtractorInfo(
                provider="stub", model="stub", prompt_version=PROMPT_VERSION, is_mock=False
            ),
        )


@pytest.fixture
def app():
    return create_app()


def client_with(app, extractor):
    app.dependency_overrides[get_extractor] = lambda: extractor
    return TestClient(app)


def test_text_production_report_is_ready_for_approval(app):
    stub = StubExtractor(envelope(DocumentType.WORK_ORDER, work_order()))
    client = client_with(app, stub)

    res = client.post(
        URL,
        data={"text": "21-oktabr kuni 500 dona A mahsulot ishlab chiqarildi. 480 dona sifat nazoratidan o'tdi."},
    )

    assert res.status_code == 200
    body = res.json()
    assert body["document_type"] == "work_order"
    assert body["status"] == "ready_for_approval"
    assert body["requires_review"] is True
    assert body["source"]["kind"] == "text"
    assert body["summary"]["errors"] == 0
    assert body["extraction"]["work_order"]["quantity_ordered"]["value"] == 500


def test_pdf_with_inconsistent_totals_needs_correction(app):
    stub = StubExtractor(envelope(DocumentType.INVOICE, invoice(total_amount=ex(1.0))))
    client = client_with(app, stub)

    res = client.post(
        URL,
        files={"file": ("inv.pdf", b"%PDF-1.7 test", "application/pdf")},
        data={"document_type": "invoice"},
    )

    assert res.status_code == 200
    body = res.json()
    assert body["status"] == "needs_correction"
    assert body["source"] == {
        "kind": "file",
        "filename": "inv.pdf",
        "media_type": "application/pdf",
        "size_bytes": 13,
        "sha256": body["source"]["sha256"],
    }
    assert [i["code"] for i in body["issues"]] == ["total_mismatch"]
    assert stub.calls[0][1] is DocumentType.INVOICE


def test_low_confidence_needs_review(app):
    stub = StubExtractor(envelope(DocumentType.INVOICE, invoice(invoice_number=ex("INV-0I42", "low"))))
    res = client_with(app, stub).post(URL, data={"text": "invoice"})
    assert res.json()["status"] == "needs_review"


def test_unknown_document_is_unsupported(app):
    res = client_with(app, StubExtractor(envelope(DocumentType.UNKNOWN))).post(URL, data={"text": "hello"})
    assert res.json()["status"] == "unsupported_document"


@pytest.mark.parametrize(
    ("kwargs", "status_code"),
    [
        ({}, 400),
        ({"data": {"text": "a"}, "files": {"file": ("a.txt", b"a", "text/plain")}}, 400),
        ({"files": {"file": ("a.docx", b"PK\x03\x04xx", "application/octet-stream")}}, 415),
        ({"data": {"text": "a", "document_type": "receipt"}}, 422),
    ],
)
def test_bad_requests(app, kwargs, status_code):
    stub = StubExtractor(envelope(DocumentType.UNKNOWN))
    res = client_with(app, stub).post(URL, **kwargs)
    assert res.status_code == status_code
    assert stub.calls == []


def test_upload_size_limit(app):
    app.dependency_overrides[get_settings] = lambda: Settings(intake_max_upload_bytes=8)
    res = client_with(app, StubExtractor()).post(URL, files={"file": ("a.txt", b"0123456789", "text/plain")})
    assert res.status_code == 413


@pytest.mark.parametrize(
    ("error", "status_code"),
    [
        (ExtractionError("refused", "declined", retryable=False), 422),
        (ExtractionError("rate_limited", "slow down", retryable=True), 503),
        (ExtractionError("provider_error", "HTTP 400", retryable=False), 502),
    ],
)
def test_extraction_errors(app, error, status_code):
    res = client_with(app, StubExtractor(error=error)).post(URL, data={"text": "invoice"})
    assert res.status_code == status_code
    assert res.json()["detail"] == {"code": error.code, "message": error.message, "retryable": error.retryable}


def test_mock_provider_is_clearly_labeled(app):
    app.dependency_overrides[get_settings] = lambda: Settings(ai_provider="mock")
    res = TestClient(app).post(URL, data={"text": "Schet-faktura No 12 (invoice)"})

    assert res.status_code == 200
    body = res.json()
    assert body["extractor"]["is_mock"] is True
    assert MOCK_NOTE in body["extraction"]["extraction_notes"]
    assert body["document_type"] == "invoice"
    assert body["status"] == "needs_correction"
    assert body["summary"]["fields_extracted"] == 0
