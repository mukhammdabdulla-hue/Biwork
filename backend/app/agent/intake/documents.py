"""Stage 1-2: accept an upload or text submission and prepare it for extraction.

Formats the model reads natively (PDF, images) are passed through as content
blocks. Plain-text formats are decoded here. Office formats (DOCX, XLSX) need
dedicated parsers and are rejected until that stage exists.
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass
from typing import Literal

PDF = "application/pdf"
IMAGE_TYPES = {"image/png", "image/jpeg", "image/gif", "image/webp"}
TEXT_TYPES = {"text/plain", "text/csv", "text/markdown", "application/json"}

_OFFICE_TYPES = {
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/msword",
    "application/vnd.ms-excel",
}


class UnsupportedDocumentError(ValueError):
    pass


class DocumentTooLargeError(ValueError):
    pass


@dataclass(frozen=True)
class PreparedDocument:
    kind: Literal["file", "text"]
    filename: str | None
    media_type: str
    data: bytes
    sha256: str

    @property
    def is_text(self) -> bool:
        return self.media_type in TEXT_TYPES

    def text(self) -> str:
        return self.data.decode("utf-8")


def sniff_media_type(data: bytes) -> str | None:
    if data.startswith(b"%PDF-"):
        return PDF
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if data.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    if data.startswith(b"PK\x03\x04"):
        return "application/zip"
    return None


def prepare_upload(
    data: bytes, filename: str | None, declared_type: str | None, *, max_bytes: int
) -> PreparedDocument:
    if not data:
        raise UnsupportedDocumentError("The uploaded file is empty.")
    if len(data) > max_bytes:
        raise DocumentTooLargeError(f"File exceeds the {max_bytes // (1024 * 1024)} MB limit.")

    declared = (declared_type or "").split(";")[0].strip().lower()
    sniffed = sniff_media_type(data)

    # Trust the file's magic bytes over the client-declared type.
    if sniffed == "application/zip" or declared in _OFFICE_TYPES:
        raise UnsupportedDocumentError(
            "Word and Excel files are not supported by document intake yet. Export to PDF or CSV."
        )
    if sniffed is not None:
        media_type = sniffed
    elif declared in TEXT_TYPES or declared in ("", "application/octet-stream"):
        try:
            data.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise UnsupportedDocumentError("Unrecognized binary file type.") from exc
        media_type = declared if declared in TEXT_TYPES else "text/plain"
    else:
        raise UnsupportedDocumentError(f"Unsupported file type: {declared}.")

    return PreparedDocument(
        kind="file",
        filename=filename,
        media_type=media_type,
        data=data,
        sha256=hashlib.sha256(data).hexdigest(),
    )


def prepare_text(text: str, *, max_chars: int) -> PreparedDocument:
    if not text.strip():
        raise UnsupportedDocumentError("The submitted text is empty.")
    if len(text) > max_chars:
        raise DocumentTooLargeError(f"Text exceeds the {max_chars} character limit.")
    data = text.encode("utf-8")
    return PreparedDocument(
        kind="text",
        filename=None,
        media_type="text/plain",
        data=data,
        sha256=hashlib.sha256(data).hexdigest(),
    )
