"""Authentication, sessions (refresh-token families), memberships, companies, roles, API clients."""

from __future__ import annotations

import uuid
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from typing import Any

import pyotp
from pydantic import BaseModel, Field
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.config import get_settings
from app.core import audit
from app.core.catalog import permission_catalog
from app.core.context import RequestContext
from app.core.errors import Conflict, NotFound, PermissionDenied, Unauthorized, ValidationFailed
from app.core.models import (
    ApiClient,
    Company,
    CompanyModule,
    Department,
    Membership,
    MembershipDepartment,
    Organization,
    RefreshToken,
    Role,
    RolePermission,
    User,
)
from app.core.permissions import SYSTEM_ROLES, expand
from app.core.security import (
    create_access_token,
    decrypt_field,
    encrypt_field,
    hash_password,
    new_opaque_token,
    password_problems,
    token_hash,
    verify_password,
)
from app.db.session import bind_tenant

# --------------------------------------------------------------------------- authentication


@dataclass
class TokenPair:
    access_token: str
    expires_in: int
    refresh_token: str
    user: User


def authenticate(session: Session, email: str, password: str, otp: str | None, ip: str | None) -> User:
    settings = get_settings()
    user = session.execute(select(User).where(func.lower(User.email) == email.strip().lower())).scalar_one_or_none()
    now = datetime.now(UTC)
    if user is not None and user.locked_until and user.locked_until > now:
        audit.record(session, "auth.login_blocked", actor_type="user", actor_id=user.id, entity_type="user",
                     entity_id=user.id, context={"ip": ip})
        raise Unauthorized("Account temporarily locked after repeated failed sign-ins", code="account_locked")
    ok = verify_password(user.password_hash if user else None, password)
    if not ok or user is None or not user.is_active:
        if user is not None:
            user.failed_login_count += 1
            if user.failed_login_count >= settings.login_max_failures:
                user.locked_until = now + timedelta(minutes=settings.login_lockout_minutes)
                user.failed_login_count = 0
        audit.record(session, "auth.login_failed", actor_type="user", actor_id=user.id if user else None,
                     context={"ip": ip, "email_hash": token_hash(email.strip().lower())[:16]})
        raise Unauthorized("Invalid email or password", code="invalid_credentials")
    if user.mfa_enabled:
        if not otp:
            raise Unauthorized("Enter the code from your authenticator app", code="mfa_required")
        if not pyotp.TOTP(decrypt_field(user.mfa_secret)).verify(otp, valid_window=1):
            audit.record(session, "auth.mfa_failed", actor_type="user", actor_id=user.id, context={"ip": ip})
            raise Unauthorized("Invalid authentication code", code="mfa_invalid")
    user.failed_login_count = 0
    user.locked_until = None
    user.last_login_at = now
    audit.record(session, "auth.login", actor_type="user", actor_id=user.id, entity_type="user", entity_id=user.id,
                 context={"ip": ip})
    return user


def issue_tokens(session: Session, user: User, ip: str | None, user_agent: str | None,
                 family_id: uuid.UUID | None = None) -> TokenPair:
    settings = get_settings()
    refresh = new_opaque_token("rt_")
    session.add(RefreshToken(
        user_id=user.id, family_id=family_id or uuid.uuid4(), token_hash=token_hash(refresh),
        expires_at=datetime.now(UTC) + timedelta(days=settings.refresh_ttl_days),
        ip_address=ip, user_agent=(user_agent or "")[:300],
    ))
    access, ttl = create_access_token(user.id)
    return TokenPair(access, ttl, refresh, user)


def rotate_refresh(session: Session, refresh_token: str, ip: str | None, user_agent: str | None) -> TokenPair:
    row = session.execute(
        select(RefreshToken).where(RefreshToken.token_hash == token_hash(refresh_token)).with_for_update()
    ).scalar_one_or_none()
    now = datetime.now(UTC)
    if row is None or row.revoked_at is not None or row.expires_at <= now:
        raise Unauthorized("Session expired; please sign in again", code="session_expired")
    if row.used_at is not None:
        # A consumed refresh token was presented again: assume theft, revoke the whole family.
        revoke_family(session, row.family_id)
        audit.record(session, "auth.refresh_reuse_detected", actor_type="user", actor_id=row.user_id,
                     context={"ip": ip, "family_id": str(row.family_id)})
        raise Unauthorized("Session invalidated for security reasons; please sign in again", code="refresh_reuse")
    user = session.get(User, row.user_id)
    if user is None or not user.is_active:
        raise Unauthorized("Account disabled", code="account_disabled")
    row.used_at = now
    return issue_tokens(session, user, ip, user_agent, family_id=row.family_id)


