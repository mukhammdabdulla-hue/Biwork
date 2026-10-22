"""Test harness: real PostgreSQL (with row-level security), in-process Celery, mock AI adapter.

Environment is configured before any `app` import so settings and the engine pick it up.
"""

from __future__ import annotations

import os
import tempfile
import uuid
from collections.abc import Iterator
from dataclasses import dataclass, field
from datetime import time
from decimal import Decimal
from typing import Any

TEST_DB = os.environ.get("TEST_DATABASE_NAME", "biwork_test")
DB_HOST = os.environ.get("TEST_DATABASE_HOST", "localhost")
os.environ.update({
    "APP_ENV": "test",
    "DATABASE_URL": os.environ.get("TEST_DATABASE_URL", f"postgresql+psycopg://biwork_app:biwork_app@{DB_HOST}:5432/{TEST_DB}"),
    "DATABASE_MIGRATION_URL": os.environ.get("TEST_DATABASE_MIGRATION_URL", f"postgresql+psycopg://biwork:biwork@{DB_HOST}:5432/{TEST_DB}"),
    "CELERY_ALWAYS_EAGER": "true",
    "AI_PROVIDER": "mock",  # alias of demo; tests that exercise real adapters switch with the ai_config fixture
    "AI_RETRY_BASE_DELAY_S": "0",
    "REDIS_URL": "",
    "STORAGE_DIR": tempfile.mkdtemp(prefix="biwork-test-storage-"),
    "AUTH_RATE_LIMIT_PER_MINUTE": "10000",
    "RATE_LIMIT_PER_MINUTE": "100000",
    "CLAMAV_HOST": "",
})

import pytest  # noqa: E402
from alembic import command  # noqa: E402
from alembic.config import Config  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402
from sqlalchemy import create_engine, text  # noqa: E402

from app.config import get_settings  # noqa: E402

BACKEND = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASSWORD = "Test-Password-123"

# Tables that keep their seed data between tests.
KEEP = {"core.units", "core.unit_aliases", "public.alembic_version"}


@pytest.fixture(scope="session")
def owner_engine():
    engine = create_engine(get_settings().database_migration_url)
    yield engine
    engine.dispose()


@pytest.fixture(scope="session", autouse=True)
def migrated(owner_engine):
    cfg = Config(os.path.join(BACKEND, "alembic.ini"))
    cfg.set_main_option("script_location", os.path.join(BACKEND, "migrations"))
    command.downgrade(cfg, "base")
    command.upgrade(cfg, "head")
    with owner_engine.begin() as conn:
        tables = [f"{s}.{t}" for s, t in conn.execute(text(
            "SELECT schemaname, tablename FROM pg_tables WHERE schemaname IN ('core','agent','production','inventory','supply')"
        )).all()]
    yield [t for t in tables if t not in KEEP]


@pytest.fixture(autouse=True)
def clean_db(migrated, owner_engine) -> Iterator[None]:
    with owner_engine.begin() as conn:
        conn.execute(text(f"TRUNCATE {', '.join(migrated)} RESTART IDENTITY CASCADE"))
    from app.cli import bootstrap

    bootstrap()
    yield


@pytest.fixture
def client() -> TestClient:
    from app.main import app

    return TestClient(app)


@pytest.fixture
def ai_config(monkeypatch):
    """Reconfigure the AI layer for one test: `ai_config(providers={"openai": fake}, OPENAI_API_KEY="k", ...)`.
    Environment variables are applied, settings/router/health/cache are reset, and injected provider
    instances (real adapter classes on a fake HTTP transport) replace the SDK-backed ones."""
    from app.agent import versioning
    from app.ai import cache, health, router

    def reset() -> None:
        get_settings.cache_clear()
        router.reset()
        health.reset()
        cache.clear()
        versioning.pipeline_version.cache_clear()

    def configure(providers: dict[str, Any] | None = None, **env: Any) -> None:
        for key, value in env.items():
            monkeypatch.setenv(key.upper(), str(value))
        reset()
        for name, provider in (providers or {}).items():
            router._instances[name] = provider

    yield configure
    monkeypatch.undo()
    reset()


def rule_based_live_provider(name: str = "openai", model: str = "test-model-default"):
    """A provider that answers like the demo rules but presents itself as a real, external one —
    for pipeline tests of live-mode behaviour (routing, auto-approval, policy) without network."""
    from app.ai.providers.demo import DemoProvider

    class RuleBasedLive(DemoProvider):
        is_mock = False
        external = True
        supports_vision = True

    provider = RuleBasedLive()
    provider.name, provider.model = name, model
    return provider


# --------------------------------------------------------------------------- world


@dataclass
class Tenant:
    id: str
    name: str
    departments: dict[str, Any] = field(default_factory=dict)
    products: dict[str, Any] = field(default_factory=dict)
    warehouses: dict[str, Any] = field(default_factory=dict)
    shifts: dict[str, Any] = field(default_factory=dict)


