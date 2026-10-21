import pytest

from app.agent.intake.documents import (
    DocumentTooLargeError,
    UnsupportedDocumentError,
    prepare_text,
    prepare_upload,
)

PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 16


def test_magic_bytes_win_over_declared_type():
    doc = prepare_upload(PNG, "scan.pdf", "application/pdf", max_bytes=1000)
    assert doc.media_type == "image/png"
    assert len(doc.sha256) == 64


def test_csv_is_decoded_as_text():
    doc = prepare_upload("Mahsulot;Soni\nA;500\n".encode(), "report.csv", "text/csv", max_bytes=1000)
    assert doc.media_type == "text/csv"
    assert doc.text().startswith("Mahsulot")


@pytest.mark.parametrize(
    ("data", "declared"),
    [
        (b"PK\x03\x04rest-of-docx", "application/octet-stream"),
        (b"\x00\x01\xfe\xff", "application/octet-stream"),
        (b"MZ\x90\x00", "application/x-msdownload"),
        (b"", "text/plain"),
    ],
)
def test_rejects_unsupported_files(data, declared):
    with pytest.raises(UnsupportedDocumentError):
        prepare_upload(data, "file", declared, max_bytes=1000)


def test_size_limits():
    with pytest.raises(DocumentTooLargeError):
        prepare_upload(PNG, "big.png", "image/png", max_bytes=10)
    with pytest.raises(DocumentTooLargeError):
        prepare_text("x" * 11, max_chars=10)
    with pytest.raises(UnsupportedDocumentError):
        prepare_text("   ", max_chars=10)
