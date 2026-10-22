"""Google Gemini adapter (alternative provider, official `google-genai` SDK).

- Structured output via `response_mime_type="application/json"` + `response_json_schema`
  (the Pydantic schema's JSON Schema); the JSON text is validated against the Pydantic model here
  and re-validated by the caller.
- Untrusted content is wrapped in delimited data blocks; the call has no tools.
- The SDK's own retries are off (no retry_options): the AI engine retries transient errors with
  backoff and then falls back to the next provider.
"""

from __future__ import annotations

import base64
import time
from typing import Any

from google import genai
from google.genai import errors as genai_errors
from google.genai import types
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

_REFUSAL_REASONS = {"SAFETY", "PROHIBITED_CONTENT", "BLOCKLIST", "SPII", "RECITATION", "IMAGE_SAFETY"}


def _parts(content: list[ContentBlock], instructions: str) -> list[types.Part]:
    parts: list[types.Part] = []
    for block in content:
        if isinstance(block, PdfBlock):
            parts.append(types.Part.from_bytes(data=base64.b64decode(block.data_b64), mime_type="application/pdf"))
        elif isinstance(block, ImageBlock):
            parts.append(types.Part.from_bytes(data=base64.b64decode(block.data_b64), mime_type=block.media_type))
    for block in content:
        if isinstance(block, TextBlock):
            parts.append(types.Part.from_text(text=wrap_untrusted(block)))
    parts.append(types.Part.from_text(text=instructions))
    return parts


def _is_network_error(exc: Exception) -> bool:
    names = {cls.__name__ for cls in type(exc).__mro__}
    return bool(names & {"TimeoutException", "TransportError", "ConnectError", "ReadTimeout", "TimeoutError", "ConnectionError"})


class GeminiProvider:
    name = "gemini"
    is_mock = False
    supports_vision = True
    supports_pdf = True
    external = True

    def __init__(self, client: genai.Client | None = None):
        s = get_settings()
        self.client = client or genai.Client(
            api_key=s.gemini_api_key,
            http_options=types.HttpOptions(base_url=s.gemini_base_url, timeout=int(s.ai_timeout_s * 1000)),
        )

    def generate(self, *, task: PromptTask, content: list[ContentBlock], output_schema: type[BaseModel],
                 timeout_s: float) -> LLMResult:
        config = types.GenerateContentConfig(
            system_instruction=task.system,
            response_mime_type="application/json",
            response_json_schema=output_schema.model_json_schema(),
            max_output_tokens=task.max_output_tokens,
            temperature=0,
            http_options=types.HttpOptions(timeout=int(timeout_s * 1000)),
        )
        started = time.monotonic()
        try:
            response = self.client.models.generate_content(
                model=task.model, contents=[types.Content(role="user", parts=_parts(content, task.instructions))], config=config,
            )
        except genai_errors.APIError as exc:
            code = int(exc.code or 0)
            if code in (408, 409, 429) or code >= 500:
                raise TransientAIError(f"AI provider error {code}" + (" (rate limit)" if code == 429 else "")) from exc
            kind = "auth" if code in (401, 403) else "bad_request"
            raise PermanentAIError(f"AI request rejected ({code}): {exc.message}", kind) from exc
        except Exception as exc:
            if _is_network_error(exc):
                raise TransientAIError(f"AI provider unreachable: {exc.__class__.__name__}") from exc
            raise
        latency_ms = int((time.monotonic() - started) * 1000)

        feedback = getattr(response, "prompt_feedback", None)
        if feedback is not None and getattr(feedback, "block_reason", None):
            raise PermanentAIError(f"The AI provider declined to process this content (category: {feedback.block_reason})",
                                   "refused")
        candidate = (response.candidates or [None])[0]
        finish = str(getattr(candidate, "finish_reason", "") or "").rsplit(".", 1)[-1]
        if finish == "MAX_TOKENS":
            raise PermanentAIError("The AI response was truncated (output too long); split the document and retry", "truncated")
        if finish in _REFUSAL_REASONS:
            raise PermanentAIError(f"The AI provider declined to process this content (category: {finish.lower()})", "refused")
        text = response.text
        if not text:
            raise PermanentAIError("The AI response contained no structured output", "invalid_output")
        try:
            parsed = output_schema.model_validate_json(text)
        except ValidationError as exc:
            raise PermanentAIError(f"AI output did not match the schema: {exc.error_count()} error(s)", "invalid_output") from exc
        usage = response.usage_metadata
        output_tokens = None
        if usage is not None and usage.candidates_token_count is not None:
            output_tokens = usage.candidates_token_count + (usage.thoughts_token_count or 0)  # thinking is billed as output
        raw: dict[str, Any] = {"model": response.model_version or task.model, "finish_reason": finish}
        return LLMResult(
            parsed=parsed,
            model=response.model_version or task.model,
            input_tokens=getattr(usage, "prompt_token_count", None),
            output_tokens=output_tokens,
            latency_ms=latency_ms,
            request_id=getattr(response, "response_id", None),
            stop_reason=finish or None,
            raw=raw,
        )

    def list_models(self) -> list[str]:
        return sorted((m.name or "").removeprefix("models/") for m in self.client.models.list())
