"""Stage 2 — Parse (docs/architecture.md §6.1, §6.3): malware scan, format-specific parsing, OCR.

Idempotent per item: re-running replaces the item's segments.
"""

from __future__ import annotations

import uuid
from decimal import Decimal

from sqlalchemy import delete
from sqlalchemy.orm import Session

from app.agent.models import IngestionItem, IngestionJob, ParsedSegment
from app.agent.parsers import ParsedDocument, parse_bytes, parse_text
from app.agent.pipeline.context import StageError
from app.ai.policy import detect_injection
from app.core.models import StoredFile
from app.core.services.files import read_bytes, scan_bytes


def parse_item(session: Session, item: IngestionItem) -> dict:
    job = session.get(IngestionJob, item.job_id)
    if item.detected_format == "form":
        doc = ParsedDocument(parser="structured")
        doc.meta["full_text"] = ""
    elif item.file_id is None:
        doc = parse_text(item.raw_text or "")
    else:
        stored = session.get(StoredFile, item.file_id)
        data = read_bytes(stored)
        if stored.scan_status in ("pending", "skipped"):
            verdict = scan_bytes(data)  # raises ScanUnavailable (transient) when ClamAV is down
            stored.scan_status = verdict
            if verdict == "infected":
                raise StageError(f"'{stored.original_name}' was quarantined by the malware scanner and was not processed")
        elif stored.scan_status == "infected":
            raise StageError(f"'{stored.original_name}' is quarantined")
        try:
            doc = parse_bytes(item.detected_format, data, stored.original_name)
        except StageError:
            raise
        except Exception as exc:
            raise StageError(f"'{stored.original_name}' could not be read as {item.detected_format}: {exc}") from exc

    session.execute(delete(ParsedSegment).where(ParsedSegment.item_id == item.id))
    for seq, seg in enumerate(doc.segments):
        session.add(ParsedSegment(
            company_id=item.company_id, item_id=item.id, seq=seq, kind=seg.kind, page=seg.page, sheet=seg.sheet,
            row_index=seg.row_index, table_seq=seg.table_seq, content=seg.content,
            ocr_confidence=Decimal(str(seg.ocr_confidence)) if seg.ocr_confidence is not None else None,
        ))
    warnings = list(doc.warnings)
    full_text = doc.meta.get("full_text", "")
    injection = detect_injection(full_text)
    if injection:
        warnings.append("The content contains instruction-like text addressed to an AI (" + "; ".join(injection[:3]) +
                        "). It was treated strictly as data.")
        job.warnings = [*job.warnings, f"Possible prompt injection in {item.meta.get('filename') or 'text input'}"]
    item.parser = doc.parser
    item.ocr_used = doc.ocr_used
    item.ocr_confidence = Decimal(str(doc.ocr_confidence)) if doc.ocr_confidence is not None else None
    item.page_count = doc.page_count
    item.meta = {**item.meta, **{k: v for k, v in doc.meta.items()}, "is_tabular": doc.is_tabular,
                 "injection_phrases": injection}
    item.warnings = warnings
    item.status = "parsed"
    item.error = None
    tables = sum(1 for s in doc.segments if s.kind == "table")
    rows = sum(1 for s in doc.segments if s.kind == "row")
    return {"parser": doc.parser, "segments": len(doc.segments), "tables": tables, "rows": rows, "ocr": doc.ocr_used,
            "chars": len(full_text)}


def mark_item_failed(session: Session, item_id: uuid.UUID, message: str, stage: str, retryable: bool) -> None:
    """`stage` is where the item stopped; `retryable` says whether processing it again can help (an
    unreadable file cannot; another record type might make text extractable)."""
    item = session.get(IngestionItem, item_id)
    item.status = "failed"
    item.error = message[:2000]
    item.meta = {**item.meta, "failed_stage": stage, "retryable": retryable}
