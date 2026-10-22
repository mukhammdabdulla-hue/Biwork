"""Supply schema (docs/architecture.md §4.4)."""

from __future__ import annotations

import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import Boolean, CheckConstraint, Date, ForeignKey, Index, Integer, String, Text, UniqueConstraint, text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.models import Department, Product, Unit
from app.db.base import Authored, Base, FromAgent, Money, Quantity, Tenant, Timestamps, UUIDPk, Versioned

SUP = {"schema": "supply"}


class Supplier(UUIDPk, Timestamps, Tenant, Authored, FromAgent, Base):
    __tablename__ = "suppliers"
    __table_args__ = (
        UniqueConstraint("company_id", "code"),
        Index("uq_suppliers_company_tin", "company_id", "tin", unique=True, postgresql_where=text("tin IS NOT NULL")),
        SUP,
    )

    code: Mapped[str] = mapped_column(String(50))
    name: Mapped[str] = mapped_column(String(250))
    tin: Mapped[str | None] = mapped_column(String(32))
    contact_person: Mapped[str | None] = mapped_column(String(200))
    phone: Mapped[str | None] = mapped_column(String(50))
    email: Mapped[str | None] = mapped_column(String(200))
    address: Mapped[str | None] = mapped_column(Text)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class SupplierAlias(UUIDPk, Tenant, Base):
    __tablename__ = "supplier_aliases"
    __table_args__ = (
        UniqueConstraint("company_id", "normalized_alias", "supplier_id"),
        Index("ix_supplier_aliases_trgm", "normalized_alias", postgresql_using="gin",
              postgresql_ops={"normalized_alias": "gin_trgm_ops"}),
        SUP,
    )

    supplier_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("supply.suppliers.id", ondelete="CASCADE"), index=True)
    alias: Mapped[str] = mapped_column(String(250))
    normalized_alias: Mapped[str] = mapped_column(String(250))


class PurchaseRequest(UUIDPk, Timestamps, Tenant, Authored, Versioned, FromAgent, Base):
    __tablename__ = "purchase_requests"
    __table_args__ = (
        UniqueConstraint("company_id", "number"),
        CheckConstraint("needed_by IS NULL OR needed_by >= request_date", name="needed_by_after_request"),
        Index("ix_purchase_requests_company_status", "company_id", "status"),
        SUP,
    )

    number: Mapped[str] = mapped_column(String(40))
    external_number: Mapped[str | None] = mapped_column(String(60))
    request_date: Mapped[date] = mapped_column(Date)
    needed_by: Mapped[date | None] = mapped_column(Date)
    department_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.departments.id"))
    requested_by_employee: Mapped[str | None] = mapped_column(String(200))
    supplier_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("supply.suppliers.id"))
    # submitted -> approved | rejected -> ordered -> closed ; cancelled
    status: Mapped[str] = mapped_column(String(20), default="submitted")
    priority: Mapped[str] = mapped_column(String(10), default="normal")
    currency: Mapped[str | None] = mapped_column(String(3))
    total_estimated: Mapped[Decimal | None] = mapped_column(Money)
    notes: Mapped[str | None] = mapped_column(Text)
    source_file_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.files.id"))
    decided_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    decided_at: Mapped[datetime | None]
    decision_comment: Mapped[str | None] = mapped_column(Text)

    department: Mapped[Department | None] = relationship(lazy="joined")
    supplier: Mapped[Supplier | None] = relationship(lazy="joined")
    lines: Mapped[list[PurchaseRequestLine]] = relationship(
        cascade="all, delete-orphan", order_by="PurchaseRequestLine.line_no", lazy="selectin"
    )


class PurchaseRequestLine(UUIDPk, Tenant, Base):
    __tablename__ = "purchase_request_lines"
    __table_args__ = (
        UniqueConstraint("request_id", "line_no"),
        CheckConstraint("qty > 0", name="qty_positive"),
        CheckConstraint("est_price IS NULL OR est_price >= 0", name="price_nonneg"),
        SUP,
    )

    request_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("supply.purchase_requests.id", ondelete="CASCADE"), index=True)
    line_no: Mapped[int] = mapped_column(Integer)
    product_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.products.id"))
    description: Mapped[str] = mapped_column(String(300))
    qty: Mapped[Decimal] = mapped_column(Quantity)
    unit_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.units.id"))
    est_price: Mapped[Decimal | None] = mapped_column(Money)
    currency: Mapped[str | None] = mapped_column(String(3))

    product: Mapped[Product | None] = relationship(lazy="joined")
    unit: Mapped[Unit] = relationship(lazy="joined")
