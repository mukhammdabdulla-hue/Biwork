"""Claude adapter tested against a fake HTTP transport (no credentials needed).

Verifies the request the SDK sends (model, schema-constrained output, effort, fallback beta,
untrusted-data wrapping) and the mapping of provider outcomes to retryable / permanent errors.
"""

from __future__ import annotations

import json

import anthropic
import httpx2
import pytest

from app.ai.extraction import envelope_for
from app.ai.providers.anthropic import AnthropicProvider
from app.ai.providers.base import PermanentAIError, PromptTask, TextBlock, TransientAIError
from app.modules.registry import record_type


def _message(text: str, stop_reason: str = "end_turn") -> dict:
    return {"id": "msg_test", "type": "message", "role": "assistant", "model": "claude-opus-5-5",
            "content": [{"type": "text", "text": text}], "stop_reason": stop_reason, "stop_sequence": None,
            "usage": {"input_tokens": 1200, "output_tokens": 340}}


def _provider(handler) -> tuple[AnthropicProvider, list[httpx2.Request]]:
    seen: list[httpx2.Request] = []

    def wrapped(request: httpx2.Request) -> httpx2.Response:
        seen.append(request)
        return handler(request)

    client = anthropic.Anthropic(api_key="test-key", base_url="https://api.test", max_retries=0,
                                 http_client=httpx2.Client(transport=httpx2.MockTransport(wrapped)))
    return AnthropicProvider(client=client), seen


TASK = PromptTask("extract", "v1", "system prompt", "Extract every Production report.", "claude-opus-5-5")


def _envelope_json() -> str:
    empty = {"value": None, "confidence": 0, "source_quote": None, "not_found": True, "alternatives": []}
    rt = record_type("production_report")
    rec = {f.name: dict(empty) for f in rt.fields}
    rec["qty_produced"] = {"value": "500", "confidence": 0.95, "source_quote": "500 dona", "not_found": False, "alternatives": []}
    return json.dumps({"records": [rec]})


def test_request_shape_and_parsed_output():
    provider, seen = _provider(lambda r: httpx2.Response(200, json=_message(_envelope_json())))
    result = provider.generate(task=TASK, content=[TextBlock("500 dona A mahsulot ishlab chiqarildi")],
                               output_schema=envelope_for(record_type("production_report")), timeout_s=30)
    assert result.parsed.records[0].qty_produced.value == "500"
    assert (result.input_tokens, result.output_tokens) == (1200, 340)
    body = json.loads(seen[0].content)
    assert body["model"] == "claude-opus-5-5" and body["system"] == "system prompt"
    assert body["output_config"]["format"]["type"] == "json_schema"
    assert body["output_config"]["effort"] == "medium"
    assert body["fallbacks"] == "default" and "server-side-fallback-2026-07-01" in seen[0].headers["anthropic-beta"]
    assert "tools" not in body  # extraction calls have no ability to act
    user_text = body["messages"][0]["content"][0]["text"]
    assert user_text.startswith('<untrusted_data source="document">') and user_text.endswith("</untrusted_data>")
    assert body["messages"][0]["content"][-1]["text"] == TASK.instructions


@pytest.mark.parametrize("status,error", [(429, TransientAIError), (529, TransientAIError), (500, TransientAIError),
                                          (400, PermanentAIError), (401, PermanentAIError)])
def test_http_errors_are_classified(status, error):
    provider, _ = _provider(lambda r: httpx2.Response(status, json={"type": "error", "error": {"type": "x", "message": "boom"}},
                                                      headers={"retry-after": "7"}))
    with pytest.raises(error) as info:
        provider.generate(task=TASK, content=[TextBlock("x")], output_schema=envelope_for(record_type("task")), timeout_s=5)
    if status == 429:
        assert info.value.retry_after == 7.0


def test_refusal_and_truncation_are_permanent_errors():
    for stop in ("refusal", "max_tokens"):
        provider, _ = _provider(lambda r, s=stop: httpx2.Response(200, json=_message(_envelope_json(), s)))
        with pytest.raises(PermanentAIError):
            provider.generate(task=TASK, content=[TextBlock("x")], output_schema=envelope_for(record_type("production_report")),
                              timeout_s=5)


def test_fallback_can_be_disabled(monkeypatch):
    from app.config import get_settings

    monkeypatch.setattr(get_settings(), "ai_refusal_fallback", False)
    provider, seen = _provider(lambda r: httpx2.Response(200, json=_message(_envelope_json())))
    provider.generate(task=TASK, content=[TextBlock("x")], output_schema=envelope_for(record_type("production_report")), timeout_s=5)
    assert "fallbacks" not in json.loads(seen[0].content) and "anthropic-beta" not in seen[0].headers


def test_end_to_end_extraction_with_claude_adapter(client, world, ai_config):
    """The whole pipeline with the real adapter class (fake transport): AI calls are logged with cost."""
    def handler(request: httpx2.Request) -> httpx2.Response:
        body = json.loads(request.content)
        if "Allowed record types" in body["messages"][0]["content"][-1]["text"]:
            return httpx2.Response(200, json=_message(json.dumps({"record_types": [
                {"record_type": "production_report", "count_estimate": 1, "confidence": 0.93, "reason": "output report"}],
                "language": "uz-Latn", "contains_instructions": False})))
        empty = {"value": None, "confidence": 0, "source_quote": None, "not_found": True, "alternatives": []}
        rec = {f.name: dict(empty) for f in record_type("production_report").fields}
        for name, value in {"production_date": "21-oktabr", "product": "A mahsulot", "department": "1-sex", "qty_produced": "500",
                            "qty_accepted": "480", "qty_defective": "20", "unit": "dona"}.items():
            rec[name] = {"value": value, "confidence": 0.95, "source_quote": value, "not_found": False, "alternatives": []}
        return httpx2.Response(200, json=_message(json.dumps({"records": [rec]})))

    provider, seen = _provider(handler)
    ai_config(providers={"anthropic": provider}, AI_PROVIDER="anthropic", ANTHROPIC_API_KEY="test-key")
    headers = world.login(client, "manager")
    r = client.post("/api/v1/agent/jobs", headers=headers, data={"text": "21-oktabr kuni 500 dona A mahsulot ... 1-sex"})
    job = client.get(f"/api/v1/agent/jobs/{r.json()['id']}", headers=headers).json()
    assert job["status"] == "awaiting_review", job
    assert len(seen) == 2
    calls = job["report"]["ai"]
    assert calls["calls"] == 2 and calls["input_tokens"] == 2400 and calls["cost_usd"] > 0
