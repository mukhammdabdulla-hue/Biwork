"""OpenAI adapter (primary provider, official `openai` SDK, Responses API).

- Structured output via `responses.parse(text_format=Model)`: the SDK turns the Pydantic schema
  into a strict JSON schema, and the parsed object is re-validated by the caller regardless.
- `store=False`: requests are not kept for later retrieval in the OpenAI dashboard.
- Untrusted content is wrapped in delimited data blocks; the call has no tools.
- The SDK's own retries are off: the AI engine retries transient errors with backoff and then
  falls back to the next provider, so every attempt is counted and logged.
"""

from __future__ import annotations

import time
from typing import Any

import openai
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


def _input(content: list[ContentBlock], instructions: str) -> list[dict[str, Any]]:
    """Files/images first, then the delimited text data, then the task instructions."""
    parts: list[dict[str, Any]] = []
    for block in content:
        if isinstance(block, PdfBlock):
            parts.append({"type": "input_file", "filename": f"{block.label}.pdf",
                          "file_data": f"data:application/pdf;base64,{block.data_b64}"})
        elif isinstance(block, ImageBlock):
            parts.append({"type": "input_image", "image_url": f"data:{block.media_type};base64,{block.data_b64}", "detail": "high"})
    for block in content:
        if isinstance(block, TextBlock):
            parts.append({"type": "input_text", "text": wrap_untrusted(block)})
    parts.append({"type": "input_text", "text": instructions})
    return [{"role": "user", "content": parts}]


def _retry_after(exc: openai.APIStatusError) -> float | None:
    value = exc.response.headers.get("retry-after") if exc.response is not None else None
    try:
        return float(value) if value else None
    except ValueError:
        return None


class OpenAIProvider:
    name = "openai"
    is_mock = False
    supports_vision = True
    supports_pdf = True
    external = True

    def __init__(self, client: openai.OpenAI | None = None):
        s = get_settings()
        self.client = client or openai.OpenAI(
            api_key=s.openai_api_key,
            base_url=s.openai_base_url,
            organization=s.openai_organization,
            timeout=s.ai_timeout_s,
            max_retries=0,
        )

    def generate(self, *, task: PromptTask, content: list[ContentBlock], output_schema: type[BaseModel],
                 timeout_s: float) -> LLMResult:
        started = time.monotonic()
        try:
            response = self.client.responses.parse(
                model=task.model,
                instructions=task.system,
                input=_input(content, task.instructions),
                text_format=output_schema,
                max_output_tokens=task.max_output_tokens,
                store=False,
                timeout=timeout_s,
            )
        except openai.RateLimitError as exc:
            raise TransientAIError("AI provider rate limit reached", _retry_after(exc)) from exc
        except (openai.APITimeoutError, openai.APIConnectionError) as exc:
            raise TransientAIError(f"AI provider unreachable: {exc.__class__.__name__}") from exc
        except openai.APIStatusError as exc:
            if exc.status_code in (408, 409, 429) or exc.status_code >= 500:
                raise TransientAIError(f"AI provider error {exc.status_code}", _retry_after(exc)) from exc
            kind = "auth" if exc.status_code in (401, 403) else "bad_request"
            raise PermanentAIError(f"AI request rejected ({exc.status_code}): {exc.message}", kind) from exc
        except openai.ContentFilterFinishReasonError as exc:
            raise PermanentAIError("The AI provider declined to process this content (content filter)", "refused") from exc
        except openai.LengthFinishReasonError as exc:
            raise PermanentAIError("The AI response was truncated (output too long); split the document and retry",
                                   "truncated") from exc
        except ValidationError as exc:
            raise PermanentAIError(f"AI output did not match the schema: {exc.error_count()} error(s)", "invalid_output") from exc
        latency_ms = int((time.monotonic() - started) * 1000)

        if response.status == "incomplete":
            reason = getattr(response.incomplete_details, "reason", None)
            if reason == "content_filter":
                raise PermanentAIError("The AI provider declined to process this content (content filter)", "refused")
            raise PermanentAIError("The AI response was truncated (output too long); split the document and retry", "truncated")
        if response.status == "failed":
            message = getattr(response.error, "message", None) or "unknown error"
            raise TransientAIError(f"AI provider failed to complete the response: {message}")
        for item in response.output:
            for part in getattr(item, "content", None) or []:
                if getattr(part, "type", None) == "refusal":
                    raise PermanentAIError("The AI provider declined to process this content", "refused")
        parsed = response.output_parsed
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
            stop_reason=response.status,
            raw={"id": response.id, "model": response.model, "status": response.status},
        )

    def list_models(self) -> list[str]:
        return sorted(m.id for m in self.client.models.list())