def revoke_family(session: Session, family_id: uuid.UUID) -> None:
    now = datetime.now(UTC)
    for token in session.execute(select(RefreshToken).where(RefreshToken.family_id == family_id)).scalars():
        token.revoked_at = token.revoked_at or now


def logout(session: Session, refresh_token: str | None) -> None:
    if not refresh_token:
        return
    row = session.execute(select(RefreshToken).where(RefreshToken.token_hash == token_hash(refresh_token))).scalar_one_or_none()
    if row is not None:
        revoke_family(session, row.family_id)
        audit.record(session, "auth.logout", actor_type="user", actor_id=row.user_id)


def revoke_sessions(session: Session, user: User) -> None:
    """End every session: revoke all refresh tokens and reject access tokens issued until now."""
    now = datetime.now(UTC)
    user.sessions_revoked_at = now
    for token in session.execute(select(RefreshToken).where(RefreshToken.user_id == user.id, RefreshToken.revoked_at.is_(None))).scalars():
        token.revoked_at = now


def change_password(session: Session, user: User, current: str, new: str, ip: str | None, user_agent: str | None) -> TokenPair:
    """Change the password, end every session and start a fresh one for the caller."""
    if not verify_password(user.password_hash, current):
        raise ValidationFailed("Current password is incorrect", code="invalid_password")
    if new == current:
        raise ValidationFailed("The new password must differ from the current one", code="password_unchanged")
    problems = password_problems(new)
    if problems:
        raise ValidationFailed("Password must have " + ", ".join(problems), code="weak_password")
    user.password_hash = hash_password(new)
    revoke_sessions(session, user)
    audit.record(session, "auth.password_changed", actor_type="user", actor_id=user.id, entity_type="user", entity_id=user.id,
                 summary="Password changed; other sessions signed out")
    return issue_tokens(session, user, ip, user_agent)


def mfa_begin(session: Session, user: User, code: str | None = None, password: str | None = None) -> str:
    """Start enrolling an authenticator. The new secret only replaces the current one once confirmed;
    replacing an active authenticator needs its current code or the account password."""
    if user.mfa_enabled:
        if code:
            if not pyotp.TOTP(decrypt_field(user.mfa_secret)).verify(code, valid_window=1):
                raise ValidationFailed("Invalid authentication code", code="mfa_invalid")
        elif password:
            if not verify_password(user.password_hash, password):
                raise ValidationFailed("Current password is incorrect", code="invalid_password")
        else:
            raise ValidationFailed("Enter the current authentication code or your password to replace the authenticator",
                                   code="mfa_reauth_required")
    secret = pyotp.random_base32()
    user.mfa_pending_secret = encrypt_field(secret)
    return pyotp.TOTP(secret).provisioning_uri(name=user.email, issuer_name="BIWORK")


def mfa_confirm(session: Session, user: User, code: str) -> None:
    if not user.mfa_pending_secret or not pyotp.TOTP(decrypt_field(user.mfa_pending_secret)).verify(code, valid_window=1):
        raise ValidationFailed("Invalid authentication code", code="mfa_invalid")
    user.mfa_secret, user.mfa_pending_secret = user.mfa_pending_secret, None
    user.mfa_enabled = True
    audit.record(session, "auth.mfa_enabled", actor_type="user", actor_id=user.id, entity_type="user", entity_id=user.id,
                 summary="Authenticator app confirmed")


# --------------------------------------------------------------------------- memberships & context


def memberships_for(session: Session, user_id: uuid.UUID) -> list[Membership]:
    return list(session.execute(
        select(Membership).join(Company, Company.id == Membership.company_id)
        .where(Membership.user_id == user_id, Membership.is_active.is_(True), Company.is_active.is_(True))
    ).unique().scalars())


