"""initial schema

Revision ID: 0001
Revises: 
Create Date: 2026-10-21 10:14:19.591414

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

# revision identifiers, used by Alembic.
revision: str = '0001'
down_revision: Union[str, Sequence[str], None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


SCHEMAS = ("core", "agent", "production", "inventory", "supply")

# Tenant tables protected by row-level security (company_id = app.company_id). Identity tables
# (companies, users, roles, memberships, api_clients, company_modules) are resolved before a
# company context exists and are guarded by Core services instead.
TENANT_TABLES = (
    "agent.ai_calls",
    "agent.approvals",
    "agent.column_mappings",
    "agent.committed_writes",
    "agent.extracted_fields",
    "agent.extracted_records",
    "agent.extraction_feedback",
    "agent.ingestion_items",
    "agent.ingestion_jobs",
    "agent.mapping_templates",
    "agent.match_candidates",
    "agent.parsed_segments",
    "agent.source_refs",
    "agent.stage_runs",
    "agent.validation_results",
    "core.department_aliases",
    "core.departments",
    "core.files",
    "core.idempotency_keys",
    "core.notifications",
    "core.number_sequences",
    "core.product_aliases",
    "core.product_categories",
    "core.products",
    "core.tasks",
    "core.workflow_definitions",
    "inventory.stock_balances",
    "inventory.stock_movements",
    "inventory.warehouses",
    "production.production_lines",
    "production.production_orders",
    "production.production_reports",
    "production.shifts",
    "supply.purchase_request_lines",
    "supply.purchase_requests",
    "supply.supplier_aliases",
    "supply.suppliers",
)

UNITS = [
    # code, name, dimension, factor_to_base, is_base, aliases
    ("pcs", "Piece", "count", "1", True, ["pc", "piece", "pieces", "ea", "each", "unit", "units", "dona", "ta", "дона", "шт", "штук", "штука", "штуки", "ед", "единица"]),
    ("kg", "Kilogram", "mass", "1", True, ["кг", "kilogram", "kilogramm", "kilo", "kgs", "килограмм"]),
    ("g", "Gram", "mass", "0.001", False, ["г", "gr", "gram", "grams", "gramm", "грамм"]),
    ("t", "Tonne", "mass", "1000", False, ["т", "tn", "ton", "tonne", "tonna", "тонна", "тонн"]),
    ("l", "Litre", "volume", "1", True, ["л", "litr", "litre", "liter", "ltr", "литр"]),
    ("ml", "Millilitre", "volume", "0.001", False, ["мл", "millilitr", "миллилитр"]),
    ("m3", "Cubic metre", "volume", "1000", False, ["м3", "м³", "m³", "kub", "куб", "cbm", "kub metr", "куб м"]),
    ("m", "Metre", "length", "1", True, ["м", "metr", "metre", "meter", "метр"]),
    ("cm", "Centimetre", "length", "0.01", False, ["см", "santimetr", "сантиметр"]),
    ("mm", "Millimetre", "length", "0.001", False, ["мм", "millimetr", "миллиметр"]),
    ("m2", "Square metre", "area", "1", True, ["м2", "м²", "m²", "kv m", "кв м", "sq m"]),
    ("box", "Box", "box", "1", True, ["quti", "коробка", "коробок", "кор", "boxes"]),
    ("pack", "Pack", "pack", "1", True, ["pachka", "упаковка", "упак", "уп", "package", "packs"]),
    ("set", "Set", "set", "1", True, ["komplekt", "комплект", "компл", "sets"]),
    ("pair", "Pair", "pair", "1", True, ["juft", "пара", "пар", "pairs"]),
    ("roll", "Roll", "roll", "1", True, ["rulon", "рулон", "rolls"]),
    ("bag", "Bag", "bag", "1", True, ["qop", "meshok", "мешок", "мешка", "мешков", "bags", "sack"]),
    ("h", "Hour", "time", "1", True, ["soat", "час", "hour", "hours", "hr", "ч"]),
]


def _seed_units() -> None:
    import uuid

    units = sa.table("units", sa.column("id", sa.Uuid), sa.column("code", sa.String), sa.column("name", sa.String),
                     sa.column("dimension", sa.String), sa.column("factor_to_base", sa.Numeric), sa.column("is_base", sa.Boolean),
                     schema="core")
    aliases = sa.table("unit_aliases", sa.column("id", sa.Uuid), sa.column("unit_id", sa.Uuid), sa.column("alias", sa.String),
                       schema="core")
    unit_rows, alias_rows = [], []
    for code, name, dimension, factor, is_base, names in UNITS:
        uid = uuid.uuid4()
        unit_rows.append({"id": uid, "code": code, "name": name, "dimension": dimension, "factor_to_base": factor, "is_base": is_base})
        alias_rows += [{"id": uuid.uuid4(), "unit_id": uid, "alias": a} for a in dict.fromkeys(names)]
    op.bulk_insert(units, unit_rows)
    op.bulk_insert(aliases, alias_rows)


def _enable_row_level_security() -> None:
    current = "NULLIF(current_setting('app.company_id', true), '')::uuid"
    for table in TENANT_TABLES:
        op.execute(f"ALTER TABLE {table} ENABLE ROW LEVEL SECURITY")
        op.execute(f"CREATE POLICY tenant_isolation ON {table} USING (company_id = {current}) WITH CHECK (company_id = {current})")
    # Audit: readable within the company; platform events (company_id NULL) may be appended but not read.
    op.execute("ALTER TABLE core.audit_events ENABLE ROW LEVEL SECURITY")
    op.execute(f"CREATE POLICY audit_read ON core.audit_events FOR SELECT USING (company_id = {current})")
    op.execute(f"CREATE POLICY audit_append ON core.audit_events FOR INSERT WITH CHECK (company_id IS NULL OR company_id = {current})")


def _grant_application_role() -> None:
    from app.config import get_settings

    role = get_settings().database_app_role
    schemas = ", ".join(SCHEMAS)
    statements = [
        f"GRANT USAGE ON SCHEMA {schemas} TO {role}",
        f"GRANT USAGE ON SCHEMA public TO {role}",  # pg_trgm functions/operators live in public
        *[f"GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {s} TO {role}" for s in SCHEMAS],
        *[f"GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {s} TO {role}" for s in SCHEMAS],
        *[f"ALTER DEFAULT PRIVILEGES IN SCHEMA {s} GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {role}" for s in SCHEMAS],
        *[f"ALTER DEFAULT PRIVILEGES IN SCHEMA {s} GRANT USAGE, SELECT ON SEQUENCES TO {role}" for s in SCHEMAS],
        # Append-only tables (§4.6, §4.4): no UPDATE/DELETE for the application.
        f"REVOKE UPDATE, DELETE, TRUNCATE ON core.audit_events FROM {role}",
        f"REVOKE UPDATE, DELETE, TRUNCATE ON inventory.stock_movements FROM {role}",
        f"REVOKE INSERT, UPDATE, DELETE ON core.units, core.unit_aliases FROM {role}",
    ]
    body = "; ".join(statements)
    op.execute(f"DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{role}') THEN {body}; END IF; END $$")


def _create_functions() -> None:
    from app.config import get_settings

    role = get_settings().database_app_role
    # The watchdog must see stuck stages across companies; RLS would hide them. This narrowly
    # scoped SECURITY DEFINER function returns only ids, nothing else.
    op.execute("""
        CREATE FUNCTION agent.stale_stage_runs(timeout_seconds integer)
        RETURNS TABLE(company_id uuid, job_id uuid, stage varchar)
        LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, agent AS $fn$
            SELECT DISTINCT company_id, job_id, stage FROM agent.stage_runs
            WHERE status = 'running' AND started_at < now() - make_interval(secs => timeout_seconds)
        $fn$
    """)
    op.execute("REVOKE ALL ON FUNCTION agent.stale_stage_runs(integer) FROM PUBLIC")
    op.execute(f"DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{role}') THEN "
               f"GRANT EXECUTE ON FUNCTION agent.stale_stage_runs(integer) TO {role}; END IF; END $$")


def upgrade() -> None:
    """Upgrade schema."""
    # Extensions and one schema per module (docs/architecture.md §4.1).
    op.execute("CREATE EXTENSION IF NOT EXISTS pg_trgm")
    for schema in SCHEMAS:
        op.execute(f"CREATE SCHEMA IF NOT EXISTS {schema}")
    # ### commands auto generated by Alembic - please adjust! ###
    op.create_table('audit_chain_heads',
    sa.Column('chain_key', sa.String(length=64), nullable=False),
    sa.Column('last_event_id', sa.BigInteger(), nullable=False),
    sa.Column('last_hash', sa.String(length=64), nullable=False),
    sa.PrimaryKeyConstraint('chain_key', name=op.f('pk_audit_chain_heads')),
    schema='core'
    )
    op.create_table('organizations',
    sa.Column('name', sa.String(length=200), nullable=False),
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_organizations')),
    sa.UniqueConstraint('code', name=op.f('uq_organizations_code')),
    schema='core'
    )
    op.create_table('units',
    sa.Column('code', sa.String(length=16), nullable=False),
    sa.Column('name', sa.String(length=60), nullable=False),
    sa.Column('dimension', sa.String(length=20), nullable=False),
    sa.Column('factor_to_base', sa.Numeric(precision=18, scale=4), nullable=False),
    sa.Column('is_base', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_units')),
    sa.UniqueConstraint('code', name=op.f('uq_units_code')),
    schema='core'
    )
    op.create_table('users',
    sa.Column('email', sa.String(length=320), nullable=False),
    sa.Column('full_name', sa.String(length=200), nullable=False),
    sa.Column('password_hash', sa.String(length=255), nullable=False),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('is_platform_admin', sa.Boolean(), nullable=False),
    sa.Column('mfa_enabled', sa.Boolean(), nullable=False),
    sa.Column('mfa_secret', sa.LargeBinary(), nullable=True),
    sa.Column('failed_login_count', sa.Integer(), nullable=False),
    sa.Column('locked_until', sa.DateTime(timezone=True), nullable=True),
    sa.Column('last_login_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('locale', sa.String(length=8), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_users')),
    schema='core'
    )
    op.create_index('uq_users_email_lower', 'users', [sa.literal_column('lower(email)')], unique=True, schema='core')
    op.create_table('companies',
    sa.Column('organization_id', sa.UUID(), nullable=False),
    sa.Column('name', sa.String(length=200), nullable=False),
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('tin', sa.String(length=32), nullable=True),
    sa.Column('settings', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('is_sample_data', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['organization_id'], ['core.organizations.id'], name=op.f('fk_companies_organization_id_organizations')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_companies')),
    sa.UniqueConstraint('organization_id', 'code', name=op.f('uq_companies_organization_id_code')),
    schema='core'
    )
    op.create_index(op.f('ix_core_companies_organization_id'), 'companies', ['organization_id'], unique=False, schema='core')
    op.create_table('refresh_tokens',
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('family_id', sa.UUID(), nullable=False),
    sa.Column('token_hash', sa.String(length=64), nullable=False),
    sa.Column('issued_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('expires_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('used_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('revoked_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('ip_address', sa.String(length=64), nullable=True),
    sa.Column('user_agent', sa.String(length=300), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['user_id'], ['core.users.id'], name=op.f('fk_refresh_tokens_user_id_users'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_refresh_tokens')),
    sa.UniqueConstraint('token_hash', name=op.f('uq_refresh_tokens_token_hash')),
    schema='core'
    )
    op.create_index(op.f('ix_core_refresh_tokens_family_id'), 'refresh_tokens', ['family_id'], unique=False, schema='core')
    op.create_index(op.f('ix_core_refresh_tokens_user_id'), 'refresh_tokens', ['user_id'], unique=False, schema='core')
    op.create_table('unit_aliases',
    sa.Column('unit_id', sa.UUID(), nullable=False),
    sa.Column('alias', sa.String(length=40), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['unit_id'], ['core.units.id'], name=op.f('fk_unit_aliases_unit_id_units'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_unit_aliases')),
    sa.UniqueConstraint('alias', name=op.f('uq_unit_aliases_alias')),
    schema='core'
    )
    op.create_index(op.f('ix_core_unit_aliases_unit_id'), 'unit_aliases', ['unit_id'], unique=False, schema='core')
    op.create_table('mapping_templates',
    sa.Column('record_type', sa.String(length=50), nullable=False),
    sa.Column('header_signature', sa.String(length=64), nullable=False),
    sa.Column('headers', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('mapping', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_mapping_templates_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_mapping_templates_created_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_mapping_templates')),
    sa.UniqueConstraint('company_id', 'record_type', 'header_signature', name=op.f('uq_mapping_templates_company_id_record_type_header_signature')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_mapping_templates_company_id'), 'mapping_templates', ['company_id'], unique=False, schema='agent')
    op.create_table('api_clients',
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('name', sa.String(length=100), nullable=False),
    sa.Column('key_prefix', sa.String(length=16), nullable=False),
    sa.Column('key_hash', sa.String(length=64), nullable=False),
    sa.Column('scopes', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('last_used_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('expires_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('revoked_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_api_clients_company_id_companies'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_api_clients_created_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_api_clients')),
    sa.UniqueConstraint('key_hash', name=op.f('uq_api_clients_key_hash')),
    schema='core'
    )
    op.create_index(op.f('ix_core_api_clients_company_id'), 'api_clients', ['company_id'], unique=False, schema='core')
    op.create_table('audit_events',
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('occurred_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=True),
    sa.Column('actor_type', sa.String(length=20), nullable=False),
    sa.Column('actor_id', sa.UUID(), nullable=True),
    sa.Column('action', sa.String(length=80), nullable=False),
    sa.Column('entity_type', sa.String(length=60), nullable=True),
    sa.Column('entity_id', sa.String(length=64), nullable=True),
    sa.Column('summary', sa.Text(), nullable=True),
    sa.Column('changes', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('context', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('prev_hash', sa.String(length=64), nullable=True),
    sa.Column('hash', sa.String(length=64), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_audit_events_company_id_companies')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_audit_events')),
    schema='core'
    )
    op.create_index('ix_audit_company_occurred', 'audit_events', ['company_id', 'occurred_at'], unique=False, schema='core')
    op.create_index('ix_audit_entity', 'audit_events', ['entity_type', 'entity_id'], unique=False, schema='core')
    op.create_index(op.f('ix_core_audit_events_action'), 'audit_events', ['action'], unique=False, schema='core')
    op.create_table('company_modules',
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('module_code', sa.String(length=40), nullable=False),
    sa.Column('enabled', sa.Boolean(), nullable=False),
    sa.Column('config', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_company_modules_company_id_companies'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('company_id', 'module_code', name=op.f('pk_company_modules')),
    schema='core'
    )
    op.create_table('departments',
    sa.Column('parent_id', sa.UUID(), nullable=True),
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('name', sa.String(length=200), nullable=False),
    sa.Column('kind', sa.String(length=30), nullable=False),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_departments_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['parent_id'], ['core.departments.id'], name=op.f('fk_departments_parent_id_departments')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_departments')),
    sa.UniqueConstraint('company_id', 'code', name=op.f('uq_departments_company_id_code')),
    schema='core'
    )
    op.create_index(op.f('ix_core_departments_company_id'), 'departments', ['company_id'], unique=False, schema='core')
    op.create_table('files',
    sa.Column('storage_backend', sa.String(length=10), nullable=False),
    sa.Column('storage_key', sa.String(length=300), nullable=False),
    sa.Column('sha256', sa.String(length=64), nullable=False),
    sa.Column('size', sa.BigInteger(), nullable=False),
    sa.Column('mime_type', sa.String(length=120), nullable=False),
    sa.Column('detected_format', sa.String(length=10), nullable=False),
    sa.Column('original_name', sa.String(length=300), nullable=False),
    sa.Column('scan_status', sa.String(length=10), nullable=False),
    sa.Column('uploaded_by', sa.UUID(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('deleted_at', sa.DateTime(timezone=True), nullable=True),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_files_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['uploaded_by'], ['core.users.id'], name=op.f('fk_files_uploaded_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_files')),
    schema='core'
    )
    op.create_index(op.f('ix_core_files_company_id'), 'files', ['company_id'], unique=False, schema='core')
    op.create_index('ix_files_company_sha', 'files', ['company_id', 'sha256'], unique=False, schema='core')
    op.create_table('idempotency_keys',
    sa.Column('key', sa.String(length=120), nullable=False),
    sa.Column('request_hash', sa.String(length=64), nullable=False),
    sa.Column('status_code', sa.Integer(), nullable=True),
    sa.Column('response', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('expires_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_idempotency_keys_company_id_companies'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_idempotency_keys')),
    sa.UniqueConstraint('company_id', 'key', name=op.f('uq_idempotency_keys_company_id_key')),
    schema='core'
    )
    op.create_index(op.f('ix_core_idempotency_keys_company_id'), 'idempotency_keys', ['company_id'], unique=False, schema='core')
    op.create_table('notifications',
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('kind', sa.String(length=50), nullable=False),
    sa.Column('payload', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('read_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_notifications_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['user_id'], ['core.users.id'], name=op.f('fk_notifications_user_id_users'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_notifications')),
    schema='core'
    )
    op.create_index(op.f('ix_core_notifications_company_id'), 'notifications', ['company_id'], unique=False, schema='core')
    op.create_index('ix_notifications_user_unread', 'notifications', ['user_id', 'read_at'], unique=False, schema='core')
    op.create_table('number_sequences',
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('key', sa.String(length=20), nullable=False),
    sa.Column('period', sa.String(length=10), nullable=False),
    sa.Column('last_value', sa.BigInteger(), nullable=False),
    sa.CheckConstraint('last_value >= 0', name=op.f('ck_number_sequences_nonneg')),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_number_sequences_company_id_companies'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('company_id', 'key', 'period', name=op.f('pk_number_sequences')),
    schema='core'
    )
    op.create_table('product_categories',
    sa.Column('parent_id', sa.UUID(), nullable=True),
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('name', sa.String(length=200), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_product_categories_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['parent_id'], ['core.product_categories.id'], name=op.f('fk_product_categories_parent_id_product_categories')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_product_categories')),
    sa.UniqueConstraint('company_id', 'code', name=op.f('uq_product_categories_company_id_code')),
    schema='core'
    )
    op.create_index(op.f('ix_core_product_categories_company_id'), 'product_categories', ['company_id'], unique=False, schema='core')
    op.create_table('roles',
    sa.Column('company_id', sa.UUID(), nullable=True),
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('name', sa.String(length=100), nullable=False),
    sa.Column('description', sa.Text(), nullable=True),
    sa.Column('is_system', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_roles_company_id_companies'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_roles')),
    schema='core'
    )
    op.create_index('uq_roles_company_code', 'roles', ['company_id', 'code'], unique=True, schema='core', postgresql_nulls_not_distinct=True)
    op.create_table('workflow_definitions',
    sa.Column('record_type', sa.String(length=50), nullable=False),
    sa.Column('steps', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('auto_approve', sa.Boolean(), nullable=False),
    sa.Column('confidence_threshold', sa.Numeric(precision=18, scale=4), nullable=True),
    sa.Column('updated_by', sa.UUID(), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_workflow_definitions_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['updated_by'], ['core.users.id'], name=op.f('fk_workflow_definitions_updated_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_workflow_definitions')),
    sa.UniqueConstraint('company_id', 'record_type', name=op.f('uq_workflow_definitions_company_id_record_type')),
    schema='core'
    )
    op.create_index(op.f('ix_core_workflow_definitions_company_id'), 'workflow_definitions', ['company_id'], unique=False, schema='core')
    op.create_table('shifts',
    sa.Column('code', sa.String(length=20), nullable=False),
    sa.Column('name', sa.String(length=100), nullable=False),
    sa.Column('starts_at', sa.Time(), nullable=True),
    sa.Column('ends_at', sa.Time(), nullable=True),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_shifts_company_id_companies'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_shifts')),
    sa.UniqueConstraint('company_id', 'code', name=op.f('uq_shifts_company_id_code')),
    schema='production'
    )
    op.create_index(op.f('ix_production_shifts_company_id'), 'shifts', ['company_id'], unique=False, schema='production')
    op.create_table('suppliers',
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('name', sa.String(length=250), nullable=False),
    sa.Column('tin', sa.String(length=32), nullable=True),
    sa.Column('contact_person', sa.String(length=200), nullable=True),
    sa.Column('phone', sa.String(length=50), nullable=True),
    sa.Column('email', sa.String(length=200), nullable=True),
    sa.Column('address', sa.Text(), nullable=True),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('updated_by', sa.UUID(), nullable=True),
    sa.Column('source_record_id', sa.UUID(), nullable=True),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_suppliers_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_suppliers_created_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['updated_by'], ['core.users.id'], name=op.f('fk_suppliers_updated_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_suppliers')),
    sa.UniqueConstraint('company_id', 'code', name=op.f('uq_suppliers_company_id_code')),
    schema='supply'
    )
    op.create_index(op.f('ix_supply_suppliers_company_id'), 'suppliers', ['company_id'], unique=False, schema='supply')
    op.create_index('uq_suppliers_company_tin', 'suppliers', ['company_id', 'tin'], unique=True, schema='supply', postgresql_where=sa.text('tin IS NOT NULL'))
    op.create_table('ingestion_jobs',
    sa.Column('submitted_by', sa.UUID(), nullable=True),
    sa.Column('api_client_id', sa.UUID(), nullable=True),
    sa.Column('channel', sa.String(length=20), nullable=False),
    sa.Column('source_kind', sa.String(length=20), nullable=False),
    sa.Column('title', sa.String(length=300), nullable=True),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('current_stage', sa.String(length=20), nullable=True),
    sa.Column('import_policy', sa.String(length=24), nullable=False),
    sa.Column('target_record_type', sa.String(length=50), nullable=True),
    sa.Column('idempotency_key', sa.String(length=120), nullable=True),
    sa.Column('content_fingerprint', sa.String(length=64), nullable=False),
    sa.Column('force_reprocess', sa.Boolean(), nullable=False),
    sa.Column('duplicate_of_job_id', sa.UUID(), nullable=True),
    sa.Column('pipeline_version', sa.String(length=80), nullable=False),
    sa.Column('ai_provider', sa.String(length=30), nullable=True),
    sa.Column('ai_model', sa.String(length=60), nullable=True),
    sa.Column('ai_is_mock', sa.Boolean(), nullable=False),
    sa.Column('prompt_version', sa.String(length=60), nullable=True),
    sa.Column('stats', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('warnings', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('error', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('attempt', sa.Integer(), nullable=False),
    sa.Column('started_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('finished_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['api_client_id'], ['core.api_clients.id'], name=op.f('fk_ingestion_jobs_api_client_id_api_clients'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_ingestion_jobs_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['duplicate_of_job_id'], ['agent.ingestion_jobs.id'], name=op.f('fk_ingestion_jobs_duplicate_of_job_id_ingestion_jobs')),
    sa.ForeignKeyConstraint(['submitted_by'], ['core.users.id'], name=op.f('fk_ingestion_jobs_submitted_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_ingestion_jobs')),
    sa.UniqueConstraint('company_id', 'idempotency_key', name=op.f('uq_ingestion_jobs_company_id_idempotency_key')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_ingestion_jobs_company_id'), 'ingestion_jobs', ['company_id'], unique=False, schema='agent')
    op.create_index(op.f('ix_agent_ingestion_jobs_status'), 'ingestion_jobs', ['status'], unique=False, schema='agent')
    op.create_index('ix_ingestion_jobs_company_created', 'ingestion_jobs', ['company_id', 'created_at'], unique=False, schema='agent')
    op.create_index('uq_ingestion_jobs_fingerprint_live', 'ingestion_jobs', ['company_id', 'content_fingerprint'], unique=True, schema='agent', postgresql_where=sa.text("status NOT IN ('failed', 'cancelled') AND NOT force_reprocess"))
    op.create_table('department_aliases',
    sa.Column('department_id', sa.UUID(), nullable=False),
    sa.Column('alias', sa.String(length=200), nullable=False),
    sa.Column('normalized_alias', sa.String(length=200), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_department_aliases_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_department_aliases_department_id_departments'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_department_aliases')),
    sa.UniqueConstraint('company_id', 'normalized_alias', 'department_id', name=op.f('uq_department_aliases_company_id_normalized_alias_department_id')),
    schema='core'
    )
    op.create_index(op.f('ix_core_department_aliases_company_id'), 'department_aliases', ['company_id'], unique=False, schema='core')
    op.create_index('ix_department_aliases_trgm', 'department_aliases', ['normalized_alias'], unique=False, schema='core', postgresql_using='gin', postgresql_ops={'normalized_alias': 'gin_trgm_ops'})
    op.create_table('memberships',
    sa.Column('user_id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('role_id', sa.UUID(), nullable=False),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_memberships_company_id_companies'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['role_id'], ['core.roles.id'], name=op.f('fk_memberships_role_id_roles')),
    sa.ForeignKeyConstraint(['user_id'], ['core.users.id'], name=op.f('fk_memberships_user_id_users'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_memberships')),
    sa.UniqueConstraint('user_id', 'company_id', 'role_id', name=op.f('uq_memberships_user_id_company_id_role_id')),
    schema='core'
    )
    op.create_index(op.f('ix_core_memberships_company_id'), 'memberships', ['company_id'], unique=False, schema='core')
    op.create_index(op.f('ix_core_memberships_user_id'), 'memberships', ['user_id'], unique=False, schema='core')
    op.create_table('products',
    sa.Column('code', sa.String(length=64), nullable=False),
    sa.Column('name', sa.String(length=250), nullable=False),
    sa.Column('category_id', sa.UUID(), nullable=True),
    sa.Column('base_unit_id', sa.UUID(), nullable=False),
    sa.Column('product_type', sa.String(length=30), nullable=False),
    sa.Column('reorder_level', sa.Numeric(precision=18, scale=4), nullable=True),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('updated_by', sa.UUID(), nullable=True),
    sa.Column('version', sa.Integer(), nullable=False),
    sa.Column('source_record_id', sa.UUID(), nullable=True),
    sa.Column('deleted_at', sa.DateTime(timezone=True), nullable=True),
    sa.ForeignKeyConstraint(['base_unit_id'], ['core.units.id'], name=op.f('fk_products_base_unit_id_units')),
    sa.ForeignKeyConstraint(['category_id'], ['core.product_categories.id'], name=op.f('fk_products_category_id_product_categories')),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_products_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_products_created_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['updated_by'], ['core.users.id'], name=op.f('fk_products_updated_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_products')),
    sa.UniqueConstraint('company_id', 'code', name=op.f('uq_products_company_id_code')),
    schema='core'
    )
    op.create_index(op.f('ix_core_products_company_id'), 'products', ['company_id'], unique=False, schema='core')
    op.create_table('role_permissions',
    sa.Column('role_id', sa.UUID(), nullable=False),
    sa.Column('permission', sa.String(length=80), nullable=False),
    sa.ForeignKeyConstraint(['role_id'], ['core.roles.id'], name=op.f('fk_role_permissions_role_id_roles'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('role_id', 'permission', name=op.f('pk_role_permissions')),
    schema='core'
    )
    op.create_table('tasks',
    sa.Column('number', sa.String(length=30), nullable=False),
    sa.Column('title', sa.String(length=300), nullable=False),
    sa.Column('description', sa.Text(), nullable=True),
    sa.Column('assignee_id', sa.UUID(), nullable=True),
    sa.Column('department_id', sa.UUID(), nullable=True),
    sa.Column('due_date', sa.Date(), nullable=True),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('priority', sa.String(length=10), nullable=False),
    sa.Column('ref_type', sa.String(length=60), nullable=True),
    sa.Column('ref_id', sa.UUID(), nullable=True),
    sa.Column('completed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('updated_by', sa.UUID(), nullable=True),
    sa.Column('version', sa.Integer(), nullable=False),
    sa.Column('source_record_id', sa.UUID(), nullable=True),
    sa.ForeignKeyConstraint(['assignee_id'], ['core.users.id'], name=op.f('fk_tasks_assignee_id_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_tasks_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_tasks_created_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_tasks_department_id_departments')),
    sa.ForeignKeyConstraint(['updated_by'], ['core.users.id'], name=op.f('fk_tasks_updated_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_tasks')),
    sa.UniqueConstraint('company_id', 'number', name=op.f('uq_tasks_company_id_number')),
    schema='core'
    )
    op.create_index(op.f('ix_core_tasks_company_id'), 'tasks', ['company_id'], unique=False, schema='core')
    op.create_index('ix_tasks_company_status_due', 'tasks', ['company_id', 'status', 'due_date'], unique=False, schema='core')
    op.create_table('warehouses',
    sa.Column('department_id', sa.UUID(), nullable=True),
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('name', sa.String(length=200), nullable=False),
    sa.Column('kind', sa.String(length=20), nullable=False),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_warehouses_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_warehouses_department_id_departments')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_warehouses')),
    sa.UniqueConstraint('company_id', 'code', name=op.f('uq_warehouses_company_id_code')),
    schema='inventory'
    )
    op.create_index(op.f('ix_inventory_warehouses_company_id'), 'warehouses', ['company_id'], unique=False, schema='inventory')
    op.create_table('production_lines',
    sa.Column('department_id', sa.UUID(), nullable=False),
    sa.Column('code', sa.String(length=50), nullable=False),
    sa.Column('name', sa.String(length=200), nullable=False),
    sa.Column('capacity_per_shift', sa.Numeric(precision=18, scale=4), nullable=True),
    sa.Column('unit_id', sa.UUID(), nullable=True),
    sa.Column('is_active', sa.Boolean(), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_production_lines_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_production_lines_department_id_departments')),
    sa.ForeignKeyConstraint(['unit_id'], ['core.units.id'], name=op.f('fk_production_lines_unit_id_units')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_production_lines')),
    sa.UniqueConstraint('company_id', 'code', name=op.f('uq_production_lines_company_id_code')),
    schema='production'
    )
    op.create_index(op.f('ix_production_production_lines_company_id'), 'production_lines', ['company_id'], unique=False, schema='production')
    op.create_table('purchase_requests',
    sa.Column('number', sa.String(length=40), nullable=False),
    sa.Column('external_number', sa.String(length=60), nullable=True),
    sa.Column('request_date', sa.Date(), nullable=False),
    sa.Column('needed_by', sa.Date(), nullable=True),
    sa.Column('department_id', sa.UUID(), nullable=True),
    sa.Column('requested_by_employee', sa.String(length=200), nullable=True),
    sa.Column('supplier_id', sa.UUID(), nullable=True),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('priority', sa.String(length=10), nullable=False),
    sa.Column('currency', sa.String(length=3), nullable=True),
    sa.Column('total_estimated', sa.Numeric(precision=18, scale=4), nullable=True),
    sa.Column('notes', sa.Text(), nullable=True),
    sa.Column('source_file_id', sa.UUID(), nullable=True),
    sa.Column('decided_by', sa.UUID(), nullable=True),
    sa.Column('decided_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('decision_comment', sa.Text(), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('updated_by', sa.UUID(), nullable=True),
    sa.Column('version', sa.Integer(), nullable=False),
    sa.Column('source_record_id', sa.UUID(), nullable=True),
    sa.CheckConstraint('needed_by IS NULL OR needed_by >= request_date', name=op.f('ck_purchase_requests_needed_by_after_request')),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_purchase_requests_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_purchase_requests_created_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['decided_by'], ['core.users.id'], name=op.f('fk_purchase_requests_decided_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_purchase_requests_department_id_departments')),
    sa.ForeignKeyConstraint(['source_file_id'], ['core.files.id'], name=op.f('fk_purchase_requests_source_file_id_files')),
    sa.ForeignKeyConstraint(['supplier_id'], ['supply.suppliers.id'], name=op.f('fk_purchase_requests_supplier_id_suppliers')),
    sa.ForeignKeyConstraint(['updated_by'], ['core.users.id'], name=op.f('fk_purchase_requests_updated_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_purchase_requests')),
    sa.UniqueConstraint('company_id', 'number', name=op.f('uq_purchase_requests_company_id_number')),
    schema='supply'
    )
    op.create_index('ix_purchase_requests_company_status', 'purchase_requests', ['company_id', 'status'], unique=False, schema='supply')
    op.create_index(op.f('ix_supply_purchase_requests_company_id'), 'purchase_requests', ['company_id'], unique=False, schema='supply')
    op.create_table('supplier_aliases',
    sa.Column('supplier_id', sa.UUID(), nullable=False),
    sa.Column('alias', sa.String(length=250), nullable=False),
    sa.Column('normalized_alias', sa.String(length=250), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_supplier_aliases_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['supplier_id'], ['supply.suppliers.id'], name=op.f('fk_supplier_aliases_supplier_id_suppliers'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_supplier_aliases')),
    sa.UniqueConstraint('company_id', 'normalized_alias', 'supplier_id', name=op.f('uq_supplier_aliases_company_id_normalized_alias_supplier_id')),
    schema='supply'
    )
    op.create_index('ix_supplier_aliases_trgm', 'supplier_aliases', ['normalized_alias'], unique=False, schema='supply', postgresql_using='gin', postgresql_ops={'normalized_alias': 'gin_trgm_ops'})
    op.create_index(op.f('ix_supply_supplier_aliases_company_id'), 'supplier_aliases', ['company_id'], unique=False, schema='supply')
    op.create_index(op.f('ix_supply_supplier_aliases_supplier_id'), 'supplier_aliases', ['supplier_id'], unique=False, schema='supply')
    op.create_table('ingestion_items',
    sa.Column('job_id', sa.UUID(), nullable=False),
    sa.Column('position', sa.Integer(), nullable=False),
    sa.Column('file_id', sa.UUID(), nullable=True),
    sa.Column('raw_text', sa.Text(), nullable=True),
    sa.Column('raw_payload', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('detected_format', sa.String(length=10), nullable=False),
    sa.Column('sha256', sa.String(length=64), nullable=False),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('error', sa.Text(), nullable=True),
    sa.Column('parser', sa.String(length=60), nullable=True),
    sa.Column('ocr_used', sa.Boolean(), nullable=False),
    sa.Column('ocr_confidence', sa.Numeric(precision=5, scale=2), nullable=True),
    sa.Column('page_count', sa.Integer(), nullable=True),
    sa.Column('meta', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('warnings', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('detected_record_type', sa.String(length=50), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_ingestion_items_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['file_id'], ['core.files.id'], name=op.f('fk_ingestion_items_file_id_files')),
    sa.ForeignKeyConstraint(['job_id'], ['agent.ingestion_jobs.id'], name=op.f('fk_ingestion_items_job_id_ingestion_jobs'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_ingestion_items')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_ingestion_items_company_id'), 'ingestion_items', ['company_id'], unique=False, schema='agent')
    op.create_index(op.f('ix_agent_ingestion_items_job_id'), 'ingestion_items', ['job_id'], unique=False, schema='agent')
    op.create_table('membership_departments',
    sa.Column('membership_id', sa.UUID(), nullable=False),
    sa.Column('department_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_membership_departments_department_id_departments'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['membership_id'], ['core.memberships.id'], name=op.f('fk_membership_departments_membership_id_memberships'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('membership_id', 'department_id', name=op.f('pk_membership_departments')),
    schema='core'
    )
    op.create_table('product_aliases',
    sa.Column('product_id', sa.UUID(), nullable=False),
    sa.Column('alias', sa.String(length=250), nullable=False),
    sa.Column('normalized_alias', sa.String(length=250), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_product_aliases_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['product_id'], ['core.products.id'], name=op.f('fk_product_aliases_product_id_products'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_product_aliases')),
    sa.UniqueConstraint('company_id', 'normalized_alias', 'product_id', name=op.f('uq_product_aliases_company_id_normalized_alias_product_id')),
    schema='core'
    )
    op.create_index(op.f('ix_core_product_aliases_company_id'), 'product_aliases', ['company_id'], unique=False, schema='core')
    op.create_index(op.f('ix_core_product_aliases_product_id'), 'product_aliases', ['product_id'], unique=False, schema='core')
    op.create_index('ix_product_aliases_trgm', 'product_aliases', ['normalized_alias'], unique=False, schema='core', postgresql_using='gin', postgresql_ops={'normalized_alias': 'gin_trgm_ops'})
    op.create_table('stock_balances',
    sa.Column('warehouse_id', sa.UUID(), nullable=False),
    sa.Column('product_id', sa.UUID(), nullable=False),
    sa.Column('batch_code', sa.String(length=64), nullable=True),
    sa.Column('qty', sa.Numeric(precision=18, scale=4), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_stock_balances_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['product_id'], ['core.products.id'], name=op.f('fk_stock_balances_product_id_products')),
    sa.ForeignKeyConstraint(['warehouse_id'], ['inventory.warehouses.id'], name=op.f('fk_stock_balances_warehouse_id_warehouses')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_stock_balances')),
    schema='inventory'
    )
    op.create_index(op.f('ix_inventory_stock_balances_company_id'), 'stock_balances', ['company_id'], unique=False, schema='inventory')
    op.create_index('uq_stock_balances_key', 'stock_balances', ['company_id', 'warehouse_id', 'product_id', 'batch_code'], unique=True, schema='inventory', postgresql_nulls_not_distinct=True)
    op.create_table('stock_movements',
    sa.Column('number', sa.String(length=40), nullable=False),
    sa.Column('movement_date', sa.Date(), nullable=False),
    sa.Column('movement_type', sa.String(length=30), nullable=False),
    sa.Column('direction', sa.SmallInteger(), nullable=False),
    sa.Column('warehouse_id', sa.UUID(), nullable=False),
    sa.Column('product_id', sa.UUID(), nullable=False),
    sa.Column('qty', sa.Numeric(precision=18, scale=4), nullable=False),
    sa.Column('unit_id', sa.UUID(), nullable=False),
    sa.Column('base_qty', sa.Numeric(precision=18, scale=4), nullable=False),
    sa.Column('batch_code', sa.String(length=64), nullable=True),
    sa.Column('reference', sa.String(length=100), nullable=True),
    sa.Column('ref_type', sa.String(length=60), nullable=True),
    sa.Column('ref_id', sa.UUID(), nullable=True),
    sa.Column('reverses_id', sa.UUID(), nullable=True),
    sa.Column('notes', sa.Text(), nullable=True),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('source_record_id', sa.UUID(), nullable=True),
    sa.CheckConstraint('direction IN (-1, 1)', name=op.f('ck_stock_movements_direction')),
    sa.CheckConstraint('qty <> 0', name=op.f('ck_stock_movements_qty_nonzero')),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_stock_movements_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_stock_movements_created_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['product_id'], ['core.products.id'], name=op.f('fk_stock_movements_product_id_products')),
    sa.ForeignKeyConstraint(['reverses_id'], ['inventory.stock_movements.id'], name=op.f('fk_stock_movements_reverses_id_stock_movements')),
    sa.ForeignKeyConstraint(['unit_id'], ['core.units.id'], name=op.f('fk_stock_movements_unit_id_units')),
    sa.ForeignKeyConstraint(['warehouse_id'], ['inventory.warehouses.id'], name=op.f('fk_stock_movements_warehouse_id_warehouses')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_stock_movements')),
    sa.UniqueConstraint('company_id', 'number', name=op.f('uq_stock_movements_company_id_number')),
    schema='inventory'
    )
    op.create_index(op.f('ix_inventory_stock_movements_company_id'), 'stock_movements', ['company_id'], unique=False, schema='inventory')
    op.create_index('ix_stock_movements_company_wh_product', 'stock_movements', ['company_id', 'warehouse_id', 'product_id'], unique=False, schema='inventory')
    op.create_index('ix_stock_movements_ref', 'stock_movements', ['ref_type', 'ref_id'], unique=False, schema='inventory')
    op.create_table('production_orders',
    sa.Column('number', sa.String(length=40), nullable=False),
    sa.Column('external_number', sa.String(length=60), nullable=True),
    sa.Column('product_id', sa.UUID(), nullable=False),
    sa.Column('department_id', sa.UUID(), nullable=True),
    sa.Column('line_id', sa.UUID(), nullable=True),
    sa.Column('planned_qty', sa.Numeric(precision=18, scale=4), nullable=False),
    sa.Column('unit_id', sa.UUID(), nullable=False),
    sa.Column('planned_start', sa.Date(), nullable=True),
    sa.Column('planned_end', sa.Date(), nullable=True),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('priority', sa.String(length=10), nullable=False),
    sa.Column('notes', sa.Text(), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('updated_by', sa.UUID(), nullable=True),
    sa.Column('version', sa.Integer(), nullable=False),
    sa.Column('source_record_id', sa.UUID(), nullable=True),
    sa.CheckConstraint('planned_end IS NULL OR planned_start IS NULL OR planned_end >= planned_start', name=op.f('ck_production_orders_dates')),
    sa.CheckConstraint('planned_qty > 0', name=op.f('ck_production_orders_planned_qty_positive')),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_production_orders_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_production_orders_created_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_production_orders_department_id_departments')),
    sa.ForeignKeyConstraint(['line_id'], ['production.production_lines.id'], name=op.f('fk_production_orders_line_id_production_lines')),
    sa.ForeignKeyConstraint(['product_id'], ['core.products.id'], name=op.f('fk_production_orders_product_id_products')),
    sa.ForeignKeyConstraint(['unit_id'], ['core.units.id'], name=op.f('fk_production_orders_unit_id_units')),
    sa.ForeignKeyConstraint(['updated_by'], ['core.users.id'], name=op.f('fk_production_orders_updated_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_production_orders')),
    sa.UniqueConstraint('company_id', 'number', name=op.f('uq_production_orders_company_id_number')),
    schema='production'
    )
    op.create_index('ix_production_orders_company_status', 'production_orders', ['company_id', 'status'], unique=False, schema='production')
    op.create_index(op.f('ix_production_production_orders_company_id'), 'production_orders', ['company_id'], unique=False, schema='production')
    op.create_index(op.f('ix_production_production_orders_product_id'), 'production_orders', ['product_id'], unique=False, schema='production')
    op.create_table('purchase_request_lines',
    sa.Column('request_id', sa.UUID(), nullable=False),
    sa.Column('line_no', sa.Integer(), nullable=False),
    sa.Column('product_id', sa.UUID(), nullable=True),
    sa.Column('description', sa.String(length=300), nullable=False),
    sa.Column('qty', sa.Numeric(precision=18, scale=4), nullable=False),
    sa.Column('unit_id', sa.UUID(), nullable=False),
    sa.Column('est_price', sa.Numeric(precision=18, scale=4), nullable=True),
    sa.Column('currency', sa.String(length=3), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.CheckConstraint('est_price IS NULL OR est_price >= 0', name=op.f('ck_purchase_request_lines_price_nonneg')),
    sa.CheckConstraint('qty > 0', name=op.f('ck_purchase_request_lines_qty_positive')),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_purchase_request_lines_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['product_id'], ['core.products.id'], name=op.f('fk_purchase_request_lines_product_id_products')),
    sa.ForeignKeyConstraint(['request_id'], ['supply.purchase_requests.id'], name=op.f('fk_purchase_request_lines_request_id_purchase_requests'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['unit_id'], ['core.units.id'], name=op.f('fk_purchase_request_lines_unit_id_units')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_purchase_request_lines')),
    sa.UniqueConstraint('request_id', 'line_no', name=op.f('uq_purchase_request_lines_request_id_line_no')),
    schema='supply'
    )
    op.create_index(op.f('ix_supply_purchase_request_lines_company_id'), 'purchase_request_lines', ['company_id'], unique=False, schema='supply')
    op.create_index(op.f('ix_supply_purchase_request_lines_request_id'), 'purchase_request_lines', ['request_id'], unique=False, schema='supply')
    op.create_table('ai_calls',
    sa.Column('job_id', sa.UUID(), nullable=True),
    sa.Column('item_id', sa.UUID(), nullable=True),
    sa.Column('stage', sa.String(length=20), nullable=False),
    sa.Column('task', sa.String(length=40), nullable=False),
    sa.Column('provider', sa.String(length=30), nullable=False),
    sa.Column('model', sa.String(length=60), nullable=False),
    sa.Column('is_mock', sa.Boolean(), nullable=False),
    sa.Column('prompt_id', sa.String(length=60), nullable=False),
    sa.Column('prompt_version', sa.String(length=20), nullable=False),
    sa.Column('schema_version', sa.String(length=20), nullable=True),
    sa.Column('input_tokens', sa.Integer(), nullable=True),
    sa.Column('output_tokens', sa.Integer(), nullable=True),
    sa.Column('cost_usd', sa.Numeric(precision=12, scale=6), nullable=True),
    sa.Column('latency_ms', sa.Integer(), nullable=True),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('error', sa.Text(), nullable=True),
    sa.Column('provider_request_id', sa.String(length=100), nullable=True),
    sa.Column('data_categories', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('request_redacted', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('response', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_ai_calls_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['item_id'], ['agent.ingestion_items.id'], name=op.f('fk_ai_calls_item_id_ingestion_items'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['job_id'], ['agent.ingestion_jobs.id'], name=op.f('fk_ai_calls_job_id_ingestion_jobs'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_ai_calls')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_ai_calls_company_id'), 'ai_calls', ['company_id'], unique=False, schema='agent')
    op.create_index('ix_ai_calls_company_created', 'ai_calls', ['company_id', 'created_at'], unique=False, schema='agent')
    op.create_table('column_mappings',
    sa.Column('item_id', sa.UUID(), nullable=False),
    sa.Column('table_seq', sa.Integer(), nullable=False),
    sa.Column('record_type', sa.String(length=50), nullable=False),
    sa.Column('source_index', sa.Integer(), nullable=False),
    sa.Column('source_column', sa.String(length=200), nullable=False),
    sa.Column('target_field', sa.String(length=80), nullable=True),
    sa.Column('confidence', sa.Numeric(precision=4, scale=3), nullable=False),
    sa.Column('mapped_by', sa.String(length=10), nullable=False),
    sa.Column('confirmed_by', sa.UUID(), nullable=True),
    sa.Column('confirmed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_column_mappings_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['confirmed_by'], ['core.users.id'], name=op.f('fk_column_mappings_confirmed_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['item_id'], ['agent.ingestion_items.id'], name=op.f('fk_column_mappings_item_id_ingestion_items'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_column_mappings')),
    sa.UniqueConstraint('item_id', 'table_seq', 'source_index', name=op.f('uq_column_mappings_item_id_table_seq_source_index')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_column_mappings_company_id'), 'column_mappings', ['company_id'], unique=False, schema='agent')
    op.create_index(op.f('ix_agent_column_mappings_item_id'), 'column_mappings', ['item_id'], unique=False, schema='agent')
    op.create_table('extracted_records',
    sa.Column('job_id', sa.UUID(), nullable=False),
    sa.Column('item_id', sa.UUID(), nullable=False),
    sa.Column('seq', sa.Integer(), nullable=False),
    sa.Column('record_type', sa.String(length=50), nullable=False),
    sa.Column('target_module', sa.String(length=30), nullable=False),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('review_group', sa.String(length=20), nullable=True),
    sa.Column('requires_review', sa.Boolean(), nullable=False),
    sa.Column('payload', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('payload_version', sa.Integer(), nullable=False),
    sa.Column('references', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('row_ref', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('overall_confidence', sa.Numeric(precision=4, scale=3), nullable=True),
    sa.Column('fingerprint', sa.String(length=64), nullable=True),
    sa.Column('allow_duplicate', sa.Boolean(), nullable=False),
    sa.Column('summary', sa.String(length=300), nullable=True),
    sa.Column('error', sa.Text(), nullable=True),
    sa.Column('reviewed_by', sa.UUID(), nullable=True),
    sa.Column('reviewed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_extracted_records_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['item_id'], ['agent.ingestion_items.id'], name=op.f('fk_extracted_records_item_id_ingestion_items'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['job_id'], ['agent.ingestion_jobs.id'], name=op.f('fk_extracted_records_job_id_ingestion_jobs'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['reviewed_by'], ['core.users.id'], name=op.f('fk_extracted_records_reviewed_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_extracted_records')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_extracted_records_company_id'), 'extracted_records', ['company_id'], unique=False, schema='agent')
    op.create_index('ix_extracted_records_fingerprint', 'extracted_records', ['company_id', 'record_type', 'fingerprint'], unique=False, schema='agent')
    op.create_index('ix_extracted_records_job_status', 'extracted_records', ['job_id', 'status'], unique=False, schema='agent')
    op.create_table('parsed_segments',
    sa.Column('item_id', sa.UUID(), nullable=False),
    sa.Column('seq', sa.Integer(), nullable=False),
    sa.Column('kind', sa.String(length=20), nullable=False),
    sa.Column('page', sa.Integer(), nullable=True),
    sa.Column('sheet', sa.String(length=120), nullable=True),
    sa.Column('row_index', sa.Integer(), nullable=True),
    sa.Column('table_seq', sa.Integer(), nullable=True),
    sa.Column('content', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('ocr_confidence', sa.Numeric(precision=5, scale=2), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_parsed_segments_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['item_id'], ['agent.ingestion_items.id'], name=op.f('fk_parsed_segments_item_id_ingestion_items'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_parsed_segments')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_parsed_segments_company_id'), 'parsed_segments', ['company_id'], unique=False, schema='agent')
    op.create_index('ix_parsed_segments_item_seq', 'parsed_segments', ['item_id', 'seq'], unique=False, schema='agent')
    op.create_table('stage_runs',
    sa.Column('job_id', sa.UUID(), nullable=False),
    sa.Column('item_id', sa.UUID(), nullable=True),
    sa.Column('stage', sa.String(length=20), nullable=False),
    sa.Column('attempt', sa.Integer(), nullable=False),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('started_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('finished_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('duration_ms', sa.Integer(), nullable=True),
    sa.Column('error', sa.Text(), nullable=True),
    sa.Column('metrics', postgresql.JSONB(astext_type=sa.Text()), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_stage_runs_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['item_id'], ['agent.ingestion_items.id'], name=op.f('fk_stage_runs_item_id_ingestion_items'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['job_id'], ['agent.ingestion_jobs.id'], name=op.f('fk_stage_runs_job_id_ingestion_jobs'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_stage_runs')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_stage_runs_company_id'), 'stage_runs', ['company_id'], unique=False, schema='agent')
    op.create_index('ix_stage_runs_job', 'stage_runs', ['job_id', 'started_at'], unique=False, schema='agent')
    op.create_table('production_reports',
    sa.Column('number', sa.String(length=40), nullable=False),
    sa.Column('production_date', sa.Date(), nullable=False),
    sa.Column('product_id', sa.UUID(), nullable=False),
    sa.Column('department_id', sa.UUID(), nullable=False),
    sa.Column('line_id', sa.UUID(), nullable=True),
    sa.Column('shift_id', sa.UUID(), nullable=True),
    sa.Column('order_id', sa.UUID(), nullable=True),
    sa.Column('qty_produced', sa.Numeric(precision=18, scale=4), nullable=False),
    sa.Column('qty_accepted', sa.Numeric(precision=18, scale=4), nullable=True),
    sa.Column('qty_defective', sa.Numeric(precision=18, scale=4), nullable=True),
    sa.Column('unit_id', sa.UUID(), nullable=False),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('supersedes_id', sa.UUID(), nullable=True),
    sa.Column('notes', sa.Text(), nullable=True),
    sa.Column('approved_by', sa.UUID(), nullable=True),
    sa.Column('approved_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.Column('created_by', sa.UUID(), nullable=True),
    sa.Column('updated_by', sa.UUID(), nullable=True),
    sa.Column('version', sa.Integer(), nullable=False),
    sa.Column('source_record_id', sa.UUID(), nullable=True),
    sa.CheckConstraint('coalesce(qty_accepted, 0) + coalesce(qty_defective, 0) <= qty_produced', name=op.f('ck_production_reports_qty_reconcile')),
    sa.CheckConstraint('qty_produced >= 0 AND coalesce(qty_accepted, 0) >= 0 AND coalesce(qty_defective, 0) >= 0', name=op.f('ck_production_reports_qty_nonneg')),
    sa.ForeignKeyConstraint(['approved_by'], ['core.users.id'], name=op.f('fk_production_reports_approved_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_production_reports_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['created_by'], ['core.users.id'], name=op.f('fk_production_reports_created_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['department_id'], ['core.departments.id'], name=op.f('fk_production_reports_department_id_departments')),
    sa.ForeignKeyConstraint(['line_id'], ['production.production_lines.id'], name=op.f('fk_production_reports_line_id_production_lines')),
    sa.ForeignKeyConstraint(['order_id'], ['production.production_orders.id'], name=op.f('fk_production_reports_order_id_production_orders')),
    sa.ForeignKeyConstraint(['product_id'], ['core.products.id'], name=op.f('fk_production_reports_product_id_products')),
    sa.ForeignKeyConstraint(['shift_id'], ['production.shifts.id'], name=op.f('fk_production_reports_shift_id_shifts')),
    sa.ForeignKeyConstraint(['supersedes_id'], ['production.production_reports.id'], name=op.f('fk_production_reports_supersedes_id_production_reports')),
    sa.ForeignKeyConstraint(['unit_id'], ['core.units.id'], name=op.f('fk_production_reports_unit_id_units')),
    sa.ForeignKeyConstraint(['updated_by'], ['core.users.id'], name=op.f('fk_production_reports_updated_by_users'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_production_reports')),
    sa.UniqueConstraint('company_id', 'number', name=op.f('uq_production_reports_company_id_number')),
    schema='production'
    )
    op.create_index(op.f('ix_production_production_reports_company_id'), 'production_reports', ['company_id'], unique=False, schema='production')
    op.create_index(op.f('ix_production_production_reports_order_id'), 'production_reports', ['order_id'], unique=False, schema='production')
    op.create_index(op.f('ix_production_production_reports_product_id'), 'production_reports', ['product_id'], unique=False, schema='production')
    op.create_index('ix_production_reports_company_date', 'production_reports', ['company_id', 'production_date'], unique=False, schema='production')
    op.create_index('uq_production_reports_natural_key', 'production_reports', ['company_id', 'production_date', 'product_id', 'department_id', 'shift_id'], unique=True, schema='production', postgresql_nulls_not_distinct=True, postgresql_where=sa.text("status <> 'cancelled'"))
    op.create_table('approvals',
    sa.Column('record_id', sa.UUID(), nullable=False),
    sa.Column('decided_by', sa.UUID(), nullable=True),
    sa.Column('decision', sa.String(length=10), nullable=False),
    sa.Column('auto', sa.Boolean(), nullable=False),
    sa.Column('comment', sa.Text(), nullable=True),
    sa.Column('diff', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('payload_version', sa.Integer(), nullable=False),
    sa.Column('decided_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_approvals_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['decided_by'], ['core.users.id'], name=op.f('fk_approvals_decided_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['record_id'], ['agent.extracted_records.id'], name=op.f('fk_approvals_record_id_extracted_records'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_approvals')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_approvals_company_id'), 'approvals', ['company_id'], unique=False, schema='agent')
    op.create_index(op.f('ix_agent_approvals_record_id'), 'approvals', ['record_id'], unique=False, schema='agent')
    op.create_table('committed_writes',
    sa.Column('record_id', sa.UUID(), nullable=False),
    sa.Column('target_table', sa.String(length=80), nullable=False),
    sa.Column('target_id', sa.UUID(), nullable=False),
    sa.Column('target_number', sa.String(length=40), nullable=True),
    sa.Column('operation', sa.String(length=10), nullable=False),
    sa.Column('idempotency_key', sa.String(length=64), nullable=False),
    sa.Column('committed_by', sa.UUID(), nullable=True),
    sa.Column('committed_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['committed_by'], ['core.users.id'], name=op.f('fk_committed_writes_committed_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_committed_writes_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['record_id'], ['agent.extracted_records.id'], name=op.f('fk_committed_writes_record_id_extracted_records'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_committed_writes')),
    sa.UniqueConstraint('idempotency_key', name=op.f('uq_committed_writes_idempotency_key')),
    sa.UniqueConstraint('record_id', name=op.f('uq_committed_writes_record_id')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_committed_writes_company_id'), 'committed_writes', ['company_id'], unique=False, schema='agent')
    op.create_table('extracted_fields',
    sa.Column('record_id', sa.UUID(), nullable=False),
    sa.Column('position', sa.Integer(), nullable=False),
    sa.Column('field', sa.String(length=120), nullable=False),
    sa.Column('raw_value', sa.Text(), nullable=True),
    sa.Column('model_value', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('normalized_value', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('confidence', sa.Numeric(precision=4, scale=3), nullable=True),
    sa.Column('state', sa.String(length=20), nullable=False),
    sa.Column('state_reason', sa.String(length=300), nullable=True),
    sa.Column('extracted_by', sa.String(length=20), nullable=False),
    sa.Column('edited_by', sa.UUID(), nullable=True),
    sa.Column('edited_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_extracted_fields_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['edited_by'], ['core.users.id'], name=op.f('fk_extracted_fields_edited_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['record_id'], ['agent.extracted_records.id'], name=op.f('fk_extracted_fields_record_id_extracted_records'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_extracted_fields')),
    sa.UniqueConstraint('record_id', 'field', name=op.f('uq_extracted_fields_record_id_field')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_extracted_fields_company_id'), 'extracted_fields', ['company_id'], unique=False, schema='agent')
    op.create_table('extraction_feedback',
    sa.Column('record_id', sa.UUID(), nullable=False),
    sa.Column('record_type', sa.String(length=50), nullable=False),
    sa.Column('field', sa.String(length=120), nullable=False),
    sa.Column('model_value', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('corrected_value', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('extracted_by', sa.String(length=20), nullable=False),
    sa.Column('prompt_version', sa.String(length=60), nullable=True),
    sa.Column('corrected_by', sa.UUID(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_extraction_feedback_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['corrected_by'], ['core.users.id'], name=op.f('fk_extraction_feedback_corrected_by_users'), ondelete='SET NULL'),
    sa.ForeignKeyConstraint(['record_id'], ['agent.extracted_records.id'], name=op.f('fk_extraction_feedback_record_id_extracted_records'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_extraction_feedback')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_extraction_feedback_company_id'), 'extraction_feedback', ['company_id'], unique=False, schema='agent')
    op.create_table('match_candidates',
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('record_id', sa.UUID(), nullable=False),
    sa.Column('field', sa.String(length=120), nullable=True),
    sa.Column('entity_type', sa.String(length=60), nullable=False),
    sa.Column('entity_id', sa.UUID(), nullable=False),
    sa.Column('label', sa.String(length=300), nullable=False),
    sa.Column('score', sa.Numeric(precision=4, scale=3), nullable=False),
    sa.Column('match_kind', sa.String(length=20), nullable=False),
    sa.Column('selected', sa.Boolean(), nullable=False),
    sa.Column('details', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_match_candidates_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['record_id'], ['agent.extracted_records.id'], name=op.f('fk_match_candidates_record_id_extracted_records'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_match_candidates')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_match_candidates_company_id'), 'match_candidates', ['company_id'], unique=False, schema='agent')
    op.create_index(op.f('ix_agent_match_candidates_record_id'), 'match_candidates', ['record_id'], unique=False, schema='agent')
    op.create_table('validation_results',
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('record_id', sa.UUID(), nullable=False),
    sa.Column('field', sa.String(length=120), nullable=True),
    sa.Column('rule_code', sa.String(length=60), nullable=False),
    sa.Column('severity', sa.String(length=10), nullable=False),
    sa.Column('message', sa.Text(), nullable=False),
    sa.Column('details', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('stage', sa.String(length=20), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_validation_results_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['record_id'], ['agent.extracted_records.id'], name=op.f('fk_validation_results_record_id_extracted_records'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_validation_results')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_validation_results_company_id'), 'validation_results', ['company_id'], unique=False, schema='agent')
    op.create_index(op.f('ix_agent_validation_results_record_id'), 'validation_results', ['record_id'], unique=False, schema='agent')
    op.create_table('source_refs',
    sa.Column('field_id', sa.UUID(), nullable=False),
    sa.Column('item_id', sa.UUID(), nullable=False),
    sa.Column('file_id', sa.UUID(), nullable=True),
    sa.Column('page', sa.Integer(), nullable=True),
    sa.Column('sheet', sa.String(length=120), nullable=True),
    sa.Column('cell', sa.String(length=20), nullable=True),
    sa.Column('bbox', postgresql.JSONB(astext_type=sa.Text()), nullable=True),
    sa.Column('text_start', sa.Integer(), nullable=True),
    sa.Column('text_end', sa.Integer(), nullable=True),
    sa.Column('excerpt', sa.Text(), nullable=True),
    sa.Column('id', sa.UUID(), nullable=False),
    sa.Column('company_id', sa.UUID(), nullable=False),
    sa.ForeignKeyConstraint(['company_id'], ['core.companies.id'], name=op.f('fk_source_refs_company_id_companies'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['field_id'], ['agent.extracted_fields.id'], name=op.f('fk_source_refs_field_id_extracted_fields'), ondelete='CASCADE'),
    sa.ForeignKeyConstraint(['file_id'], ['core.files.id'], name=op.f('fk_source_refs_file_id_files')),
    sa.ForeignKeyConstraint(['item_id'], ['agent.ingestion_items.id'], name=op.f('fk_source_refs_item_id_ingestion_items'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_source_refs')),
    schema='agent'
    )
    op.create_index(op.f('ix_agent_source_refs_company_id'), 'source_refs', ['company_id'], unique=False, schema='agent')
    op.create_index(op.f('ix_agent_source_refs_field_id'), 'source_refs', ['field_id'], unique=False, schema='agent')
    # ### end Alembic commands ###
    # Provenance links from business rows to agent.extracted_records (circular, so added last).
    op.create_foreign_key(op.f('fk_suppliers_source_record_id_extracted_records'), 'suppliers', 'extracted_records', ['source_record_id'], ['id'], source_schema='supply', referent_schema='agent', ondelete='SET NULL')
    op.create_foreign_key(op.f('fk_products_source_record_id_extracted_records'), 'products', 'extracted_records', ['source_record_id'], ['id'], source_schema='core', referent_schema='agent', ondelete='SET NULL')
    op.create_foreign_key(op.f('fk_tasks_source_record_id_extracted_records'), 'tasks', 'extracted_records', ['source_record_id'], ['id'], source_schema='core', referent_schema='agent', ondelete='SET NULL')
    op.create_foreign_key(op.f('fk_purchase_requests_source_record_id_extracted_records'), 'purchase_requests', 'extracted_records', ['source_record_id'], ['id'], source_schema='supply', referent_schema='agent', ondelete='SET NULL')
    op.create_foreign_key(op.f('fk_stock_movements_source_record_id_extracted_records'), 'stock_movements', 'extracted_records', ['source_record_id'], ['id'], source_schema='inventory', referent_schema='agent', ondelete='SET NULL')
    op.create_foreign_key(op.f('fk_production_orders_source_record_id_extracted_records'), 'production_orders', 'extracted_records', ['source_record_id'], ['id'], source_schema='production', referent_schema='agent', ondelete='SET NULL')
    op.create_foreign_key(op.f('fk_production_reports_source_record_id_extracted_records'), 'production_reports', 'extracted_records', ['source_record_id'], ['id'], source_schema='production', referent_schema='agent', ondelete='SET NULL')

    _seed_units()
    _enable_row_level_security()
    _grant_application_role()
    _create_functions()



def downgrade() -> None:
    """Downgrade schema."""
    for schema in reversed(SCHEMAS):
        op.execute(f"DROP SCHEMA IF EXISTS {schema} CASCADE")
