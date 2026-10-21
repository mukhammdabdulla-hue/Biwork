"""Anthropic Claude adapter (optional provider, official `anthropic` SDK).

- Schema-constrained output via the SDK's `messages.parse(output_format=Model)`; the parsed object
  is re-validated by the caller regardless.
- Untrusted content is wrapped in delimited data blocks; the call has no tools.
- The SDK's own retries are off: the AI engine retries transient errors with backoff and then
  falls back to the next provider, so every attempt is counted and logged. 429/5xx/timeouts
  surface as TransientAIError, everything else as PermanentAIError with a kind.
- Refusal fallback (server-side, `fallbacks: "default"`) is on by default and can be disabled
  with AI_REFUSAL_FALLBACK=false.
"""

from __future__ import annotations

import time
from typing import Any

import anthropic
from pydantic import BaseModel, ValidationError

from app.ai.providers.base import (
    ContentBlock,
    ImageBlock,
    LLMResult,
    PdfBlock,
    PermanentAIError,
    PromptTask,
    TextBlock,
    TransientAIError,
    wrap_untrusted,
)
from app.config import get_settings

_FALLBACK_BETA = "server-side-fallback-2026-07-01"


def _content_params(content: list[ContentBlock], instructions: str) -> list[dict[str, Any]]:
    """Documents/images first, then the delimited text data, then the task instructions."""
    blocks: list[dict[str, Any]] = []
    for block in content:
        if isinstance(block, PdfBlock):
            blocks.append({"type": "document", "source": {"type": "base64", "media_type": "application/pdf", "data": block.data_b64}})
        elif isinstance(block, ImageBlock):
            blocks.append({"type": "image", "source": {"type": "base64", "media_type": block.media_type, "data": block.data_b64}})
    for block in content:
        if isinstance(block, TextBlock):
            blocks.append({"type": "text", "text": wrap_untrusted(block)})
    blocks.append({"type": "text", "text": instructions})
    return blocks


class AnthropicProvider:
    name = "anthropic"
    is_mock = False
    supports_vision = True
    supports_pdf = True
    external = True

    def __init__(self, client: anthropic.Anthropic | None = None):
        s = get_settings()
        self.settings = s
        self.client = client or anthropic.Anthropic(
            api_key=s.anthropic_api_key,
            base_url=s.anthropic_base_url,
            timeout=s.ai_timeout_s,
            max_retries=0,
        )

    def generate(self, *, task: PromptTask, content: list[ContentBlock], output_schema: type[BaseModel],
                 timeout_s: float) -> LLMResult:
        kwargs: dict[str, Any] = {
            "model": task.model,
            "max_tokens": task.max_output_tokens,
            "system": task.system,
            "messages": [{"role": "user", "content": _content_params(content, task.instructions)}],
            "output_format": output_schema,
            "output_config": {"effort": self.settings.ai_effort},
            "timeout": timeout_s,
        }
        started = time.monotonic()
        try:
            if self.settings.ai_refusal_fallback:
                response = self.client.beta.messages.parse(**kwargs, betas=[_FALLBACK_BETA], fallbacks="default")
            else:
                response = self.client.messages.parse(**kwargs)
        except anthropic.RateLimitError as exc:
            retry_after = exc.response.headers.get("retry-after") if exc.response is not None else None
            raise TransientAIError("AI provider rate limit reached", float(retry_after) if retry_after else None) from exc
        except (anthropic.APITimeoutError, anthropic.APIConnectionError) as exc:
            raise TransientAIError(f"AI provider unreachable: {exc.__class__.__name__}") from exc
        except anthropic.InternalServerError as exc:
            raise TransientAIError(f"AI provider error {exc.status_code}") from exc
        except anthropic.APIStatusError as exc:
            if exc.status_code in (408, 409, 429) or exc.status_code >= 500:
                raise TransientAIError(f"AI provider error {exc.status_code}") from exc
            kind = "auth" if exc.status_code in (401, 403) else "bad_request"
            raise PermanentAIError(f"AI request rejected ({exc.status_code}): {exc.message}", kind) from exc
        except ValidationError as exc:
            raise PermanentAIError(f"AI output did not match the schema: {exc.error_count()} error(s)", "invalid_output") from exc
        latency_ms = int((time.monotonic() - started) * 1000)

        if response.stop_reason == "refusal":
            category = getattr(getattr(response, "stop_details", None), "category", None)
            raise PermanentAIError(f"The AI provider declined to process this content (category: {category or 'unspecified'})",
                                   "refused")
        if response.stop_reason == "max_tokens":
            raise PermanentAIError("The AI response was truncated (output too long); split the document and retry", "truncated")
        parsed = getattr(response, "parsed_output", None)
        if parsed is None:
            raise PermanentAIError("The AI response contained no structured output", "invalid_output")
        usage = response.usage
        return LLMResult(
            parsed=parsed,
            model=response.model,
            input_tokens=getattr(usage, "input_tokens", None),
            output_tokens=getattr(usage, "output_tokens", None),
            latency_ms=latency_ms,
            request_id=getattr(response, "_request_id", None),
            stop_reason=response.stop_reason,
            raw={"id": response.id, "model": response.model, "stop_reason": response.stop_reason},
        )

    def list_models(self) -> list[str]:
        return sorted(m.id for m in self.client.models.list())
