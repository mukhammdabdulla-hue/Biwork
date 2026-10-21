"""Provider-independent LLM interface (docs/architecture.md §7.2).

Business logic never names a model or a vendor: it asks for a *task* (classify / extract /
map_columns) with untrusted content blocks and a Pydantic output schema, and gets back a parsed,
validated object plus usage metadata. Which provider and model serve the task is decided by
`app.ai.router` from configuration; each adapter (OpenAI, Gemini, Anthropic, demo) only translates
the request to its official SDK and maps the outcome to the errors below.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Literal, Protocol

from pydantic import BaseModel


class AIError(Exception):
    """Base class for AI layer failures."""


class TransientAIError(AIError):
    """Timeouts, rate limits, overload, 5xx: retried with backoff, then the next provider, then the job."""

    def __init__(self, message: str, retry_after: float | None = None):
        super().__init__(message)
        self.retry_after = retry_after


# Why a permanent error happened. The engine tries the next configured provider for the kinds in
# FALLBACK_KINDS; the others would fail the same way everywhere, so the item fails visibly.
PermanentKind = Literal["refused", "truncated", "auth", "not_configured", "invalid_output", "bad_request", "too_large", "policy"]
FALLBACK_KINDS = frozenset({"refused", "truncated", "auth", "not_configured", "invalid_output"})


class PermanentAIError(AIError):
    """Bad request, authentication, refusal, or repeated invalid output: the item fails visibly."""

    def __init__(self, message: str, kind: PermanentKind = "bad_request"):
        super().__init__(message)
        self.kind = kind


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
    params: dict[str, Any] = field(default_factory=dict)  # structured task inputs (also used by the demo provider)
    max_output_tokens: int = 16000


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


def wrap_untrusted(block: TextBlock) -> str:
    """Delimits user content so prompts can tell data from instructions (prompt-injection defence)."""
    text = block.text.replace("</untrusted_data>", "</untrusted_data_>")  # the data cannot close its own wrapper
    return f'<untrusted_data source="{block.label}">\n{text}\n</untrusted_data>'


class LLMProvider(Protocol):
    name: str
    is_mock: bool  # True only for the demo provider: results are simulated, never real AI analysis
    supports_vision: bool
    supports_pdf: bool
    external: bool  # True = data leaves the deployment

    def generate(self, *, task: PromptTask, content: list[ContentBlock], output_schema: type[BaseModel],
                 timeout_s: float) -> LLMResult: ...
