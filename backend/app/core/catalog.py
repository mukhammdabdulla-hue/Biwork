"""Runtime catalogs that modules register into on import (keeps Core independent of modules)."""

from __future__ import annotations

from app.core.permissions import CORE_PERMISSIONS

_PERMISSIONS: set[str] = set(CORE_PERMISSIONS)


def register_permissions(*permissions: str) -> None:
    _PERMISSIONS.update(permissions)


def permission_catalog() -> list[str]:
    return sorted(_PERMISSIONS)
