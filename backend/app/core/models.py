"""BIWORK Core schema (docs/architecture.md §4.2): tenancy, identity, access, master data, files,
tasks, notifications, workflow, audit, API clients, idempotency."""

from __future__ import annotations

import uuid
from datetime import date, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import (
    BigInteger,
    Boolean,
    CheckConstraint,
    Date,
    ForeignKey,
    Index,
    Integer,
    LargeBinary,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
    text,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Authored, Base, FromAgent, Quantity, SoftDelete, Tenant, Timestamps, UUIDPk, Versioned

CORE = {"schema": "core"}


# --------------------------------------------------------------------------- tenancy


class Organization(UUIDPk, Timestamps, Base):
    __tablename__ = "organizations"
    __table_args__ = CORE

    name: Mapped[str] = mapped_column(String(200))
    code: Mapped[str] = mapped_column(String(50), unique=True)


class Company(UUIDPk, Timestamps, Base):
    __tablename__ = "companies"
    __table_args__ = (UniqueConstraint("organization_id", "code"), CORE)

    organization_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.organizations.id"), index=True)
    name: Mapped[str] = mapped_column(String(200))
    code: Mapped[str] = mapped_column(String(50))
    tin: Mapped[str | None] = mapped_column(String(32))
    # locale, timezone, default_currency, date_order, ai_policy, production_output_warehouse_id ...
    settings: Mapped[dict[str, Any]] = mapped_column(default=dict, server_default=text("'{}'::jsonb"))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    # Seeded demo companies are flagged so the UI labels their figures "Sample data".
    is_sample_data: Mapped[bool] = mapped_column(Boolean, default=False, server_default=text("false"))


class CompanyModule(Base):
    __tablename__ = "company_modules"
    __table_args__ = CORE

    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.companies.id", ondelete="CASCADE"), primary_key=True)
    module_code: Mapped[str] = mapped_column(String(40), primary_key=True)
    enabled: Mapped[bool] = mapped_column(Boolean, default=True)
    config: Mapped[dict[str, Any]] = mapped_column(default=dict, server_default=text("'{}'::jsonb"))


# --------------------------------------------------------------------------- identity & access


class User(UUIDPk, Timestamps, Base):
    __tablename__ = "users"
    __table_args__ = (Index("uq_users_email_lower", func.lower(text("email")), unique=True), CORE)

    email: Mapped[str] = mapped_column(String(320))
    full_name: Mapped[str] = mapped_column(String(200))
    password_hash: Mapped[str] = mapped_column(String(255))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    is_platform_admin: Mapped[bool] = mapped_column(Boolean, default=False)
    mfa_enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    mfa_secret: Mapped[bytes | None] = mapped_column(LargeBinary)  # Fernet-encrypted TOTP secret
    mfa_pending_secret: Mapped[bytes | None] = mapped_column(LargeBinary)  # enrolled, not yet confirmed
    failed_login_count: Mapped[int] = mapped_column(Integer, default=0)
    locked_until: Mapped[datetime | None]
    last_login_at: Mapped[datetime | None]
    # Access tokens issued before this moment are rejected (password change ends every session).
    sessions_revoked_at: Mapped[datetime | None]
    locale: Mapped[str] = mapped_column(String(8), default="en")


class RefreshToken(UUIDPk, Base):
    """Rotating refresh tokens. Re-use of a consumed token revokes the whole family."""

    __tablename__ = "refresh_tokens"
    __table_args__ = CORE

    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.users.id", ondelete="CASCADE"), index=True)
    family_id: Mapped[uuid.UUID] = mapped_column(index=True)
    token_hash: Mapped[str] = mapped_column(String(64), unique=True)
    issued_at: Mapped[datetime] = mapped_column(server_default=func.now())
    expires_at: Mapped[datetime]
    used_at: Mapped[datetime | None]
    revoked_at: Mapped[datetime | None]
    ip_address: Mapped[str | None] = mapped_column(String(64))
    user_agent: Mapped[str | None] = mapped_column(String(300))


class Role(UUIDPk, Timestamps, Base):
    """`company_id` NULL = system role template usable in every company."""

    __tablename__ = "roles"
    __table_args__ = (
        Index("uq_roles_company_code", "company_id", "code", unique=True, postgresql_nulls_not_distinct=True),
        CORE,
    )

    company_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.companies.id", ondelete="CASCADE"))
    code: Mapped[str] = mapped_column(String(50))
    name: Mapped[str] = mapped_column(String(100))
    description: Mapped[str | None] = mapped_column(Text)
    is_system: Mapped[bool] = mapped_column(Boolean, default=False)

    permissions: Mapped[list[RolePermission]] = relationship(cascade="all, delete-orphan", lazy="selectin")


class RolePermission(Base):
    __tablename__ = "role_permissions"
    __table_args__ = CORE

    role_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.roles.id", ondelete="CASCADE"), primary_key=True)
    permission: Mapped[str] = mapped_column(String(80), primary_key=True)


class Membership(UUIDPk, Timestamps, Base):
    __tablename__ = "memberships"
    __table_args__ = (UniqueConstraint("user_id", "company_id", "role_id"), CORE)

    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.users.id", ondelete="CASCADE"), index=True)
    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.companies.id", ondelete="CASCADE"), index=True)
    role_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.roles.id"))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    role: Mapped[Role] = relationship(lazy="joined")
    departments: Mapped[list[MembershipDepartment]] = relationship(cascade="all, delete-orphan", lazy="selectin")


class MembershipDepartment(Base):
    """Restricts a membership to specific departments (department-level access)."""

    __tablename__ = "membership_departments"
    __table_args__ = CORE

    membership_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("core.memberships.id", ondelete="CASCADE"), primary_key=True
    )
    department_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("core.departments.id", ondelete="CASCADE"), primary_key=True
    )