def build_user_context(session: Session, user: User, company_id: uuid.UUID, request_id: str, ip: str | None) -> RequestContext:
    """Verify membership and derive permissions + department scope. Non-members get 403 — the same
    answer whether or not the company exists, so ids of other tenants cannot be probed."""
    rows = [m for m in memberships_for(session, user.id) if m.company_id == company_id]
    if not rows:
        raise PermissionDenied("You do not have access to this company", code="company_access_denied")
    permissions: set[str] = set()
    departments: set[uuid.UUID] | None = set()
    for m in rows:
        permissions.update(p.permission for p in m.role.permissions)
        if not m.departments:
            departments = None
        elif departments is not None:
            departments.update(d.department_id for d in m.departments)
    return RequestContext(
        company_id=company_id, permissions=frozenset(permissions), user_id=user.id,
        department_ids=frozenset(departments) if departments is not None else None,
        request_id=request_id, actor_type="user", ip_address=ip,
    )


def context_for_user_id(session: Session, user_id: uuid.UUID, company_id: uuid.UUID, request_id: str = "agent") -> RequestContext:
    user = session.get(User, user_id)
    if user is None or not user.is_active:
        raise PermissionDenied("User is no longer active")
    return build_user_context(session, user, company_id, request_id, None)


def describe_user(session: Session, user: User) -> dict[str, Any]:
    companies: dict[uuid.UUID, dict[str, Any]] = {}
    for m in memberships_for(session, user.id):
        company = session.get(Company, m.company_id)
        entry = companies.setdefault(company.id, {
            "id": str(company.id), "name": company.name, "code": company.code, "is_sample_data": company.is_sample_data,
            "roles": [], "permissions": set(),
            "modules": [cm.module_code for cm in session.execute(
                select(CompanyModule).where(CompanyModule.company_id == company.id, CompanyModule.enabled.is_(True))
            ).scalars()],
        })
        entry["roles"].append({"code": m.role.code, "name": m.role.name})
        entry["permissions"].update(p.permission for p in m.role.permissions)
    return {
        "id": str(user.id), "email": user.email, "full_name": user.full_name, "locale": user.locale,
        "is_platform_admin": user.is_platform_admin, "mfa_enabled": user.mfa_enabled,
        "companies": [{**c, "permissions": sorted(c["permissions"])} for c in companies.values()],
    }


# --------------------------------------------------------------------------- roles


def sync_system_roles(session: Session) -> list[Role]:
    """Idempotently create/update the system role templates from the current permission catalog."""
    catalog = permission_catalog()
    roles = []
    for code, spec in SYSTEM_ROLES.items():
        role = session.execute(select(Role).where(Role.company_id.is_(None), Role.code == code)).scalar_one_or_none()
        if role is None:
            role = Role(company_id=None, code=code, name=spec["name"], description=spec["description"], is_system=True)
            session.add(role)
            session.flush()
        wanted = set(expand(spec["grants"], catalog))
        current = {p.permission: p for p in role.permissions}
        for perm in wanted - set(current):
            role.permissions.append(RolePermission(permission=perm))
        for perm in set(current) - wanted:
            role.permissions.remove(current[perm])
        roles.append(role)
    session.flush()
    return roles


def get_role(session: Session, company_id: uuid.UUID, code_or_id: str) -> Role:
    stmt = select(Role).where((Role.company_id.is_(None)) | (Role.company_id == company_id))
    try:
        stmt = stmt.where(Role.id == uuid.UUID(code_or_id))
    except ValueError:
        stmt = stmt.where(Role.code == code_or_id).order_by(Role.company_id.nulls_last())
    role = session.execute(stmt).scalars().first()
    if role is None:
        raise NotFound(f"Role '{code_or_id}' not found")
    return role


class RoleIn(BaseModel):
    code: str = Field(min_length=2, max_length=50, pattern=r"^[a-z0-9_]+$")
    name: str = Field(min_length=1, max_length=100)
    description: str | None = None
    permissions: list[str]


