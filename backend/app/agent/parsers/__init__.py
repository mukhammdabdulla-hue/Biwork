"""Parser dispatch by detected format."""

from __future__ import annotations

from app.agent.parsers.base import ParsedDocument
from app.agent.parsers.documents import parse_docx, parse_image, parse_pdf, parse_text, parse_text_file
from app.agent.parsers.tabular import parse_csv, parse_xls, parse_xlsx

PARSERS = {
    "text": parse_text_file, "csv": parse_csv, "xlsx": parse_xlsx, "xls": parse_xls,
    "docx": parse_docx, "pdf": parse_pdf, "image": parse_image,
}


def parse_bytes(fmt: str, data: bytes, filename: str) -> ParsedDocument:
    return PARSERS[fmt](data, filename)


__all__ = ["ParsedDocument", "parse_bytes", "parse_text"]