@dataclass
class World:
    a: Tenant
    b: Tenant
    users: dict[str, str]  # key -> email

    def login(self, client: TestClient, who: str, company: Tenant | None = None) -> dict[str, str]:
        r = client.post("/api/v1/auth/login", json={"email": self.users[who], "password": PASSWORD})
        assert r.status_code == 200, r.text
        return {"Authorization": f"Bearer {r.json()['access_token']}", "X-Company-Id": (company or self.a).id}


def _build_tenant(session, admin, code: str, name: str, *, sample: bool = False) -> Tenant:
    from app.cli import admin_ctx
    from app.core.services import identity, master_data
    from app.modules.inventory import service as inventory
    from app.modules.production import service as production
    from app.modules.registry import available_module_codes
    from app.modules.supply import service as supply

    company = identity.create_company(session, admin, identity.CompanyIn(name=name, code=code, organization_code="TEST-ORG",
                                                                         is_sample_data=sample), available_module_codes())
    session.commit()
    ctx = admin_ctx(session, company.id, admin.id)
    t = Tenant(id=str(company.id), name=name)
    for dcode, dname, kind, aliases in [("SEX-1", "1-sex", "workshop", ["Sex 1", "Цех №1"]),
                                        ("SEX-2", "2-sex", "workshop", ["Sex 2", "Цех №2"]),
                                        ("TAMINOT", "Ta'minot bo'limi", "office", ["Снабжение"])]:
        t.departments[dcode] = master_data.create_department(session, ctx, master_data.DepartmentIn(code=dcode, name=dname, kind=kind,
                                                                                                    aliases=aliases)).id
    for pcode, pname, unit, ptype, aliases in [("A-001", "A mahsulot", "pcs", "finished_good", ["Product A"]),
                                               ("B-001", "B mahsulot", "pcs", "finished_good", ["Product B"]),
                                               ("RM-CEM", "Sement M400", "bag", "raw_material", ["Цемент М400"]),
                                               ("RM-STL", "Po'lat list 2mm", "kg", "raw_material", ["Стальной лист 2мм"])]:
        t.products[pcode] = master_data.create_product(session, ctx, master_data.ProductIn(
            code=pcode, name=pname, base_unit=unit, product_type=ptype, aliases=aliases, reorder_level=Decimal(100))).id
    t.warehouses["FG"] = inventory.create_warehouse(session, ctx, inventory.WarehouseIn(code="FG", name="Tayyor mahsulot ombori",
                                                                                        kind="finished")).id
    t.warehouses["RM"] = inventory.create_warehouse(session, ctx, inventory.WarehouseIn(code="RM", name="Xomashyo ombori", kind="raw")).id
    for scode, sname in [("1", "1-smena"), ("2", "2-smena")]:
        t.shifts[scode] = production.create_shift(session, ctx, production.ShiftIn(code=scode, name=sname, starts_at=time(8))).id
    supply.create_supplier(session, ctx, supply.SupplierIn(code="QQS", name="Qizilqumsement AJ", tin="200123456"))
    session.commit()
    return t


@pytest.fixture
def world() -> World:
    from app.cli import admin_ctx, create_admin
    from app.core.models import User
    from app.core.services import identity
    from app.db.session import SessionLocal

    users = {"admin": "admin@a.test", "manager": "manager@a.test", "operator": "operator@a.test", "viewer": "viewer@a.test",
             "scoped": "scoped@a.test", "manager_b": "manager@b.test"}
    admin_user = create_admin(users["admin"], PASSWORD, "Admin A")
    with SessionLocal() as s:
        admin = s.get(User, admin_user.id)
        a = _build_tenant(s, admin, "A", "Company A")
        b = _build_tenant(s, admin, "B", "Company B")
        ctx_a = admin_ctx(s, uuid.UUID(a.id), admin.id)
        for key, role in (("manager", "manager"), ("operator", "operator"), ("viewer", "viewer")):
            identity.add_member(s, ctx_a, identity.MemberIn(email=users[key], full_name=key.title(), role=role, password=PASSWORD))
        identity.add_member(s, ctx_a, identity.MemberIn(email=users["scoped"], full_name="Scoped", role="manager", password=PASSWORD,
                                                        department_ids=[a.departments["SEX-2"]]))
        s.commit()
        ctx_b = admin_ctx(s, uuid.UUID(b.id), admin.id)
        identity.add_member(s, ctx_b, identity.MemberIn(email=users["manager_b"], full_name="Manager B", role="manager", password=PASSWORD))
        s.commit()
    return World(a=a, b=b, users=users)


# --------------------------------------------------------------------------- helpers


def wait_job(client: TestClient, headers: dict[str, str], job_id: str) -> dict[str, Any]:
    """Jobs run in-process (eager Celery), so they are finished by the time the submit call returns."""
    r = client.get(f"/api/v1/agent/jobs/{job_id}", headers=headers)
    assert r.status_code == 200, r.text
    return r.json()


def records(client: TestClient, headers: dict[str, str], job_id: str, **params) -> dict[str, Any]:
    r = client.get(f"/api/v1/agent/jobs/{job_id}/records", headers=headers, params={"limit": 500, **params})
    assert r.status_code == 200, r.text
    return r.json()


def owner_query(owner_engine, sql: str, **params) -> list[Any]:
    with owner_engine.begin() as conn:
        return conn.execute(text(sql), params).all()