def create_role(session: Session, ctx: RequestContext, data: RoleIn) -> Role:
    ctx.require("admin.roles")
    unknown = sorted(set(data.permissions) - set(permission_catalog()))
    if unknown:
        raise ValidationFailed(f"Unknown permissions: {', '.join(unknown)}")
    escalation = sorted(set(data.permissions) - ctx.permissions)
    if escalation:
        raise PermissionDenied("You cannot grant permissions you do not hold", extra={"permissions": escalation})
    role = Role(company_id=ctx.company_id, code=data.code, name=data.name, description=data.description,
                permissions=[RolePermission(permission=p) for p in sorted(set(data.permissions))])
    session.add(role)
    session.flush()
    audit.record(session, "role.created", ctx=ctx, entity_type="role", entity_id=role.id,
                 changes={"code": role.code, "permissions": sorted(set(data.permissions))})
    return role


# --------------------------------------------------------------------------- companies


class CompanyIn(BaseModel):
    name: str = Field(min_length=1, max_length=200)
    code: str = Field(min_length=1, max_length=50)
    tin: str | None = Field(default=None, max_length=32)
    organization_code: str = Field(default="default", max_length=50)
    organization_name: str | None = None
    settings: dict[str, Any] = Field(default_factory=dict)
    modules: list[str] | None = None
    is_sample_data: bool = False


DEFAULT_COMPANY_SETTINGS: dict[str, Any] = {
    "locale": "uz",
    "timezone": "Asia/Tashkent",
    "default_currency": "UZS",
    "date_order": "DMY",
    "ai_policy": {
        "external_ai_allowed": True,
        "allowed_providers": ["openai", "gemini", "anthropic", "demo"],
        "send_images_to_ai": False,
        "redaction_rules": ["phone", "email", "passport", "card", "bank_account"],
        "minimize_context": True,
        "retain_ai_payloads_days": 0,
    },
}


def create_company(session: Session, actor: User, data: CompanyIn, available_modules: list[str]) -> Company:
    if not actor.is_platform_admin:
        raise PermissionDenied("Only platform administrators can create companies")
    org = session.execute(select(Organization).where(Organization.code == data.organization_code)).scalar_one_or_none()
    if org is None:
        org = Organization(code=data.organization_code, name=data.organization_name or data.organization_code)
        session.add(org)
        session.flush()
    if session.execute(select(Company.id).where(Company.organization_id == org.id, Company.code == data.code)).first():
        raise Conflict(f"Company code '{data.code}' already exists in this organization")
    settings = {**DEFAULT_COMPANY_SETTINGS, **data.settings,
                "ai_policy": {**DEFAULT_COMPANY_SETTINGS["ai_policy"], **data.settings.get("ai_policy", {})}}
    company = Company(organization_id=org.id, name=data.name, code=data.code, tin=data.tin, settings=settings,
                      is_sample_data=data.is_sample_data)
    session.add(company)
    session.flush()
    bind_tenant(session, company.id, actor.id)  # subsequent tenant rows (audit) are written under RLS
    for module in data.modules or available_modules:
        session.add(CompanyModule(company_id=company.id, module_code=module, enabled=True))
    admin_role = get_role(session, company.id, "admin")
    session.add(Membership(user_id=actor.id, company_id=company.id, role_id=admin_role.id))
    session.flush()
    audit.record(session, "company.created", company_id=company.id, actor_type="user", actor_id=actor.id,
                 entity_type="company", entity_id=company.id, summary=f"Company {company.name} created",
                 changes={"name": company.name, "code": company.code})
    return company


def update_company_settings(session: Session, ctx: RequestContext, values: dict[str, Any]) -> Company:
    ctx.require("admin.company")
    company = session.get(Company, ctx.company_id)
    allowed = {"locale", "timezone", "default_currency", "date_order", "ai_policy", "production_output_warehouse_id"}
    unknown = set(values) - allowed
    if unknown:
        raise ValidationFailed(f"Unknown settings: {', '.join(sorted(unknown))}")
    if "date_order" in values and values["date_order"] not in ("DMY", "MDY", "YMD"):
        raise ValidationFailed("date_order must be DMY, MDY or YMD")
    before = dict(company.settings)
    merged = {**company.settings, **values}
    if "ai_policy" in values:
        merged["ai_policy"] = {**company.settings.get("ai_policy", {}), **values["ai_policy"]}
    company.settings = merged
    session.flush()
    audit.record(session, "company.settings_updated", ctx=ctx, entity_type="company", entity_id=company.id,
                 changes=audit.diff(before, merged))
    return company


