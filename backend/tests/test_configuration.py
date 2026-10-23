"""Start-up configuration: production safety, provider endpoints, operational commands."""

from __future__ import annotations

import secrets

import pytest
from cryptography.fernet import Fernet
from pydantic import ValidationError

from tests.conftest import PASSWORD

PROD = {"app_env": "prod", "cookie_secure": True, "celery_always_eager": False, "ai_provider": "demo"}


def _settings(**values):
    from app.config import Settings

    return Settings(_env_file=None, **{**PROD, **values})


@pytest.mark.parametrize("jwt_key, field_key, problem", [
    ("change-me-at-least-32-characters-long-random", Fernet.generate_key().decode(), "JWT_SIGNING_KEY"),  # .env.example as shipped
    ("dev-only-insecure-jwt-signing-key-change-me", Fernet.generate_key().decode(), "JWT_SIGNING_KEY"),
    (secrets.token_urlsafe(48), "change-me", "FIELD_ENCRYPTION_KEY"),
    (secrets.token_urlsafe(48), "not-a-fernet-key-but-long-enough-0123456789", "FIELD_ENCRYPTION_KEY"),
    (secrets.token_urlsafe(48), None, "FIELD_ENCRYPTION_KEY"),
])
def test_production_refuses_placeholder_and_invalid_secrets(jwt_key, field_key, problem):
    with pytest.raises(ValidationError) as exc:
        _settings(jwt_signing_key=jwt_key, field_encryption_key=field_key)
    assert problem in str(exc.value)


def test_production_accepts_real_secrets_and_development_stays_lenient():
    s = _settings(jwt_signing_key=secrets.token_urlsafe(48), field_encryption_key=Fernet.generate_key().decode())
    assert s.app_env == "prod"
    with pytest.raises(ValidationError):
        _settings(jwt_signing_key="too-short", field_encryption_key=Fernet.generate_key().decode())
    dev = _settings(app_env="dev", cookie_secure=False, field_encryption_key="change-me")
    assert dev.jwt_signing_key.startswith("dev-only")


def test_production_refuses_the_example_database_passwords():
    keys = {"jwt_signing_key": secrets.token_urlsafe(48), "field_encryption_key": Fernet.generate_key().decode()}
    for url in ("database_url", "database_migration_url"):
        with pytest.raises(ValidationError, match="POSTGRES_PASSWORD and APP_DB_PASSWORD"):
            _settings(**keys, **{url: "postgresql+psycopg://biwork:change-me-owner@postgres:5432/biwork"})


def test_local_trial_with_the_example_encryption_key_can_encrypt(monkeypatch):
    """A dev install keeps FIELD_ENCRYPTION_KEY=change-me from .env.example; MFA enrolment must still work."""
    from app.config import get_settings
    from app.core.security import decrypt_field, encrypt_field

    monkeypatch.setenv("FIELD_ENCRYPTION_KEY", "change-me")
    get_settings.cache_clear()
    try:
        assert decrypt_field(encrypt_field("JBSWY3DPEHPK3PXP")) == "JBSWY3DPEHPK3PXP"
    finally:
        monkeypatch.undo()
        get_settings.cache_clear()


def test_empty_provider_base_urls_fall_back_to_the_public_endpoints(ai_config):
    from app.ai.providers.anthropic import AnthropicProvider
    from app.ai.providers.openai import OpenAIProvider
    from app.config import get_settings

    # docker compose passes `ANTHROPIC_BASE_URL=` from .env into the environment as an empty string.
    ai_config(ANTHROPIC_API_KEY="k", ANTHROPIC_BASE_URL="", OPENAI_API_KEY="k", OPENAI_MODEL_DEFAULT="m", OPENAI_BASE_URL="",
              GEMINI_BASE_URL="")
    assert get_settings().anthropic_base_url is None and get_settings().gemini_base_url is None
    assert str(AnthropicProvider().client.base_url).rstrip("/") == "https://api.anthropic.com"
    assert str(OpenAIProvider().client.base_url).rstrip("/") == "https://api.openai.com/v1"
    ai_config(ANTHROPIC_BASE_URL="https://claude.proxy.test")
    assert str(AnthropicProvider().client.base_url).rstrip("/") == "https://claude.proxy.test"


def test_seed_demo_rerun_changes_nothing(capsys):
    from app.cli import create_admin, seed_demo
    from app.core.models import User
    from app.core.security import verify_password
    from app.core.services import identity
    from app.db.session import SessionLocal

    admin = create_admin("admin@biwork.local", PASSWORD, "Admin")
    with SessionLocal() as s:  # an existing demo company, without the slow sample operations
        identity.create_company(s, s.get(User, admin.id), identity.CompanyIn(name="Demo", code="DEMO"), ["production"])
        s.commit()
    capsys.readouterr()
    seed_demo("Other-Pass-2026x")
    assert "nothing to do" in capsys.readouterr().out
    with SessionLocal() as s:
        assert verify_password(s.get(User, admin.id).password_hash, PASSWORD)