class ApiClient(UUIDPk, Timestamps, Base):
    """Machine credentials for system-to-system ingestion: hashed key, scopes, one company."""

    __tablename__ = "api_clients"
    __table_args__ = CORE

    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.companies.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(100))
    key_prefix: Mapped[str] = mapped_column(String(16))
    key_hash: Mapped[str] = mapped_column(String(64), unique=True)
    scopes: Mapped[list[Any]] = mapped_column(default=list)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    last_used_at: Mapped[datetime | None]
    expires_at: Mapped[datetime | None]
    revoked_at: Mapped[datetime | None]


# --------------------------------------------------------------------------- organization structure


class Department(UUIDPk, Timestamps, Tenant, Base):
    __tablename__ = "departments"
    __table_args__ = (UniqueConstraint("company_id", "code"), CORE)

    parent_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.departments.id"))
    code: Mapped[str] = mapped_column(String(50))
    name: Mapped[str] = mapped_column(String(200))
    kind: Mapped[str] = mapped_column(String(30), default="office")  # workshop|warehouse|office|other
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class DepartmentAlias(UUIDPk, Tenant, Base):
    """Alternative spellings ("1-sex", "Цех №1", "Workshop 1") used by entity matching."""

    __tablename__ = "department_aliases"
    __table_args__ = (
        UniqueConstraint("company_id", "normalized_alias", "department_id"),
        Index("ix_department_aliases_trgm", "normalized_alias", postgresql_using="gin",
              postgresql_ops={"normalized_alias": "gin_trgm_ops"}),
        CORE,
    )

    department_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.departments.id", ondelete="CASCADE"))
    alias: Mapped[str] = mapped_column(String(200))
    normalized_alias: Mapped[str] = mapped_column(String(200))


# --------------------------------------------------------------------------- master data


class Unit(UUIDPk, Base):
    """Global units-of-measure table with conversion factors to the dimension's base unit."""

    __tablename__ = "units"
    __table_args__ = CORE

    code: Mapped[str] = mapped_column(String(16), unique=True)
    name: Mapped[str] = mapped_column(String(60))
    dimension: Mapped[str] = mapped_column(String(20))  # count|mass|volume|length|area|time
    factor_to_base: Mapped[Decimal] = mapped_column(Quantity)
    is_base: Mapped[bool] = mapped_column(Boolean, default=False)


class UnitAlias(UUIDPk, Base):
    __tablename__ = "unit_aliases"
    __table_args__ = CORE

    unit_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.units.id", ondelete="CASCADE"), index=True)
    alias: Mapped[str] = mapped_column(String(40), unique=True)  # stored normalized (lower-case, trimmed)


class ProductCategory(UUIDPk, Timestamps, Tenant, Base):
    __tablename__ = "product_categories"
    __table_args__ = (UniqueConstraint("company_id", "code"), CORE)

    parent_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.product_categories.id"))
    code: Mapped[str] = mapped_column(String(50))
    name: Mapped[str] = mapped_column(String(200))


class Product(UUIDPk, Timestamps, Tenant, Authored, Versioned, FromAgent, SoftDelete, Base):
    __tablename__ = "products"
    __table_args__ = (UniqueConstraint("company_id", "code"), CORE)

    code: Mapped[str] = mapped_column(String(64))
    name: Mapped[str] = mapped_column(String(250))
    category_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.product_categories.id"))
    base_unit_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.units.id"))
    product_type: Mapped[str] = mapped_column(String(30), default="finished_good")
    reorder_level: Mapped[Decimal | None] = mapped_column(Quantity)  # inventory shortage threshold
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    base_unit: Mapped[Unit] = relationship(lazy="joined")
    aliases: Mapped[list[ProductAlias]] = relationship(cascade="all, delete-orphan", lazy="selectin")


