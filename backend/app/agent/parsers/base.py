"""Common parser output (docs/architecture.md §6.3): the rest of the pipeline is format-agnostic."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import date, datetime, time
from decimal import Decimal
from typing import Any

TOTAL_ROW = re.compile(r"^\s*(итого|всего|jami|hammasi|total|grand total|sum)\b", re.IGNORECASE)


@dataclass
class Segment:
    kind: str  # text|table|row
    content: dict[str, Any]
    page: int | None = None
    sheet: str | None = None
    row_index: int | None = None
    table_seq: int | None = None
    ocr_confidence: float | None = None


@dataclass
class ParsedDocument:
    parser: str
    segments: list[Segment] = field(default_factory=list)
    page_count: int | None = None
    ocr_used: bool = False
    ocr_confidence: float | None = None
    meta: dict[str, Any] = field(default_factory=dict)
    warnings: list[str] = field(default_factory=list)
    is_tabular: bool = False  # spreadsheets / pasted tables: rows become records

    def add_table(self, *, name: str, headers: list[str], rows: list[tuple[int, list[Any]]], sheet: str | None = None,
                  page: int | None = None, extra: dict[str, Any] | None = None) -> int:
        table_seq = len(self.segments)
        self.segments.append(Segment("table", {"name": name, "headers": headers, "n_rows": len(rows),
                                               "column_types": column_types(rows, len(headers)), **(extra or {})},
                                     page=page, sheet=sheet))
        for row_number, cells in rows:
            self.segments.append(Segment("row", {"cells": cells, "row_number": row_number}, page=page, sheet=sheet,
                                         row_index=row_number, table_seq=table_seq))
        return table_seq

    def full_text(self) -> str:
        """Text for AI extraction: page texts with tables rendered as markdown."""
        return self.meta.get("full_text", "")


def cell_value(value: Any) -> Any:
    """JSON-safe cell value: dates -> ISO strings, decimals -> numbers, strings trimmed."""
    if value is None:
        return None
    if isinstance(value, datetime):
        return value.date().isoformat() if value.time() == time(0) else value.isoformat(timespec="minutes")
    if isinstance(value, date):
        return value.isoformat()
    if isinstance(value, bool):
        return value
    if isinstance(value, float):
        return int(value) if value.is_integer() else value
    if isinstance(value, (int, Decimal)):
        return int(value) if isinstance(value, Decimal) and value == value.to_integral_value() else value
    text = str(value).replace(" ", " ").strip()
    return text or None


def _kind(v: Any) -> str:
    if v is None:
        return "empty"
    if isinstance(v, bool):
        return "text"
    if isinstance(v, (int, float, Decimal)):
        return "number"
    if isinstance(v, str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}(T.*)?|\d{1,2}[./]\d{1,2}[./]\d{2,4}", v):
        return "date"
    if isinstance(v, str) and re.fullmatch(r"[-+]?\d[\d\s\u00a0]*([.,]\d+)?", v):
        return "number"
    return "text"


def column_types(rows: list[tuple[int, list[Any]]], width: int) -> list[str]:
    types = []
    for c in range(width):
        counts: dict[str, int] = {}
        for _, cells in rows[:200]:
            k = _kind(cells[c] if c < len(cells) else None)
            if k != "empty":
                counts[k] = counts.get(k, 0) + 1
        types.append(max(counts, key=counts.get) if counts else "empty")
    return types


def detect_header(rows: list[list[Any]], max_scan: int = 25) -> int | None:
    """Index of the header row: the first row (within the first `max_scan`) that is mostly short
    text labels and is followed by at least one non-empty row."""
    best, best_score = None, 0
    for i, row in enumerate(rows[:max_scan]):
        values = [v for v in row if v not in (None, "")]
        if len(values) < 2:
            continue
        texts = [v for v in values if isinstance(v, str) and len(v) <= 80 and not re.fullmatch(r"[\d\s.,:/-]+", v)]
        distinct = len(set(texts))
        # A merged title spread across columns repeats one value: not a header.
        if distinct < 2 or len(texts) / len(values) < 0.6:
            continue
        has_data_after = any(any(v not in (None, "") for v in r) for r in rows[i + 1: i + 4])
        if not has_data_after:
            continue
        score = distinct * 10 - i
        if score > best_score:
            best, best_score = i, score
    return best


def build_table(raw_rows: list[list[Any]], first_row_number: int = 1) -> tuple[list[str], list[tuple[int, list[Any]]], dict[str, Any]] | None:
    """Turn raw rows into (headers, data rows with source row numbers, info) or None if not tabular."""
    rows = [[cell_value(v) for v in r] for r in raw_rows]
    width = max((len(r) for r in rows), default=0)
    while width and all((len(r) < width or r[width - 1] in (None, "")) for r in rows):
        width -= 1
    rows = [(r + [None] * width)[:width] for r in rows]
    h = detect_header(rows)
    if h is None or width < 2:
        return None
    headers = [str(v).strip() if v not in (None, "") else f"Column {i + 1}" for i, v in enumerate(rows[h])]
    seen: dict[str, int] = {}
    for i, name in enumerate(headers):
        if name in seen:
            seen[name] += 1
            headers[i] = f"{name} ({seen[name]})"
        else:
            seen[name] = 1
    preamble = [" ".join(dict.fromkeys(str(v) for v in r if v not in (None, ""))) for r in rows[:h]]
    data: list[tuple[int, list[Any]]] = []
    empty = 0
    totals: list[int] = []
    for offset, r in enumerate(rows[h + 1:], start=h + 1):
        if all(v in (None, "") for v in r):
            empty += 1
            continue
        first_text = next((str(v) for v in r if isinstance(v, str)), "")
        if TOTAL_ROW.match(first_text):
            totals.append(first_row_number + offset)
            continue
        data.append((first_row_number + offset, r))
    info = {"header_row": first_row_number + h, "preamble": [p for p in preamble if p], "skipped_empty_rows": empty,
            "total_rows_skipped": totals}
    return headers, data, info


def markdown_table(headers: list[str], rows: list[list[Any]]) -> str:
    def fmt(v: Any) -> str:
        return "" if v is None else str(v).replace("|", "/").replace("\n", " ")

    lines = ["| " + " | ".join(fmt(h) for h in headers) + " |", "|" + "---|" * len(headers)]
    lines += ["| " + " | ".join(fmt(v) for v in r) + " |" for r in rows]
    return "\n".join(lines)
