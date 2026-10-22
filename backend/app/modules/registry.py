"""Module registry: the single place that knows which modules are installed.

Importing a manifest registers its permissions and entity resolvers. The Agent asks the registry
for record types; it never imports module internals (docs/architecture.md §3.3–3.4).
"""

from __future__ import annotations

from functools import lru_cache

from app.core.catalog import register_permissions
from app.core.manifest import ModuleManifest, RecordTypeSpec
from app.core.module import CORE_MANIFEST

ANALYTICS_MANIFEST = ModuleManifest(
    code="analytics", name="BIWORK Analytics", description="KPIs, dashboards, plan-vs-actual, ingestion statistics.",
    permissions=("analytics.read",), phase=1,
)


@lru_cache
def manifests() -> tuple[ModuleManifest, ...]:
    from app.modules.inventory.manifest import MANIFEST as INVENTORY
    from app.modules.planned.manifest import PLANNED
    from app.modules.production.manifest import MANIFEST as PRODUCTION
    from app.modules.supply.manifest import MANIFEST as SUPPLY

    result = (CORE_MANIFEST, PRODUCTION, INVENTORY, SUPPLY, ANALYTICS_MANIFEST, *PLANNED)
    for m in result:
        register_permissions(*m.permissions)
    return result


def available_module_codes() -> list[str]:
    return [m.code for m in manifests() if m.available and m.code != "core"]


@lru_cache
def record_types() -> dict[str, RecordTypeSpec]:
    return {rt.code: rt for m in manifests() for rt in m.record_types}


def record_type(code: str) -> RecordTypeSpec:
    try:
        return record_types()[code]
    except KeyError as exc:
        raise KeyError(f"Unknown record type '{code}'") from exc


def record_types_for_modules(enabled_modules: list[str]) -> list[RecordTypeSpec]:
    return [rt for rt in record_types().values() if rt.module == "core" or rt.module in enabled_modules]