class ProductAlias(UUIDPk, Tenant, Base):
    __tablename__ = "product_aliases"
    __table_args__ = (
        UniqueConstraint("company_id", "normalized_alias", "product_id"),
        Index("ix_product_aliases_trgm", "normalized_alias", postgresql_using="gin",
              postgresql_ops={"normalized_alias": "gin_trgm_ops"}),
        CORE,
    )

    product_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.products.id", ondelete="CASCADE"), index=True)
    alias: Mapped[str] = mapped_column(String(250))
    normalized_alias: Mapped[str] = mapped_column(String(250))


# --------------------------------------------------------------------------- personnel and business documents


class Employee(UUIDPk, Timestamps, Tenant, Authored, Versioned, FromAgent, Base):
    """Personnel register (not login accounts: users are in core.users)."""

    __tablename__ = "employees"
    __table_args__ = (
        Index("uq_employees_company_number", "company_id", "personnel_number", unique=True,
              postgresql_where=text("personnel_number IS NOT NULL")),
        Index("ix_employees_company_name", "company_id", "normalized_name"),
        CheckConstraint("status IN ('active', 'on_leave', 'dismissed')", name="employee_status"),
        CORE,
    )

    personnel_number: Mapped[str | None] = mapped_column(String(40))
    full_name: Mapped[str] = mapped_column(String(250))
    normalized_name: Mapped[str] = mapped_column(String(250))
    position: Mapped[str | None] = mapped_column(String(200))
    department_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.departments.id"))
    hire_date: Mapped[date | None] = mapped_column(Date)
    phone: Mapped[str | None] = mapped_column(String(50))
    status: Mapped[str] = mapped_column(String(20), default="active")


class BusinessDocument(UUIDPk, Timestamps, Tenant, Authored, Versioned, FromAgent, Base):
    """Register of incoming business documents (invoices, waybills, contracts, acts...)."""

    __tablename__ = "business_documents"
    __table_args__ = (
        UniqueConstraint("company_id", "doc_type", "number", "counterparty_key"),
        CheckConstraint("total_amount IS NULL OR total_amount >= 0", name="document_total_non_negative"),
        CORE,
    )

    doc_type: Mapped[str] = mapped_column(String(20))  # invoice|waybill|contract|act|specification|other
    number: Mapped[str] = mapped_column(String(60))
    doc_date: Mapped[date] = mapped_column(Date)
    counterparty: Mapped[str | None] = mapped_column(String(250))
    counterparty_key: Mapped[str] = mapped_column(String(250), default="")  # normalized, for the uniqueness constraint
    supplier_id: Mapped[uuid.UUID | None]  # supply.suppliers when the counterparty is a known supplier (no FK: module data)
    total_amount: Mapped[Decimal | None] = mapped_column(Numeric(18, 2))
    currency: Mapped[str | None] = mapped_column(String(3))
    description: Mapped[str | None] = mapped_column(Text)
    lines: Mapped[list[Any]] = mapped_column(default=list)
    source_file_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.files.id", ondelete="SET NULL"))


# --------------------------------------------------------------------------- files


class StoredFile(UUIDPk, Tenant, SoftDelete, Base):
    __tablename__ = "files"
    __table_args__ = (Index("ix_files_company_sha", "company_id", "sha256"), CORE)

    storage_backend: Mapped[str] = mapped_column(String(10))
    storage_key: Mapped[str] = mapped_column(String(300))  # never derived from the original filename
    sha256: Mapped[str] = mapped_column(String(64))
    size: Mapped[int] = mapped_column(BigInteger)
    mime_type: Mapped[str] = mapped_column(String(120))
    detected_format: Mapped[str] = mapped_column(String(10))  # text|csv|xlsx|xls|docx|pdf|image
    original_name: Mapped[str] = mapped_column(String(300))
    scan_status: Mapped[str] = mapped_column(String(10), default="pending")  # pending|clean|infected|skipped
    uploaded_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = mapped_column(server_default=func.now())


# --------------------------------------------------------------------------- tasks, notifications, workflow


class Task(UUIDPk, Timestamps, Tenant, Authored, Versioned, FromAgent, Base):
    __tablename__ = "tasks"
    __table_args__ = (
        UniqueConstraint("company_id", "number"),
        Index("ix_tasks_company_status_due", "company_id", "status", "due_date"),
        CORE,
    )

    number: Mapped[str] = mapped_column(String(30))
    title: Mapped[str] = mapped_column(String(300))
    description: Mapped[str | None] = mapped_column(Text)
    assignee_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))
    department_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.departments.id"))
    due_date: Mapped[date | None] = mapped_column(Date)
    status: Mapped[str] = mapped_column(String(20), default="open")  # open|in_progress|done|cancelled
    priority: Mapped[str] = mapped_column(String(10), default="normal")  # low|normal|high|urgent
    ref_type: Mapped[str | None] = mapped_column(String(60))
    ref_id: Mapped[uuid.UUID | None]
    completed_at: Mapped[datetime | None]


