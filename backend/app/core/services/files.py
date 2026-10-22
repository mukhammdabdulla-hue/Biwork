"""File intake, storage and malware scanning (docs/architecture.md §8 "Files").

- Allow-listed extensions AND magic-byte checks; the two must agree.
- Archive-based formats (xlsx/docx) are checked for zip bombs before anything opens them.
- Storage keys are random; original filenames are kept only as metadata.
- ClamAV (clamd INSTREAM) scans before parsing; without ClamAV configured, files are marked
  `skipped` and jobs carry a visible warning.
"""

from __future__ import annotations

import hashlib
import io
import socket
import struct
import uuid
import zipfile
from datetime import UTC, datetime
from pathlib import Path
from typing import Protocol

from charset_normalizer import from_bytes
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.config import get_settings
from app.core import audit
from app.core.context import RequestContext
from app.core.errors import NotFound, PayloadTooLarge, ServiceUnavailable, UnsupportedMediaType
from app.core.models import StoredFile

EXTENSIONS = {
    ".txt": "text", ".csv": "csv", ".tsv": "csv", ".xlsx": "xlsx", ".xls": "xls", ".docx": "docx", ".pdf": "pdf",
    ".png": "image", ".jpg": "image", ".jpeg": "image", ".tif": "image", ".tiff": "image", ".webp": "image", ".bmp": "image",
}
MIME = {
    "text": "text/plain", "csv": "text/csv", "pdf": "application/pdf",
    "xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "xls": "application/vnd.ms-excel",
    "docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
}
MAX_UNCOMPRESSED = 300 * 1024 * 1024
MAX_COMPRESSION_RATIO = 150


def _image_mime(data: bytes) -> str | None:
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if data.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if data[:4] in (b"II*\x00", b"MM\x00*"):
        return "image/tiff"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    if data[:2] == b"BM":
        return "image/bmp"
    return None


def _zip_members(data: bytes) -> list[str]:
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            infos = zf.infolist()
    except zipfile.BadZipFile as exc:
        raise UnsupportedMediaType("The file is not a valid Office document") from exc
    total = sum(i.file_size for i in infos)
    compressed = sum(i.compress_size for i in infos) or 1
    if total > MAX_UNCOMPRESSED or total / compressed > MAX_COMPRESSION_RATIO:
        raise UnsupportedMediaType("The archive expands to an unsafe size and was rejected", code="archive_bomb")
    return [i.filename for i in infos]


