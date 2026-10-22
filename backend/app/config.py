"""Application configuration (docs/architecture.md §10.3).

Everything comes from environment variables, validated at start-up: the process refuses to
start on invalid configuration. Secrets (JWT key, field-encryption key, provider API keys) are
never committed; see `.env.example`.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Annotated, Literal

from cryptography.fernet import Fernet
from pydantic import Field, field_validator, model_validator
from pydantic_settings import BaseSettings, NoDecode, SettingsConfigDict

_DEV_JWT_KEY = "dev-only-insecure-jwt-signing-key-change-me"


def _is_placeholder(secret: str) -> bool:
    """The development key and the "change-me" values published in .env.example are known to everyone."""
    return secret == _DEV_JWT_KEY or "change-me" in secret.lower()


def _is_fernet_key(key: str) -> bool:
    try:
        Fernet(key.encode())
    except (ValueError, TypeError):
        return False
    return True


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

    # AI layer (docs/ai-engine.md). Business logic never names a model: it asks the router for a
    # task, and the router picks a provider + model from the settings below and the routing table.
    # auto = use whichever providers have credentials (primary first); demo = labelled rule-based
    # simulation, used automatically when no provider is configured. "mock" is an alias of demo.
    ai_provider: Literal["auto", "openai", "gemini", "anthropic", "demo", "mock"] = "auto"
    ai_primary_provider: Literal["openai", "gemini", "anthropic"] = "openai"
    # Tried in this order after the selected provider (which is skipped here); comma-separated in the env.
    ai_fallback_providers: Annotated[list[str], NoDecode] = ["openai", "gemini", "anthropic"]
    ai_routing_enabled: bool = True
    ai_fallback_enabled: bool = True
    ai_review_required: bool = True
    ai_routing_file: Path | None = None  # optional JSON overriding app/ai/routing.default.json

    openai_api_key: str | None = None
    openai_base_url: str | None = None
    openai_organization: str | None = None
    openai_model_default: str | None = None  # routine extraction (structured output)
    openai_model_complex: str | None = None  # escalation for ambiguous / conflicting / low-confidence input
    openai_model_economy: str | None = None  # classification, column mapping (defaults to OPENAI_MODEL_DEFAULT)
    openai_model_vision: str | None = None  # images and scans (defaults to OPENAI_MODEL_DEFAULT)

    gemini_api_key: str | None = None
    gemini_base_url: str | None = None
    gemini_model_default: str | None = None
    gemini_model_complex: str | None = None
    gemini_model_economy: str | None = None
    gemini_model_vision: str | None = None

    anthropic_api_key: str | None = None
    anthropic_base_url: str | None = None
    anthropic_model_default: str | None = "claude-sonnet-5-5"
    anthropic_model_complex: str | None = "claude-opus-5-5"
    anthropic_model_economy: str | None = "claude-haiku-5-5"
    anthropic_model_vision: str | None = None
    ai_effort: Literal["low", "medium", "high", "xhigh", "max"] = "medium"
    ai_refusal_fallback: bool = True

    ai_timeout_s: float = 60.0
    ai_max_retries: int = 2  # in-call retries per provider for transient errors (then fallback / job retry)
    ai_retry_base_delay_s: float = 1.0
    ai_retry_max_delay_s: float = 20.0
    ai_max_calls_per_minute: int = 60
    ai_max_input_chars: int = 120_000
    ai_max_input_tokens: int = 40_000  # estimated (chars / 4); larger inputs must be split
    ai_max_output_tokens: int = 16_000
    ai_escalation_confidence: float = 0.6  # mean field confidence below this escalates to the complex tier
    ai_cache_ttl_s: int = 86_400  # company-scoped cache of classification / column-mapping results; 0 disables
    ai_health_failure_threshold: int = 3  # consecutive failures before a provider is marked down
    ai_health_cooldown_s: int = 120
    ai_pricing_json: str | None = None  # {"model-id": [input_usd_per_mtok, output_usd_per_mtok], ...}

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

    @field_validator("openai_base_url", "gemini_base_url", "anthropic_base_url", mode="before")
    @classmethod
    def _blank_is_unset(cls, value):
        # `ANTHROPIC_BASE_URL=` in .env means "use the provider's public endpoint", not an empty URL.
        return value.strip() or None if isinstance(value, str) else value

    @field_validator("ai_fallback_providers", mode="before")
    @classmethod
    def _split_providers(cls, value):
        if isinstance(value, str):
            return [p.strip().lower() for p in value.split(",") if p.strip()]
        return value

    def configured_ai_providers(self) -> list[str]:
        """Providers with credentials, primary first, then the fallback order."""
        keys = {"openai": self.openai_api_key, "gemini": self.gemini_api_key, "anthropic": self.anthropic_api_key}
        order = [self.ai_primary_provider, *self.ai_fallback_providers, "openai", "gemini", "anthropic"]
        return [p for p in dict.fromkeys(order) if keys.get(p)]

    def provider_model(self, provider: str, tier: str) -> str | None:
        """Model id for a provider and routing tier; economy and vision fall back to the default model."""
        value = getattr(self, f"{provider}_model_{tier}", None)
        if not value and tier in ("economy", "vision"):
            value = getattr(self, f"{provider}_model_default", None)
        return value or None

    @model_validator(mode="after")
    def _check_ai_settings(self) -> Settings:
        problems = []
        if self.ai_provider in ("openai", "gemini", "anthropic") and not getattr(self, f"{self.ai_provider}_api_key"):
            problems.append(f"{self.ai_provider.upper()}_API_KEY is required when AI_PROVIDER={self.ai_provider}")
        for provider in self.configured_ai_providers():
            if not self.provider_model(provider, "default"):
                problems.append(f"{provider.upper()}_MODEL_DEFAULT must be set when {provider.upper()}_API_KEY is set "
                                "(use a model id your account can access; `python -m app.cli ai-models` lists them)")
        unknown = set(self.ai_fallback_providers) - {"openai", "gemini", "anthropic"}
        if unknown:
            problems.append(f"AI_FALLBACK_PROVIDERS contains unknown provider(s): {', '.join(sorted(unknown))}")
        if not 0 <= self.ai_escalation_confidence <= 1:
            problems.append("AI_ESCALATION_CONFIDENCE must be between 0 and 1")
        if problems:
            raise ValueError(f"Invalid AI configuration: {'; '.join(problems)}")
        return self

    @model_validator(mode="after")
    def _check_production_safety(self) -> Settings:
        if self.app_env in ("staging", "prod"):
            problems = []
            if _is_placeholder(self.jwt_signing_key):
                problems.append("JWT_SIGNING_KEY must be set to a strong secret (not the example or development value)")
            if not self.cookie_secure:
                problems.append("COOKIE_SECURE must be true")
            if not self.field_encryption_key or _is_placeholder(self.field_encryption_key) or not _is_fernet_key(self.field_encryption_key):
                problems.append("FIELD_ENCRYPTION_KEY must be set to a Fernet key (Fernet.generate_key())")
            if self.celery_always_eager:
                problems.append("CELERY_ALWAYS_EAGER must be false")
            if self.ai_provider in ("auto",) and not self.configured_ai_providers():
                problems.append("no AI provider credentials are configured; set OPENAI_API_KEY (or GEMINI_API_KEY / "
                                "ANTHROPIC_API_KEY), or set AI_PROVIDER=demo to run the labelled demo mode on purpose")
            if problems:
                raise ValueError(f"Unsafe configuration for {self.app_env}: {'; '.join(problems)}")
        return self


@lru_cache
def get_settings() -> Settings:
    return Settings()
