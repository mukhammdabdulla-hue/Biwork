"""Administration API: companies, modules, members, roles, departments, API clients, workflows, system health.

Lives in the `app.api` composition layer because it spans Core, the module registry and the Agent.
"""

from __future__ import annotations

import uuid
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from fastapi import APIRouter, Response
from pydantic import BaseModel, Field
from sqlalchemy import func, select

from app.agent.models import AICall, IngestionJob, StageRun
from app.agent.pipeline.ingest import enabled_modules
from app.ai.router import demo_mode
from app.ai.router import health_report as provider_health
from app.config import get_settings
from app.core import audit
from app.core.api import DB, Ctx, CurrentUser
from app.core.catalog import permission_catalog
from app.core.errors import NotFound, ValidationFailed
from app.core.models import ApiClient, Company, CompanyModule, Department, Membership, Role, User
from app.core.services import identity, master_data, workflows
from app.modules.registry import available_module_codes, manifests, record_types, record_types_for_modules

router = APIRouter(tags=["administration"])


# --------------------------------------------------------------------------- companies & modules


@router.get("/companies")
def my_companies(user: CurrentUser, db: DB) -> list[dict[str, Any]]:
    return identity.describe_user(db, user)["companies"]


@router.post("/companies", status_code=201)
def create_company(data: identity.CompanyIn, user: CurrentUser, db: DB) -> dict[str, Any]:
    company = identity.create_company(db, user, data, available_module_codes())
    db.commit()
    return {"id": str(company.id), "name": company.name, "code": company.code}


def company_out(db, company: Company) -> dict[str, Any]:
    enabled = {cm.module_code: cm.enabled for cm in db.execute(
        select(CompanyModule).where(CompanyModule.company_id == company.id)).scalars()}
    return {"id": str(company.id), "name": company.name, "code": company.code, "tin": company.tin,
            "is_sample_data": company.is_sample_data, "settings": company.settings,
            "modules": [{"code": m.code, "name": m.name, "description": m.description, "available": m.available, "phase": m.phase,
                         "enabled": bool(enabled.get(m.code)) if m.code != "core" else True,
                         "nav": {"path": m.nav.path, "label": m.nav.label, "icon": m.nav.icon} if m.nav else None}
                        for m in manifests()]}


@router.get("/company")
def current_company(ctx: Ctx, db: DB) -> dict[str, Any]:
    return company_out(db, db.get(Company, ctx.company_id))


class SettingsIn(BaseModel):
    settings: dict[str, Any]


