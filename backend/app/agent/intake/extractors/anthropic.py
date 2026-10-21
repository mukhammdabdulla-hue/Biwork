"""Claude extractor using structured outputs (JSON-schema-constrained responses)."""

from __future__ import annotations

import base64
from typing import Any

import anthropic
import pydantic

from app.agent.intake.documents import PDF, PreparedDocument
from app.agent.intake.extractors.base import ExtractionError, ExtractionOutcome
from app.agent.intake.prompts import PROMPT_VERSION, SYSTEM_PROMPT, user_instruction
from app.agent.intake.schemas import DocumentType, ExtractionEnvelope, ExtractorInfo

# On a safety-classifier decline the API re-runs the request on Anthropic's
# recommended fallback model instead of returning the refusal.
FALLBACK_BETA = "server-side-fallback-2026-07-01"


def build_document_block(document: PreparedDocument) -> dict[str, Any]:
    if document.media_type == PDF:
        return {
            "type": "document",
            "source": {
                "type": "base64",
                "media_type": PDF,
                "data": base64.standard_b64encode(document.data).decode("ascii"),
            },
        }
    if document.media_type.startswith("image/"):
        return {
            "type": "image",
            "source": {
                "type": "base64",
                "media_type": document.media_type,
                "data": base64.standard_b64encode(document.data).decode("ascii"),
            },
        }
    # Text goes in a document block rather than inline in the prompt, so the
    # submitted content stays clearly separated from our instructions.
    return {
        "type": "document",
        "source": {"type": "text", "media_type": "text/plain", "data": document.text()},
    }


class AnthropicExtractor:
    provider = "anthropic"

    def __init__(
        self,
        client: anthropic.AsyncAnthropic,
        *,
        model: str,
        effort: str,
        max_output_tokens: int,
    ) -> None:
        self._client = client
        self._model = model
        self._effort = effort
        self._max_output_tokens = max_output_tokens

    async def extract(
        self, document: PreparedDocument, type_hint: DocumentType | None = None
    ) -> ExtractionOutcome:
        try:
            response = await self._client.beta.messages.parse(
                model=self._model,
                max_tokens=self._max_output_tokens,
                system=SYSTEM_PROMPT,
                messages=[
                    {
                        "role": "user",
                        "content": [
                            build_document_block(document),
                            {"type": "text", "text": user_instruction(type_hint)},
                        ],
                    }
                ],
                output_format=ExtractionEnvelope,
                output_config={"effort": self._effort},
                betas=[FALLBACK_BETA],
                fallbacks="default",
            )
        except anthropic.RateLimitError as exc:
            raise ExtractionError("rate_limited", "AI provider rate limit reached.", retryable=True) from exc
        except anthropic.APITimeoutError as exc:
            raise ExtractionError("timeout", "AI provider timed out.", retryable=True) from exc
        except anthropic.APIConnectionError as exc:
            raise ExtractionError("provider_unreachable", "Could not reach the AI provider.", retryable=True) from exc
        except anthropic.APIStatusError as exc:
            retryable = exc.status_code >= 500
            raise ExtractionError(
                "provider_error", f"AI provider returned HTTP {exc.status_code}.", retryable=retryable
            ) from exc
        except pydantic.ValidationError as exc:
            # Structured outputs make this unlikely; it can still happen if the
            # response was cut off.
            raise ExtractionError("invalid_output", "AI output did not match the schema.", retryable=True) from exc

        if response.stop_reason == "refusal":
            raise ExtractionError("refused", "The AI provider declined to process this document.", retryable=False)
        if response.stop_reason == "max_tokens":
            raise ExtractionError("output_truncated", "AI output exceeded the token limit.", retryable=False)

        envelope = response.parsed_output
        if envelope is None:
            raise ExtractionError("empty_output", "AI provider returned no structured output.", retryable=True)

        return ExtractionOutcome(
            envelope=envelope,
            extractor=ExtractorInfo(
                provider=self.provider,
                model=response.model,
                prompt_version=PROMPT_VERSION,
                is_mock=False,
                input_tokens=response.usage.input_tokens,
                output_tokens=response.usage.output_tokens,
            ),
        )