# --------------------------------------------------------------------------- members


# Structural check only: on-premises directories commonly use special-use domains such as ".local".
EMAIL_PATTERN = r"^[^@\s]{1,64}@[^@\s]+\.[^@\s]{2,}$"


class MemberIn(BaseModel):
    email: str = Field(pattern=EMAIL_PATTERN, max_length=320)
    full_name: str = Field(min_length=1, max_length=200)
    role: str = "operator"
    password: str | None = Field(default=None, description="Initial password (required for new users)")
    department_ids: list[uuid.UUID] = Field(default_factory=list)


def add_member(session: Session, ctx: RequestContext, data: MemberIn) -> Membership:
    ctx.require("admin.users")
    role = get_role(session, ctx.company_id, data.role)
    escalation = {p.permission for p in role.permissions} - ctx.permissions
    if escalation:
        raise PermissionDenied("You cannot assign a role with permissions you do not hold")
    user = session.execute(select(User).where(func.lower(User.email) == data.email.lower())).scalar_one_or_none()
    if user is not None and not _may_attach(session, ctx, user):
        # An account the actor cannot see (another tenant's) is answered like an unknown email, and
        # never attached or described: adding it needs a platform administrator.
        if not data.password:
            raise ValidationFailed("An initial password is required for a new user", extra={"field": "password"})
        raise Conflict("This email address cannot be used for a new user; ask a platform administrator to add the account",
                       code="email_unavailable")
    if user is None:
        if not data.password:
            raise ValidationFailed("An initial password is required for a new user", extra={"field": "password"})
        problems = password_problems(data.password)
        if problems:
            raise ValidationFailed("Password must have " + ", ".join(problems), extra={"field": "password"})
        user = User(email=data.email.lower(), full_name=data.full_name, password_hash=hash_password(data.password))
        session.add(user)
        session.flush()
    if session.execute(select(Membership.id).where(Membership.user_id == user.id, Membership.company_id == ctx.company_id,
                                                    Membership.role_id == role.id)).first():
        raise Conflict("User already has this role in the company")
    _check_departments(session, ctx.company_id, data.department_ids)
    membership = Membership(user_id=user.id, company_id=ctx.company_id, role_id=role.id,
                            departments=[MembershipDepartment(department_id=d) for d in data.department_ids])
    session.add(membership)
    session.flush()
    audit.record(session, "membership.created", ctx=ctx, entity_type="membership", entity_id=membership.id,
                 summary=f"{user.email} added as {role.name}",
                 changes={"user": user.email, "role": role.code, "departments": [str(d) for d in data.department_ids]})
    return membership


def _may_attach(session: Session, ctx: RequestContext, user: User) -> bool:
    """An existing account can be added by a platform administrator, or by someone who already
    administers that person in some company (including this one)."""
    actor = session.get(User, ctx.user_id) if ctx.user_id else None
    if actor is None:
        return False
    if actor.is_platform_admin:
        return True
    administered = (select(Membership.company_id).join(RolePermission, RolePermission.role_id == Membership.role_id)
                    .where(Membership.user_id == actor.id, Membership.is_active.is_(True), RolePermission.permission == "admin.users"))
    return session.execute(select(Membership.id).where(Membership.user_id == user.id,
                                                       Membership.company_id.in_(administered)).limit(1)).first() is not None


def _check_departments(session: Session, company_id: uuid.UUID, ids: list[uuid.UUID]) -> None:
    if not ids:
        return
    found = set(session.execute(select(Department.id).where(Department.company_id == company_id, Department.id.in_(ids))).scalars())
    if found != set(ids):
        raise ValidationFailed("Unknown department in department scope")


class MemberUpdate(BaseModel):
    role: str | None = None
    is_active: bool | None = None
    department_ids: list[uuid.UUID] | None = None


