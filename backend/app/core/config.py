from functools import lru_cache
from typing import Literal

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="BIWORK_", env_file=".env", extra="ignore", frozen=True)

    # "anthropic" calls the Claude API; "mock" returns clearly labeled empty
    # placeholders for local development without credentials.
    ai_provider: Literal["anthropic", "mock"] = "anthropic"
    ai_model: str = "claude-opus-5-5"
    ai_effort: Literal["low", "medium", "high", "xhigh", "max"] = "high"
    ai_max_output_tokens: int = 16000
    ai_timeout_seconds: float = 180.0
    ai_max_retries: int = 3

    intake_max_upload_bytes: int = 20 * 1024 * 1024
    intake_max_text_chars: int = 200_000


@lru_cache
def get_settings() -> Settings:
    return Settings()
