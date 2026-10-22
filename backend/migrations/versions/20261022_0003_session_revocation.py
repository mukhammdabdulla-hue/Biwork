"""session revocation, confirmed MFA re-enrolment, per-caller idempotency keys

- core.users.sessions_revoked_at: access tokens issued before it are rejected (password change).
- core.users.mfa_pending_secret: a new authenticator secret waits here until it is confirmed, so
  re-running MFA setup never switches off the second factor. Unconfirmed secrets move here.
- core.idempotency_keys.actor_id: keys are scoped to the user or API client that sent them.
  Stored keys only live two days and cannot be attributed, so they are dropped.
- Foreign keys from core.employees / core.business_documents to the Agent record that created them:
  declared in 0002 with use_alter, which create_table skips.

Revision ID: 0003
Revises: 0002
Create Date: 2026-10-22 18:00:00.000000

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

# revision identifiers, used by Alembic.
revision: str = '0003'
down_revision: Union[str, Sequence[str], None] = '0002'
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    """Upgrade schema."""
    op.add_column('users', sa.Column('mfa_pending_secret', sa.LargeBinary(), nullable=True), schema='core')
    op.add_column('users', sa.Column('sessions_revoked_at', sa.DateTime(timezone=True), nullable=True), schema='core')
    op.execute("""
        UPDATE core.users SET mfa_pending_secret = mfa_secret, mfa_secret = NULL
        WHERE NOT mfa_enabled AND mfa_secret IS NOT NULL
    """)

    op.execute("DELETE FROM core.idempotency_keys")
    op.add_column('idempotency_keys', sa.Column('actor_id', sa.UUID(), nullable=False), schema='core')
    op.drop_constraint(op.f('uq_idempotency_keys_company_id_key'), 'idempotency_keys', schema='core', type_='unique')
    op.create_unique_constraint(op.f('uq_idempotency_keys_company_id_actor_id_key'), 'idempotency_keys',
                                ['company_id', 'actor_id', 'key'], schema='core')

    for table in ('employees', 'business_documents'):
        op.create_foreign_key(op.f(f'fk_{table}_source_record_id_extracted_records'), table, 'extracted_records', ['source_record_id'], ['id'],
                              source_schema='core', referent_schema='agent', ondelete='SET NULL')


def downgrade() -> None:
    """Downgrade schema."""
    for table in ('employees', 'business_documents'):
        op.drop_constraint(op.f(f'fk_{table}_source_record_id_extracted_records'), table, schema='core', type_='foreignkey')
    op.execute("DELETE FROM core.idempotency_keys")
    op.drop_constraint(op.f('uq_idempotency_keys_company_id_actor_id_key'), 'idempotency_keys', schema='core', type_='unique')
    op.create_unique_constraint(op.f('uq_idempotency_keys_company_id_key'), 'idempotency_keys', ['company_id', 'key'], schema='core')
    op.drop_column('idempotency_keys', 'actor_id', schema='core')

    op.execute("""
        UPDATE core.users SET mfa_secret = mfa_pending_secret
        WHERE NOT mfa_enabled AND mfa_pending_secret IS NOT NULL
    """)
    op.drop_column('users', 'sessions_revoked_at', schema='core')
    op.drop_column('users', 'mfa_pending_secret', schema='core')
