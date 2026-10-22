"""Declarative base, shared column mixins and key generation (docs/architecture.md §4.1)."""

from __future__ import annotations

import os
import threading
import time
import uuid
from datetime import datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import DateTime, ForeignKey, Integer, MetaData, Numeric, func
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import DeclarativeBase, Mapped, declared_attr, mapped_column

NAMING_CONVENTION = {
    "ix": "ix_%(column_0_label)s",
    "uq": "uq_%(table_name)s_%(column_0_N_name)s",
    "ck": "ck_%(table_name)s_%(constraint_name)s",
    "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
    "pk": "pk_%(table_name)s",
}

# Money and quantities are exact decimals, never floats.
Quantity = Numeric(18, 4)
Money = Numeric(18, 4)


_uuid7_lock = threading.Lock()
_uuid7_last = [0, 0]  # [unix ms, 12-bit sequence]


def uuid7() -> uuid.UUID:
    """RFC 9562 UUIDv7 with a 12-bit monotonic counter (method 1), so keys are strictly ordered
    within a process and primary-key indexes stay compact."""
    with _uuid7_lock:
        ms = time.time_ns() // 1_000_000
        if ms <= _uuid7_last[0]:
            ms = _uuid7_last[0]
            seq = _uuid7_last[1] + 1
            if seq > 0xFFF:  # counter exhausted: borrow the next millisecond
                ms, seq = ms + 1, 0
        else:
            seq = int.from_bytes(os.urandom(2), "big") & 0x3FF  # leave headroom for increments
        _uuid7_last[0], _uuid7_last[1] = ms, seq
    rand_b = int.from_bytes(os.urandom(8), "big") & ((1 << 62) - 1)
    value = (ms & ((1 << 48) - 1)) << 80 | 0x7 << 76 | seq << 64 | 0b10 << 62 | rand_b
    return uuid.UUID(int=value)


class Base(DeclarativeBase):
    metadata = MetaData(naming_convention=NAMING_CONVENTION)
    type_annotation_map = {
        dict[str, Any]: JSONB,
        list[Any]: JSONB,
        uuid.UUID: UUID(as_uuid=True),
        datetime: DateTime(timezone=True),
        Decimal: Quantity,
    }


class UUIDPk:
    id: Mapped[uuid.UUID] = mapped_column(primary_key=True, default=uuid7)


class Timestamps:
    created_at: Mapped[datetime] = mapped_column(server_default=func.now(), nullable=False)
    updated_at: Mapped[datetime] = mapped_column(server_default=func.now(), onupdate=func.now(), nullable=False)


class Tenant:
    """Tenant scope. Every tenant table is protected by a row-level security policy on this column."""

    @declared_attr
    def company_id(cls) -> Mapped[uuid.UUID]:  # noqa: N805
        return mapped_column(ForeignKey("core.companies.id", ondelete="RESTRICT"), index=True, nullable=False)


class Authored:
    @declared_attr
    def created_by(cls) -> Mapped[uuid.UUID | None]:  # noqa: N805
        return mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"), nullable=True)

    @declared_attr
    def updated_by(cls) -> Mapped[uuid.UUID | None]:  # noqa: N805
        return mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"), nullable=True)


class Versioned:
    """Optimistic locking: SQLAlchemy bumps `version` on every UPDATE and refuses stale writes."""

    version: Mapped[int] = mapped_column(Integer, nullable=False, default=1)

    @declared_attr.directive
    def __mapper_args__(cls) -> dict[str, Any]:  # noqa: N805
        return {"version_id_col": cls.__table__.c.version}


class FromAgent:
    """Link to the extracted record a row was created from (provenance, §4.1)."""

    @declared_attr
    def source_record_id(cls) -> Mapped[uuid.UUID | None]:  # noqa: N805
        return mapped_column(
            ForeignKey("agent.extracted_records.id", ondelete="SET NULL", use_alter=True), nullable=True
        )


class SoftDelete:
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