@router.patch("/company/settings")
def update_settings(data: SettingsIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    company = identity.update_company_settings(db, ctx, data.settings)
    db.commit()
    return company_out(db, company)


class ModuleToggle(BaseModel):
    enabled: bool


@router.put("/company/modules/{code}")
def toggle_module(code: str, data: ModuleToggle, ctx: Ctx, db: DB) -> dict[str, Any]:
    ctx.require("admin.company")
    manifest = next((m for m in manifests() if m.code == code), None)
    if manifest is None or code == "core":
        raise NotFound("Unknown module")
    if data.enabled and not manifest.available:
        raise ValidationFailed(f"{manifest.name} is not available yet (planned for phase {manifest.phase})")
    row = db.get(CompanyModule, (ctx.company_id, code))
    if row is None:
        row = CompanyModule(company_id=ctx.company_id, module_code=code)
        db.add(row)
    row.enabled = data.enabled
    audit.record(db, "company.module_toggled", ctx=ctx, entity_type="company", entity_id=ctx.company_id,
                 summary=f"Module {manifest.name} {'enabled' if data.enabled else 'disabled'}",
                 changes={"module": code, "enabled": data.enabled})
    db.commit()
    return company_out(db, db.get(Company, ctx.company_id))


# --------------------------------------------------------------------------- members & roles


def member_out(m: Membership, user: User) -> dict[str, Any]:
    return {"id": str(m.id), "user_id": str(user.id), "email": user.email, "full_name": user.full_name,
            "role": {"id": str(m.role.id), "code": m.role.code, "name": m.role.name}, "is_active": m.is_active and user.is_active,
            "department_ids": [str(d.department_id) for d in m.departments], "last_login_at": user.last_login_at,
            "mfa_enabled": user.mfa_enabled}


@router.get("/members")
def list_members(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("admin.users")
    rows = db.execute(select(Membership, User).join(User, User.id == Membership.user_id)
                      .where(Membership.company_id == ctx.company_id).order_by(User.full_name)).unique().all()
    return [member_out(m, u) for m, u in rows]


@router.get("/members/directory")
def member_directory(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    """Minimal list of colleagues (for assignee pickers); available to every member."""
    rows = db.execute(select(User.id, User.full_name, User.email).join(Membership, Membership.user_id == User.id)
                      .where(Membership.company_id == ctx.company_id, Membership.is_active.is_(True)).distinct()
                      .order_by(User.full_name)).all()
    return [{"id": str(i), "full_name": n, "email": e} for i, n, e in rows]


@router.post("/members", status_code=201)
def add_member(data: identity.MemberIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    m = identity.add_member(db, ctx, data)
    db.commit()
    return member_out(m, db.get(User, m.user_id))


@router.patch("/members/{membership_id}")
def update_member(membership_id: uuid.UUID, data: identity.MemberUpdate, ctx: Ctx, db: DB) -> dict[str, Any]:
    m = identity.update_member(db, ctx, membership_id, data)
    db.commit()
    return member_out(m, db.get(User, m.user_id))


@router.get("/roles")
def list_roles(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    roles = db.execute(select(Role).where((Role.company_id.is_(None)) | (Role.company_id == ctx.company_id))
                       .order_by(Role.is_system.desc(), Role.name)).scalars()
    return [{"id": str(r.id), "code": r.code, "name": r.name, "description": r.description, "is_system": r.is_system,
             "permissions": sorted(p.permission for p in r.permissions)} for r in roles]


@router.get("/permissions")
def list_permissions(ctx: Ctx) -> list[str]:
    return permission_catalog()


@router.post("/roles", status_code=201)
def create_role(data: identity.RoleIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    role = identity.create_role(db, ctx, data)
    db.commit()
    return {"id": str(role.id), "code": role.code, "name": role.name}


# --------------------------------------------------------------------------- departments


def department_out(db, d: Department) -> dict[str, Any]:
    return {"id": str(d.id), "code": d.code, "name": d.name, "kind": d.kind, "parent_id": str(d.parent_id) if d.parent_id else None,
            "is_active": d.is_active, "aliases": sorted(master_data.department_aliases(db, d.company_id, d.id))}


@router.get("/departments")
def list_departments(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    rows = db.execute(select(Department).where(Department.company_id == ctx.company_id).order_by(Department.code)).scalars()
    return [department_out(db, d) for d in rows]


@router.post("/departments", status_code=201)
def create_department(data: master_data.DepartmentIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    d = master_data.create_department(db, ctx, data)
    db.commit()
    return department_out(db, d)


class AliasesIn(BaseModel):
    aliases: list[str] = Field(max_length=50)


@router.put("/departments/{department_id}/aliases")
def set_department_aliases(department_id: uuid.UUID, data: AliasesIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    d = master_data.update_department_aliases(db, ctx, department_id, data.aliases)
    db.commit()
    return department_out(db, d)


# --------------------------------------------------------------------------- API clients


@router.get("/api-clients")
def list_api_clients(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    ctx.require("admin.api_clients")
    rows = db.execute(select(ApiClient).where(ApiClient.company_id == ctx.company_id).order_by(ApiClient.created_at.desc())).scalars()
    return [{"id": str(c.id), "name": c.name, "key_prefix": c.key_prefix, "scopes": c.scopes, "created_at": c.created_at,
             "last_used_at": c.last_used_at, "expires_at": c.expires_at, "revoked_at": c.revoked_at} for c in rows]


@router.post("/api-clients", status_code=201)
def create_api_client(data: identity.ApiClientIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    client, key = identity.create_api_client(db, ctx, data)
    db.commit()
    return {"id": str(client.id), "name": client.name, "scopes": client.scopes, "api_key": key,
            "note": "Store this key now; it cannot be shown again."}


@router.delete("/api-clients/{client_id}", status_code=204)
def revoke_api_client(client_id: uuid.UUID, ctx: Ctx, db: DB) -> Response:
    identity.revoke_api_client(db, ctx, client_id)
    db.commit()
    return Response(status_code=204)


# --------------------------------------------------------------------------- approval workflows


@router.get("/workflows")
def list_workflows(ctx: Ctx, db: DB) -> list[dict[str, Any]]:
    out = []
    for rt in record_types_for_modules(enabled_modules(db, ctx.company_id)):
        wf = workflows.get_workflow(db, ctx.company_id, rt.code)
        out.append({"record_type": rt.code, "label": rt.label, "module": rt.module, "approve_permission": rt.approve_permission,
                    "auto_approve": bool(wf and wf.auto_approve),
                    "confidence_threshold": float(wf.confidence_threshold) if wf and wf.confidence_threshold is not None
                    else get_settings().agent_confidence_threshold})
    return out


class WorkflowIn(BaseModel):
    auto_approve: bool
    confidence_threshold: float | None = Field(default=None, ge=0.5, le=1.0)


@router.put("/workflows/{record_type}")
def set_workflow(record_type: str, data: WorkflowIn, ctx: Ctx, db: DB) -> dict[str, Any]:
    if record_type not in record_types():
        raise NotFound("Unknown record type")
    wf = workflows.set_workflow(db, ctx, record_type, auto_approve=data.auto_approve,
                                confidence_threshold=Decimal(str(data.confidence_threshold)) if data.confidence_threshold else None)
    db.commit()
    return {"record_type": wf.record_type, "auto_approve": wf.auto_approve,
            "confidence_threshold": float(wf.confidence_threshold) if wf.confidence_threshold is not None else None}


# --------------------------------------------------------------------------- system health (company scope)


@router.get("/admin/system/queues")
def system_queues(ctx: Ctx, db: DB) -> dict[str, Any]:
    ctx.require("admin.system")
    by_status = dict(db.execute(select(IngestionJob.status, func.count()).where(IngestionJob.company_id == ctx.company_id)
                                .group_by(IngestionJob.status)).all())
    running = db.execute(select(StageRun).where(StageRun.company_id == ctx.company_id, StageRun.status == "running")
                         .order_by(StageRun.started_at)).scalars().all()
    failed_runs = db.execute(select(StageRun).where(StageRun.company_id == ctx.company_id, StageRun.status == "failed")
                             .order_by(StageRun.started_at.desc()).limit(20)).scalars().all()
    month_start = datetime.now(UTC).replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    ai = db.execute(select(AICall.provider, AICall.model, func.count(), func.coalesce(func.sum(AICall.input_tokens), 0),
                           func.coalesce(func.sum(AICall.output_tokens), 0), func.coalesce(func.sum(AICall.cost_usd), 0),
                           func.count().filter(AICall.status != "ok"))
                    .where(AICall.company_id == ctx.company_id, AICall.created_at >= month_start)
                    .group_by(AICall.provider, AICall.model)).all()
    backlog: dict[str, Any] = {}
    s = get_settings()
    if s.celery_always_eager:
        backlog = {"mode": "in-process (CELERY_ALWAYS_EAGER)"}
    elif s.redis_url:
        try:
            import redis

            client = redis.Redis.from_url(s.broker_url, socket_timeout=1)
            backlog = {q: client.llen(q) for q in ("parse", "ai", "commit", "default")}
        except Exception as exc:  # noqa: BLE001
            backlog = {"error": f"broker unreachable: {exc.__class__.__name__}"}
    return {
        "jobs_by_status": by_status,
        "running_stages": [{"job_id": str(r.job_id), "stage": r.stage, "started_at": r.started_at, "attempt": r.attempt} for r in running],
        "recent_failures": [{"job_id": str(r.job_id), "stage": r.stage, "error": r.error, "at": r.started_at, "attempt": r.attempt}
                            for r in failed_runs],
        "ai_providers": provider_health(),
        "ai_usage_this_month": [{"provider": p, "model": m, "calls": c, "input_tokens": int(i), "output_tokens": int(o),
                                 "cost_usd": float(cost), "errors": e} for p, m, c, i, o, cost, e in ai],
        "queue_backlog": backlog,
        "config": {"ai_provider": s.ai_provider, "ai_mode": "demo" if demo_mode() else "live", "ocr_enabled": s.ocr_enabled, "malware_scanning": bool(s.clamav_host),
                   "storage": s.storage_backend},
    }

