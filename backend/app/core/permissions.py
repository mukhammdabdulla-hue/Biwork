"""Permission catalog and system role templates.

Permissions are `<module>.<action>` strings declared by module manifests. System roles are
templates (company_id NULL) whose grants are patterns resolved against the catalog, so Core does
not need to know which modules exist.
"""

from __future__ import annotations

import fnmatch

CORE_PERMISSIONS = (
    "admin.company", "admin.users", "admin.roles", "admin.api_clients", "admin.system",
    "audit.read",
    "master.read", "master.write", "master.approve",
    "tasks.read", "tasks.write", "tasks.approve",
    "files.read", "files.write",
    "agent.read", "agent.submit", "agent.review",
    "analytics.read",
)

SYSTEM_ROLES: dict[str, dict] = {
    "admin": {
        "name": "Administrator",
        "description": "Full access to the company, including users, roles, settings and audit.",
        "grants": ["*"],
    },
    "manager": {
        "name": "Manager",
        "description": "Approves records and manages operational data across modules.",
        "grants": ["*.read", "*.write", "*.approve", "agent.*", "files.*", "audit.read"],
    },
    "operator": {
        "name": "Operator",
        "description": "Submits data to BIWORK Agent, corrects extractions, creates drafts. Cannot approve.",
        "grants": ["*.read", "agent.*", "files.*", "tasks.write", "production.write", "inventory.write", "supply.write"],
    },
    "viewer": {
        "name": "Viewer",
        "description": "Read-only access to operational data and dashboards.",
        "grants": ["*.read"],
    },
}

_NEVER_IMPLIED = {"audit.read"}  # must be granted explicitly (not by "*.read")


def expand(grants: list[str], catalog: list[str]) -> list[str]:
    result = set()
    for grant in grants:
        for perm in catalog:
            if grant == "*" or grant == perm or (fnmatch.fnmatch(perm, grant) and perm not in _NEVER_IMPLIED):
                if grant.startswith("*.") and perm.startswith("admin."):
                    continue
                result.add(perm)
    return sorted(result)