def update_member(session: Session, ctx: RequestContext, membership_id: uuid.UUID, data: MemberUpdate) -> Membership:
    ctx.require("admin.users")
    m = session.execute(select(Membership).where(Membership.id == membership_id, Membership.company_id == ctx.company_id)).unique().scalar_one_or_none()
    if m is None:
        raise NotFound("Membership not found")
    if m.user_id == ctx.user_id and data.is_active is False:
        raise ValidationFailed("You cannot deactivate your own membership")
    if {p.permission for p in m.role.permissions} - ctx.permissions:
        raise PermissionDenied("You cannot change a member whose role has permissions you do not hold")
    before = {"role": m.role.code, "is_active": m.is_active, "departments": sorted(str(d.department_id) for d in m.departments)}
    if data.role:
        role = get_role(session, ctx.company_id, data.role)
        if {p.permission for p in role.permissions} - ctx.permissions:
            raise PermissionDenied("You cannot assign a role with permissions you do not hold")
        m.role_id, m.role = role.id, role
    if data.is_active is not None:
        m.is_active = data.is_active
    if data.department_ids is not None:
        _check_departments(session, ctx.company_id, data.department_ids)
        m.departments = [MembershipDepartment(department_id=d) for d in data.department_ids]
    session.flush()
    after = {"role": m.role.code, "is_active": m.is_active, "departments": sorted(str(d.department_id) for d in m.departments)}
    audit.record(session, "membership.updated", ctx=ctx, entity_type="membership", entity_id=m.id, changes=audit.diff(before, after))
    return m


# --------------------------------------------------------------------------- API clients


class ApiClientIn(BaseModel):
    name: str = Field(min_length=1, max_length=100)
    scopes: list[str] = Field(default_factory=lambda: ["agent.submit", "agent.read"])
    expires_in_days: int | None = Field(default=365, ge=1, le=3650)


def create_api_client(session: Session, ctx: RequestContext, data: ApiClientIn) -> tuple[ApiClient, str]:
    ctx.require("admin.api_clients")
    unknown = set(data.scopes) - set(permission_catalog())
    if unknown:
        raise ValidationFailed(f"Unknown scopes: {', '.join(sorted(unknown))}")
    if any(s.startswith("admin.") for s in data.scopes):
        raise ValidationFailed("API clients cannot hold administrative scopes")
    if set(data.scopes) - ctx.permissions:
        raise PermissionDenied("You cannot grant scopes you do not hold")
    key = new_opaque_token("bwk_")
    client = ApiClient(
        company_id=ctx.company_id, name=data.name, key_prefix=key[:12], key_hash=token_hash(key), scopes=sorted(data.scopes),
        created_by=ctx.user_id,
        expires_at=datetime.now(UTC) + timedelta(days=data.expires_in_days) if data.expires_in_days else None,
    )
    session.add(client)
    session.flush()
    audit.record(session, "api_client.created", ctx=ctx, entity_type="api_client", entity_id=client.id,
                 changes={"name": client.name, "scopes": client.scopes})
    return client, key


def authenticate_api_key(session: Session, key: str, request_id: str, ip: str | None) -> RequestContext:
    client = session.execute(select(ApiClient).where(ApiClient.key_hash == token_hash(key))).scalar_one_or_none()
    now = datetime.now(UTC)
    if client is None or client.revoked_at or (client.expires_at and client.expires_at <= now):
        raise Unauthorized("Invalid API key", code="invalid_api_key")
    company = session.get(Company, client.company_id)
    if company is None or not company.is_active:
        raise Unauthorized("Invalid API key", code="invalid_api_key")
    client.last_used_at = now
    return RequestContext(company_id=client.company_id, permissions=frozenset(client.scopes), api_client_id=client.id,
                          request_id=request_id, actor_type="api_client", ip_address=ip)


def revoke_api_client(session: Session, ctx: RequestContext, client_id: uuid.UUID) -> None:
    ctx.require("admin.api_clients")
    client = session.execute(select(ApiClient).where(ApiClient.id == client_id, ApiClient.company_id == ctx.company_id)).scalar_one_or_none()
    if client is None:
        raise NotFound("API client not found")
    client.revoked_at = datetime.now(UTC)
    audit.record(session, "api_client.revoked", ctx=ctx, entity_type="api_client", entity_id=client.id)
