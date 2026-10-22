"""Production schema (docs/architecture.md §4.4)."""

from __future__ import annotations

import uuid
from datetime import date, datetime, time
from decimal import Decimal

from sqlalchemy import Boolean, CheckConstraint, Date, ForeignKey, Index, String, Text, Time, UniqueConstraint, text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.models import Department, Product, Unit
from app.db.base import Authored, Base, FromAgent, Quantity, Tenant, Timestamps, UUIDPk, Versioned

PROD = {"schema": "production"}


class ProductionLine(UUIDPk, Timestamps, Tenant, Base):
    __tablename__ = "production_lines"
    __table_args__ = (UniqueConstraint("company_id", "code"), PROD)

    department_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.departments.id"))
    code: Mapped[str] = mapped_column(String(50))
    name: Mapped[str] = mapped_column(String(200))
    capacity_per_shift: Mapped[Decimal | None] = mapped_column(Quantity)
    unit_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.units.id"))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class Shift(UUIDPk, Timestamps, Tenant, Base):
    __tablename__ = "shifts"
    __table_args__ = (UniqueConstraint("company_id", "code"), PROD)

    code: Mapped[str] = mapped_column(String(20))
    name: Mapped[str] = mapped_column(String(100))
    starts_at: Mapped[time | None] = mapped_column(Time)
    ends_at: Mapped[time | None] = mapped_column(Time)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class ProductionOrder(UUIDPk, Timestamps, Tenant, Authored, Versioned, FromAgent, Base):
    __tablename__ = "production_orders"
    __table_args__ = (
        UniqueConstraint("company_id", "number"),
        CheckConstraint("planned_qty > 0", name="planned_qty_positive"),
        CheckConstraint("planned_end IS NULL OR planned_start IS NULL OR planned_end >= planned_start", name="dates"),
        Index("ix_production_orders_company_status", "company_id", "status"),
        PROD,
    )

    number: Mapped[str] = mapped_column(String(40))
    external_number: Mapped[str | None] = mapped_column(String(60))
    product_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.products.id"), index=True)
    department_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.departments.id"))
    line_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("production.production_lines.id"))
    planned_qty: Mapped[Decimal] = mapped_column(Quantity)
    unit_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.units.id"))
    planned_start: Mapped[date | None] = mapped_column(Date)
    planned_end: Mapped[date | None] = mapped_column(Date)
    status: Mapped[str] = mapped_column(String(20), default="planned")  # planned|released|in_progress|completed|cancelled
    priority: Mapped[str] = mapped_column(String(10), default="normal")
    notes: Mapped[str | None] = mapped_column(Text)

    product: Mapped[Product] = relationship(lazy="joined")
    unit: Mapped[Unit] = relationship(lazy="joined")
    department: Mapped[Department | None] = relationship(lazy="joined")


class ProductionReport(UUIDPk, Timestamps, Tenant, Authored, Versioned, FromAgent, Base):
    __tablename__ = "production_reports"
    __table_args__ = (
        UniqueConstraint("company_id", "number"),
        CheckConstraint("qty_produced >= 0 AND coalesce(qty_accepted, 0) >= 0 AND coalesce(qty_defective, 0) >= 0",
                        name="qty_nonneg"),
        CheckConstraint("coalesce(qty_accepted, 0) + coalesce(qty_defective, 0) <= qty_produced", name="qty_reconcile"),
        # Natural key (§4.4): one live report per date / product / unit / shift.
        Index("uq_production_reports_natural_key", "company_id", "production_date", "product_id", "department_id",
              "shift_id", unique=True, postgresql_nulls_not_distinct=True,
              postgresql_where=text("status <> 'cancelled'")),
        Index("ix_production_reports_company_date", "company_id", "production_date"),
        PROD,
    )

    number: Mapped[str] = mapped_column(String(40))
    production_date: Mapped[date] = mapped_column(Date)
    product_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.products.id"), index=True)
    department_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.departments.id"))
    line_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("production.production_lines.id"))
    shift_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("production.shifts.id"))
    order_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("production.production_orders.id"), index=True)
    qty_produced: Mapped[Decimal] = mapped_column(Quantity)
    qty_accepted: Mapped[Decimal | None] = mapped_column(Quantity)
    qty_defective: Mapped[Decimal | None] = mapped_column(Quantity)
    unit_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.units.id"))
    status: Mapped[str] = mapped_column(String(20), default="draft")  # draft|approved|cancelled
    supersedes_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("production.production_reports.id"))
    notes: Mapped[str | None] = mapped_column(Text)
    approved_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    approved_at: Mapped[datetime | None]

    product: Mapped[Product] = relationship(lazy="joined")
    unit: Mapped[Unit] = relationship(lazy="joined")
    department: Mapped[Department] = relationship(lazy="joined")
