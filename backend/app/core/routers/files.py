"""File upload and download. Downloads are always attachments with a server-set content type."""

from __future__ import annotations

import uuid
from typing import Any
from urllib.parse import quote

from fastapi import APIRouter, UploadFile
from fastapi.responses import Response

from app.config import get_settings
from app.core.api import DB, Ctx
from app.core.errors import PayloadTooLarge
from app.core.services import files

router = APIRouter(prefix="/files", tags=["files"])


def file_out(f) -> dict[str, Any]:
    return {"id": str(f.id), "name": f.original_name, "size": f.size, "mime_type": f.mime_type, "format": f.detected_format,
            "sha256": f.sha256, "scan_status": f.scan_status, "created_at": f.created_at}


@router.post("", status_code=201)
async def upload(file: UploadFile, ctx: Ctx, db: DB) -> dict[str, Any]:
    limit = get_settings().max_upload_bytes
    data = await file.read(limit + 1)
    if len(data) > limit:
        raise PayloadTooLarge(f"File exceeds the {get_settings().max_upload_mb} MB limit")
    stored = files.store_upload(db, ctx, file.filename or "upload", data)
    db.commit()
    return file_out(stored)


@router.get("/{file_id}/meta")
def meta(file_id: uuid.UUID, ctx: Ctx, db: DB) -> dict[str, Any]:
    return file_out(files.get_file(db, ctx, file_id))


@router.get("/{file_id}")
def download(file_id: uuid.UUID, ctx: Ctx, db: DB, inline: bool = False) -> Response:
    f = files.get_file(db, ctx, file_id)
    data = files.read_bytes(f)
    disposition = "inline" if inline and f.detected_format in ("pdf", "image") else "attachment"
    return Response(data, media_type=f.mime_type, headers={
        "Content-Disposition": f"{disposition}; filename*=UTF-8''{quote(f.original_name)}",
        "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff",
    })
