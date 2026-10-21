"""Per-company human-facing document numbers, e.g. PRD-2026-000123 (docs/architecture.md §4.1)."""

from __future__ import annotations

import uuid
from datetime import date

from sqlalchemy import text
from sqlalchemy.orm import Session


def next_number(session: Session, company_id: uuid.UUID, prefix: str, on: date | None = None) -> str:
    period = str((on or date.today()).year)
    value = session.execute(
        text(
            "INSERT INTO core.number_sequences (company_id, key, period, last_value) VALUES (:c, :k, :p, 1) "
            "ON CONFLICT (company_id, key, period) DO UPDATE SET last_value = core.number_sequences.last_value + 1 "
            "RETURNING last_value"
        ),
        {"c": company_id, "k": prefix, "p": period},
    ).scalar_one()
    return f"{prefix}-{period}-{value:06d}"
