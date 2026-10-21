"""Modules on the roadmap (docs/architecture.md §3.2, Phase 4). They appear in navigation with an
explicit "not available yet" state — never with placeholder data."""

from __future__ import annotations

from app.core.manifest import ModuleManifest, NavEntry

PLANNED = (
    ModuleManifest("hr", "BIWORK HR", "Employee records, organizational structure, attendance, shifts, leave, workforce allocation.",
                   nav=NavEntry("/hr", "HR", "users"), available=False, phase=4),
    ModuleManifest("edo", "BIWORK EDO", "Document registration and storage, approvals, internal correspondence, deadlines.",
                   nav=NavEntry("/edo", "EDO", "file-text"), available=False, phase=4),
    ModuleManifest("finance", "BIWORK Finance", "Budgets, cost calculations, production expenses, financial reports, profitability.",
                   nav=NavEntry("/finance", "Finance", "landmark"), available=False, phase=4),
    ModuleManifest("maintenance", "BIWORK Maintenance", "Equipment register, maintenance plans, repair requests, downtime.",
                   nav=NavEntry("/maintenance", "Maintenance", "wrench"), available=False, phase=4),
    ModuleManifest("quality", "BIWORK Quality",
                   "Inspections, nonconformities, corrective actions, traceability. Until then, defect quantities captured on "
                   "production reports are shown here.",
                   nav=NavEntry("/quality", "Quality", "shield-check"), available=False, phase=4),
)
