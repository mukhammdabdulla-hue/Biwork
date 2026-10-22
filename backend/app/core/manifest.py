"""The contract between a module and the platform (docs/architecture.md §3.3).

To make a new kind of record ingestible, a module adds a `RecordTypeSpec` to its manifest;
nothing in the Agent changes.
"""

from __future__ import annotations

import uuid
from collections.abc import Callable, Iterable
from dataclasses import dataclass, field
from decimal import Decimal
from typing import TYPE_CHECKING, Any, Literal

from pydantic import BaseModel

if TYPE_CHECKING:
    from fastapi import APIRouter
    from sqlalchemy.orm import Session

    from app.core.context import RequestContext

FieldType = Literal["string", "text", "integer", "decimal", "date", "enum", "reference", "unit", "currency", "boolean"]


@dataclass(frozen=True)
class FieldSpec:
    name: str
    label: str
    type: FieldType
    required: bool = False
    description: str = ""
    reference: str | None = None  # entity type resolved by matching: product, department, shift, ...
    enum: tuple[str, ...] = ()
    aliases: tuple[str, ...] = ()  # spreadsheet header synonyms (en / ru / uz)
    max_length: int | None = None
    quantity_unit_field: str | None = None  # for decimals that are quantities: the unit field name
    # For references: False = an unresolved value is kept as text (warning), not an error.
    match_required: bool = True


@dataclass(frozen=True)
class Issue:
    rule_code: str
    severity: Literal["error", "warning", "info"]
    message: str
    field: str | None = None
    details: dict[str, Any] | None = None


@dataclass
class RuleInput:
    """What a validator sees: normalized payload values plus resolved references."""

    payload: dict[str, Any]
    references: dict[str, dict[str, Any]]
    company_id: uuid.UUID
    session: Session
    settings: dict[str, Any]

    def ref_id(self, name: str) -> uuid.UUID | None:
        ref = self.references.get(name)
        return uuid.UUID(ref["id"]) if ref and ref.get("id") else None

    def decimal(self, name: str) -> Decimal | None:
        value = self.payload.get(name)
        return Decimal(str(value)) if value not in (None, "") else None


@dataclass(frozen=True)
class DuplicateCandidate:
    entity_type: str
    entity_id: uuid.UUID
    label: str
    score: float
    kind: Literal["duplicate_record", "pending_record"] = "duplicate_record"
    blocking: bool = True  # a natural-key collision blocks commit; a near-duplicate only warns
    details: dict[str, Any] | None = None


@dataclass(frozen=True)
class WriteResult:
    target_table: str
    target_id: uuid.UUID
    number: str | None = None
    label: str | None = None


Validator = Callable[[RuleInput], Iterable[Issue]]
Matcher = Callable[[RuleInput], Iterable[DuplicateCandidate]]
Writer = Callable[["Session", "RequestContext", BaseModel, uuid.UUID | None], WriteResult]


@dataclass(frozen=True)
class RecordTypeSpec:
    code: str
    module: str
    label: str
    description: str  # used by classification prompts
    fields: tuple[FieldSpec, ...]
    schema: type[BaseModel]  # validated write input built from payload + references
    writer: Writer
    write_permission: str
    approve_permission: str
    line_fields: tuple[FieldSpec, ...] = ()
    lines_label: str = "Lines"
    validators: tuple[Validator, ...] = ()  # run after matching (they may use resolved references)
    matcher: Matcher | None = None  # existing-record / duplicate detection
    fingerprint_fields: tuple[str, ...] = ()
    approval_policy: Literal["required", "auto_if_enabled"] = "required"
    schema_version: str = "1"
    summary: Callable[[dict[str, Any]], str] | None = None

    def field(self, name: str) -> FieldSpec | None:
        for spec in (*self.fields, *self.line_fields):
            if spec.name == name:
                return spec
        return None

    @property
    def has_lines(self) -> bool:
        return bool(self.line_fields)


@dataclass(frozen=True)
class NavEntry:
    path: str
    label: str
    icon: str


@dataclass(frozen=True)
class ModuleManifest:
    code: str
    name: str
    description: str
    permissions: tuple[str, ...] = ()
    record_types: tuple[RecordTypeSpec, ...] = ()
    nav: NavEntry | None = None
    available: bool = True  # False = planned module: UI shows "not available yet", never fake data
    phase: int = 1
    router_factory: Callable[[], APIRouter] | None = None
    entity_resolvers: dict[str, Any] = field(default_factory=dict)