class Notification(UUIDPk, Tenant, Base):
    __tablename__ = "notifications"
    __table_args__ = (Index("ix_notifications_user_unread", "user_id", "read_at"), CORE)

    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.users.id", ondelete="CASCADE"))
    kind: Mapped[str] = mapped_column(String(50))
    payload: Mapped[dict[str, Any]] = mapped_column(default=dict)  # {title, body, link}
    read_at: Mapped[datetime | None]
    created_at: Mapped[datetime] = mapped_column(server_default=func.now())


class WorkflowDefinition(UUIDPk, Timestamps, Tenant, Base):
    """Approval configuration per record type. Auto-approval is opt-in by an administrator."""

    __tablename__ = "workflow_definitions"
    __table_args__ = (UniqueConstraint("company_id", "record_type"), CORE)

    record_type: Mapped[str] = mapped_column(String(50))
    steps: Mapped[list[Any]] = mapped_column(default=list)  # [{"permission": "production.approve"}]
    auto_approve: Mapped[bool] = mapped_column(Boolean, default=False)
    confidence_threshold: Mapped[Decimal | None] = mapped_column(Quantity)
    updated_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.users.id", ondelete="SET NULL"))


# --------------------------------------------------------------------------- audit


class AuditEvent(Base):
    """Append-only, hash-chained audit trail (§4.6). The application role has INSERT/SELECT only."""

    __tablename__ = "audit_events"
    __table_args__ = (
        Index("ix_audit_company_occurred", "company_id", "occurred_at"),
        Index("ix_audit_entity", "entity_type", "entity_id"),
        CORE,
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    occurred_at: Mapped[datetime] = mapped_column(server_default=func.now())
    company_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("core.companies.id"))
    actor_type: Mapped[str] = mapped_column(String(20))  # user|api_client|system|agent
    actor_id: Mapped[uuid.UUID | None]
    action: Mapped[str] = mapped_column(String(80), index=True)
    entity_type: Mapped[str | None] = mapped_column(String(60))
    entity_id: Mapped[str | None] = mapped_column(String(64))
    summary: Mapped[str | None] = mapped_column(Text)
    changes: Mapped[dict[str, Any] | None]
    context: Mapped[dict[str, Any] | None]
    prev_hash: Mapped[str | None] = mapped_column(String(64))
    hash: Mapped[str] = mapped_column(String(64))


class AuditChainHead(Base):
    """Latest hash per chain (one chain per company plus a platform chain). Serializes appends."""

    __tablename__ = "audit_chain_heads"
    __table_args__ = CORE

    chain_key: Mapped[str] = mapped_column(String(64), primary_key=True)
    last_event_id: Mapped[int] = mapped_column(BigInteger)
    last_hash: Mapped[str] = mapped_column(String(64))


# --------------------------------------------------------------------------- idempotency & numbering


class IdempotencyKey(UUIDPk, Tenant, Base):
    __tablename__ = "idempotency_keys"
    __table_args__ = (UniqueConstraint("company_id", "actor_id", "key"), CORE)

    actor_id: Mapped[uuid.UUID]  # the user or API client that sent the request; keys are not shared between callers
    key: Mapped[str] = mapped_column(String(120))
    request_hash: Mapped[str] = mapped_column(String(64))
    status_code: Mapped[int | None] = mapped_column(Integer)
    response: Mapped[dict[str, Any] | None]
    created_at: Mapped[datetime] = mapped_column(server_default=func.now())
    expires_at: Mapped[datetime]


class NumberSequence(Base):
    """Per-company human-facing numbers, e.g. PRD-2026-000123."""

    __tablename__ = "number_sequences"
    __table_args__ = (CheckConstraint("last_value >= 0", name="nonneg"), CORE)

    company_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("core.companies.id", ondelete="CASCADE"), primary_key=True)
    key: Mapped[str] = mapped_column(String(20), primary_key=True)
    period: Mapped[str] = mapped_column(String(10), primary_key=True)
    last_value: Mapped[int] = mapped_column(BigInteger, default=0)


# Re-exported for type hints elsewhere.
__all__ = [
    "ApiClient", "AuditChainHead", "AuditEvent", "Company", "CompanyModule", "Department", "DepartmentAlias",
    "IdempotencyKey", "Membership", "MembershipDepartment", "Notification", "NumberSequence", "Organization",
    "Product", "ProductAlias", "ProductCategory", "RefreshToken", "Role", "RolePermission", "StoredFile", "Task",
    "Unit", "UnitAlias", "User", "WorkflowDefinition",
]
