"""Inventory schema (docs/architecture.md §4.4): append-only movements + materialized balances."""

from __future__ import annotations

import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import Boolean, CheckConstraint, Date, ForeignKey, Index, SmallInteger, String, Text, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.models import Product, Unit
from app.db.base import Base, FromAgent, Quantity, Tenant, Timestamps, UUIDPk

INV = {"schema": "inventory"}


class Warehouse(UUIDPk, Timestamps, Tenant, Base):
    __tablename__ = "warehouses"
    __table_args__ = (UniqueConstraint("company_id", "code"), INV)

    department_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.departments.id"))
    code: Mapped[str] = mapped_column(String(50))
    name: Mapped[str] = mapped_column(String(200))
    kind: Mapped[str] = mapped_column(String(20), default="general")  # raw|finished|wip|general
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class StockMovement(UUIDPk, Tenant, FromAgent, Base):
    """Append-only. Corrections are compensating movements (`reverses_id`), never edits."""

    __tablename__ = "stock_movements"
    __table_args__ = (
        UniqueConstraint("company_id", "number"),
        CheckConstraint("qty <> 0", name="qty_nonzero"),
        CheckConstraint("direction IN (-1, 1)", name="direction"),
        Index("ix_stock_movements_company_wh_product", "company_id", "warehouse_id", "product_id"),
        Index("ix_stock_movements_ref", "ref_type", "ref_id"),
        INV,
    )

    number: Mapped[str] = mapped_column(String(40))
    movement_date: Mapped[date] = mapped_column(Date)
    movement_type: Mapped[str] = mapped_column(String(30))
    direction: Mapped[int] = mapped_column(SmallInteger)
    warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("inventory.warehouses.id"))
    product_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.products.id"))
    qty: Mapped[Decimal] = mapped_column(Quantity)  # signed, in `unit_id`
    unit_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.units.id"))
    base_qty: Mapped[Decimal] = mapped_column(Quantity)  # signed, in the product's base unit
    batch_code: Mapped[str | None] = mapped_column(String(64))
    reference: Mapped[str | None] = mapped_column(String(100))
    ref_type: Mapped[str | None] = mapped_column(String(60))
    ref_id: Mapped[uuid.UUID | None]
    reverses_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("inventory.stock_movements.id"))
    notes: Mapped[str | None] = mapped_column(Text)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(server_default=func.now())

    product: Mapped[Product] = relationship(lazy="joined")
    unit: Mapped[Unit] = relationship(lazy="joined")
    warehouse: Mapped[Warehouse] = relationship(lazy="joined")


class StockBalance(UUIDPk, Tenant, Base):
    """Maintained in the same transaction as each movement; quantity in the product's base unit."""

    __tablename__ = "stock_balances"
    __table_args__ = (
        Index("uq_stock_balances_key", "company_id", "warehouse_id", "product_id", "batch_code", unique=True,
              postgresql_nulls_not_distinct=True),
        INV,
    )

    warehouse_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("inventory.warehouses.id"))
    product_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.products.id"))
    batch_code: Mapped[str | None] = mapped_column(String(64))
    qty: Mapped[Decimal] = mapped_column(Quantity, default=Decimal(0))
    updated_at: Mapped[datetime] = mapped_column(server_default=func.now(), onupdate=func.now())