def detect_format(filename: str, data: bytes) -> tuple[str, str]:
    """Return (detected_format, mime_type) or raise 415 when extension and content disagree."""
    ext = Path(filename or "").suffix.lower()
    expected = EXTENSIONS.get(ext)
    if expected is None:
        raise UnsupportedMediaType(
            f"File type '{ext or 'unknown'}' is not supported",
            extra={"supported": sorted(EXTENSIONS)},
        )
    if expected == "pdf" and data.startswith(b"%PDF-"):
        return "pdf", MIME["pdf"]
    if expected in ("xlsx", "docx") and data.startswith(b"PK\x03\x04"):
        names = _zip_members(data)
        if "[Content_Types].xml" in names:
            if expected == "xlsx" and "xl/workbook.xml" in names and "xl/vbaProject.bin" not in names:
                return "xlsx", MIME["xlsx"]
            if expected == "docx" and "word/document.xml" in names and "word/vbaProject.bin" not in names:
                return "docx", MIME["docx"]
    if expected == "xls" and data.startswith(b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"):
        return "xls", MIME["xls"]
    if expected == "image":
        mime = _image_mime(data)
        if mime:
            return "image", mime
    if expected in ("text", "csv"):
        head = data[:65536]
        if b"\x00" not in head and from_bytes(head).best() is not None:
            return expected, MIME[expected]
    raise UnsupportedMediaType(f"The content of '{filename}' does not match its {ext} extension", code="content_mismatch")


def decode_text(data: bytes) -> tuple[str, str]:
    """Decode text with encoding detection (cp1251 is common for Russian sources)."""
    for enc in ("utf-8-sig",):
        try:
            return data.decode(enc), "utf-8"
        except UnicodeDecodeError:
            pass
    best = from_bytes(data).best()
    if best is None:
        return data.decode("utf-8", errors="replace"), "utf-8 (lossy)"
    return str(best), best.encoding


# --------------------------------------------------------------------------- storage backends


class Storage(Protocol):
    name: str

    def put(self, key: str, data: bytes, content_type: str) -> None: ...

    def get(self, key: str) -> bytes: ...

    def delete(self, key: str) -> None: ...


class LocalStorage:
    name = "local"

    def __init__(self, base: Path):
        self.base = base.resolve()

    def _path(self, key: str) -> Path:
        path = (self.base / key).resolve()
        if not str(path).startswith(str(self.base)):
            raise ValueError("invalid storage key")
        return path

    def put(self, key: str, data: bytes, content_type: str) -> None:
        path = self._path(key)
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(".tmp")
        tmp.write_bytes(data)
        tmp.chmod(0o600)
        tmp.replace(path)

    def get(self, key: str) -> bytes:
        return self._path(key).read_bytes()

    def delete(self, key: str) -> None:
        self._path(key).unlink(missing_ok=True)


class S3Storage:
    """S3-compatible storage (MinIO in development). Objects are private; the API streams them."""

    name = "s3"

    def __init__(self):
        import boto3

        s = get_settings()
        self.bucket = s.s3_bucket
        self.client = boto3.client(
            "s3", endpoint_url=s.s3_endpoint, aws_access_key_id=s.s3_access_key,
            aws_secret_access_key=s.s3_secret_key, region_name=s.s3_region,
        )

    def ensure_bucket(self) -> None:
        from botocore.exceptions import ClientError

        try:
            self.client.head_bucket(Bucket=self.bucket)
        except ClientError:
            self.client.create_bucket(Bucket=self.bucket)

    def put(self, key: str, data: bytes, content_type: str) -> None:
        self.client.put_object(Bucket=self.bucket, Key=key, Body=data, ContentType=content_type)

    def get(self, key: str) -> bytes:
        return self.client.get_object(Bucket=self.bucket, Key=key)["Body"].read()

    def delete(self, key: str) -> None:
        self.client.delete_object(Bucket=self.bucket, Key=key)


_storage: Storage | None = None


def storage() -> Storage:
    global _storage
    if _storage is None:
        s = get_settings()
        _storage = S3Storage() if s.storage_backend == "s3" else LocalStorage(s.storage_dir)
    return _storage


# --------------------------------------------------------------------------- malware scanning


class ScanUnavailable(Exception):
    """ClamAV is configured but unreachable: a transient error, the stage is retried."""


def scan_bytes(data: bytes) -> str:
    s = get_settings()
    if not s.clamav_host:
        return "skipped"
    try:
        with socket.create_connection((s.clamav_host, s.clamav_port), timeout=30) as sock:
            sock.sendall(b"zINSTREAM\0")
            for i in range(0, len(data), 1 << 16):
                chunk = data[i : i + (1 << 16)]
                sock.sendall(struct.pack("!L", len(chunk)) + chunk)
            sock.sendall(struct.pack("!L", 0))
            reply = sock.recv(4096).decode(errors="replace").strip("\0\n ")
    except OSError as exc:
        raise ScanUnavailable(str(exc)) from exc
    if reply.endswith("OK"):
        return "clean"
    if "FOUND" in reply:
        return "infected"
    raise ScanUnavailable(f"Unexpected ClamAV reply: {reply}")


# --------------------------------------------------------------------------- service


def store_upload(session: Session, ctx: RequestContext, filename: str, data: bytes) -> StoredFile:
    ctx.require("files.write")
    settings = get_settings()
    if len(data) > settings.max_upload_bytes:
        raise PayloadTooLarge(f"'{filename}' exceeds the {settings.max_upload_mb} MB limit")
    if not data:
        raise UnsupportedMediaType(f"'{filename}' is empty", code="empty_file")
    detected, mime = detect_format(filename, data)
    sha = hashlib.sha256(data).hexdigest()
    now = datetime.now(UTC)
    key = f"{ctx.company_id}/{now:%Y/%m}/{uuid.uuid4().hex}"
    backend = storage()
    backend.put(key, data, mime)
    record = StoredFile(
        company_id=ctx.company_id, storage_backend=backend.name, storage_key=key, sha256=sha, size=len(data),
        mime_type=mime, detected_format=detected, original_name=Path(filename).name[:300] or "upload",
        scan_status="pending" if settings.clamav_host else "skipped", uploaded_by=ctx.user_id,
    )
    session.add(record)
    session.flush()
    audit.record(session, "file.uploaded", ctx=ctx, entity_type="file", entity_id=record.id, summary=f"File {record.original_name} uploaded",
                 changes={"name": record.original_name, "size": record.size, "sha256": sha, "format": detected})
    return record


def get_file(session: Session, ctx: RequestContext, file_id: uuid.UUID) -> StoredFile:
    ctx.require("files.read")
    f = session.execute(
        select(StoredFile).where(StoredFile.id == file_id, StoredFile.company_id == ctx.company_id, StoredFile.deleted_at.is_(None))
    ).scalar_one_or_none()
    if f is None:
        raise NotFound("File not found")
    if f.scan_status == "infected":
        raise ServiceUnavailable("This file was quarantined by the malware scanner", code="file_infected")
    return f


def read_bytes(f: StoredFile) -> bytes:
    return storage().get(f.storage_key)
