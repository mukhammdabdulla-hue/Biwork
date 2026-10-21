"""Application configuration (docs/architecture.md §10.3).

Everything comes from environment variables, validated at start-up: the process refuses to
start on invalid configuration. Secrets (JWT key, field-encryption key, provider API keys) are
never committed; see `.env.example`.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

_DEV_JWT_KEY = "dev-only-insecure-jwt-signing-key-change-me"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_env: Literal["dev", "test", "staging", "prod"] = "dev"
    app_name: str = "BIWORK"
    log_level: str = "INFO"
    log_json: bool = False

    # Database: the API and workers connect as a non-owner role subject to row-level security;
    # migrations run as the schema owner.
    database_url: str = "postgresql+psycopg://biwork_app:biwork_app@localhost:5432/biwork"
    database_migration_url: str = "postgresql+psycopg://biwork:biwork@localhost:5432/biwork"
    database_app_role: str = "biwork_app"
    db_pool_size: int = 10

    redis_url: str | None = "redis://localhost:6379/0"

    # Auth
    jwt_signing_key: str = Field(default=_DEV_JWT_KEY, min_length=32)
    jwt_access_ttl_minutes: int = 15
    refresh_ttl_days: int = 14
    refresh_cookie_name: str = "biwork_refresh"
    cookie_secure: bool = False
    field_encryption_key: str | None = None  # Fernet key for MFA secrets etc.
    login_max_failures: int = 5
    login_lockout_minutes: int = 15
    password_min_length: int = 10
    cors_origins: list[str] = ["http://localhost:5173", "http://127.0.0.1:5173"]
    rate_limit_per_minute: int = 300
    auth_rate_limit_per_minute: int = 20

    # Files
    storage_backend: Literal["local", "s3"] = "local"
    storage_dir: Path = Path("./var/storage")
    s3_endpoint: str | None = None
    s3_bucket: str = "biwork-files"
    s3_access_key: str | None = None
    s3_secret_key: str | None = None
    s3_region: str = "us-east-1"
    max_upload_mb: int = 50
    max_files_per_job: int = 20
    clamav_host: str | None = None
    clamav_port: int = 3310

    # Background work (Celery + Redis). `celery_always_eager` runs tasks in-process, for tests
    # and for single-process local development without a worker.
    celery_broker_url: str | None = None
    celery_always_eager: bool = False
    stage_timeout_seconds: int = 900

    # AI layer
    ai_provider: Literal["mock", "anthropic"] = "mock"
    anthropic_api_key: str | None = None
    anthropic_base_url: str | None = None
    ai_model_extract: str = "claude-opus-5-5"
    ai_model_classify: str = "claude-haiku-5-5"
    ai_effort: Literal["low", "medium", "high", "xhigh", "max"] = "medium"
    ai_timeout_s: float = 60.0
    ai_max_retries: int = 3
    ai_refusal_fallback: bool = True
    ai_max_calls_per_minute: int = 60
    ai_max_input_chars: int = 120_000

    # OCR
    ocr_enabled: bool = True
    ocr_langs: str = "eng+rus+uzb+uzb_cyrl"
    tesseract_cmd: str | None = None

    # Agent
    agent_commit_batch_size: int = 500
    agent_confidence_threshold: float = 0.8

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024

    @property
    def broker_url(self) -> str:
        return self.celery_broker_url or self.redis_url or "memory://"

    @model_validator(mode="after")
    def _check_production_safety(self) -> Settings:
        if self.app_env in ("staging", "prod"):
            problems = []
            if self.jwt_signing_key == _DEV_JWT_KEY:
                problems.append("JWT_SIGNING_KEY must be set to a strong secret")
            if not self.cookie_secure:
                problems.append("COOKIE_SECURE must be true")
            if not self.field_encryption_key:
                problems.append("FIELD_ENCRYPTION_KEY must be set")
            if self.celery_always_eager:
                problems.append("CELERY_ALWAYS_EAGER must be false")
            if self.ai_provider == "anthropic" and not self.anthropic_api_key:
                problems.append("ANTHROPIC_API_KEY is required when AI_PROVIDER=anthropic")
            if problems:
                raise ValueError(f"Unsafe configuration for {self.app_env}: {'; '.join(problems)}")
        return self


@lru_cache
def get_settings() -> Settings:
    return Settings()
