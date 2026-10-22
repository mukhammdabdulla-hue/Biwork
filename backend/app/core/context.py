"""The caller context every service receives explicitly (docs/architecture.md §5.3)."""

from __future__ import annotations

import dataclasses
import uuid
from dataclasses import dataclass, field
from typing import Any

from app.core.errors import PermissionDenied


@dataclass(frozen=True)
class RequestContext:
    company_id: uuid.UUID
    permissions: frozenset[str]
    user_id: uuid.UUID | None = None
    api_client_id: uuid.UUID | None = None
    department_ids: frozenset[uuid.UUID] | None = None  # None = all departments
    request_id: str = "-"
    actor_type: str = "user"  # user|api_client|system|agent
    ip_address: str | None = None
    # Provenance stamped onto audit events when the Agent writes on behalf of an approver.
    provenance: dict[str, Any] = field(default_factory=dict)

    @property
    def actor_id(self) -> uuid.UUID | None:
        return self.user_id or self.api_client_id

    def has(self, permission: str) -> bool:
        return permission in self.permissions

    def require(self, *permissions: str) -> None:
        missing = [p for p in permissions if p not in self.permissions]
        if missing:
            raise PermissionDenied(f"Missing permission: {', '.join(missing)}", extra={"missing": missing})

    def can_access_department(self, department_id: uuid.UUID | None) -> bool:
        return self.department_ids is None or department_id is None or department_id in self.department_ids

    def require_department(self, department_id: uuid.UUID | None) -> None:
        if not self.can_access_department(department_id):
            raise PermissionDenied("You do not have access to this department")

    def with_provenance(self, **values: Any) -> RequestContext:
        return dataclasses.replace(self, provenance={**self.provenance, **values})
