"""Provider-independent LLM interface (docs/architecture.md §7.2).

Business logic never names a model or a vendor: it asks for a *task* (classify / extract /
map_columns) with untrusted content blocks and a Pydantic output schema, and gets back a parsed,
validated object plus usage metadata.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Literal, Protocol

from pydantic import BaseModel


class AIError(Exception):
    """Base class for AI layer failures."""


class TransientAIError(AIError):
    """Timeouts, rate limits, overload, 5xx: the stage is retried with backoff."""

    def __init__(self, message: str, retry_after: float | None = None):
        super().__init__(message)
        self.retry_after = retry_after


class PermanentAIError(AIError):
    """Bad request, authentication, refusal, or repeated invalid output: the item fails visibly."""


@dataclass(frozen=True)
class TextBlock:
    text: str
    label: str = "document"
    type: Literal["text"] = "text"


@dataclass(frozen=True)
class ImageBlock:
    media_type: str
    data_b64: str
    label: str = "image"
    type: Literal["image"] = "image"


@dataclass(frozen=True)
class PdfBlock:
    data_b64: str
    label: str = "pdf"
    type: Literal["pdf"] = "pdf"


ContentBlock = TextBlock | ImageBlock | PdfBlock


@dataclass(frozen=True)
class PromptTask:
    prompt_id: str  # classify | extract | map_columns
    version: str
    system: str
    instructions: str
    model: str
    params: dict[str, Any] = field(default_factory=dict)  # structured task inputs (also used by the mock)


@dataclass
class LLMResult:
    parsed: BaseModel
    model: str
    input_tokens: int | None = None
    output_tokens: int | None = None
    latency_ms: int | None = None
    request_id: str | None = None
    stop_reason: str | None = None
    raw: dict[str, Any] | None = None


class LLMProvider(Protocol):
    name: str
    is_mock: bool
    supports_vision: bool
    external: bool  # True = data leaves the deployment

    def generate(self, *, task: PromptTask, content: list[ContentBlock], output_schema: type[BaseModel],
                 timeout_s: float) -> LLMResult: ...
