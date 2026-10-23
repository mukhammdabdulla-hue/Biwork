"""The provider-independent AI engine: configuration validation, task routing, retries with backoff,
provider fallback, health monitoring, escalation to a stronger model, the company-scoped cache,
demo mode, and the end-to-end pipeline on the OpenAI adapter (fake HTTP transport)."""

from __future__ import annotations

import json
import uuid
from decimal import Decimal

import httpx2
import openai
import pytest
from pydantic import ValidationError

from app.ai import extraction, health, router
from app.ai.policy import AIPolicy, PolicyBlocked
from app.ai.providers.base import LLMResult, PermanentAIError, TransientAIError
from app.ai.providers.openai import OpenAIProvider
from app.config import Settings
from app.modules.registry import record_type
from tests.conftest import owner_query, records
from tests.test_ai_providers import envelope_json, openai_response
from tests.test_scenarios import UZ_TEXT, submit_text

LIVE = {"AI_PROVIDER": "auto", "OPENAI_API_KEY": "sk-test", "OPENAI_MODEL_DEFAULT": "oa-default", "OPENAI_MODEL_COMPLEX": "oa-complex",
        "OPENAI_MODEL_ECONOMY": "oa-economy", "GEMINI_API_KEY": "g-test", "GEMINI_MODEL_DEFAULT": "gm-default"}


class Scripted:
    """A provider whose answers are scripted per call: an exception to raise, or a JSON payload."""

    is_mock = False
    supports_vision = True
    supports_pdf = True
    external = True

    def __init__(self, name: str, *script):
        self.name = name
        self.script = list(script)
        self.models: list[str] = []

    def generate(self, *, task, content, output_schema, timeout_s):
        self.models.append(task.model)
        action = self.script.pop(0) if len(self.script) > 1 else self.script[0]
        if isinstance(action, Exception):
            raise action
        payload = action(task) if callable(action) else action
        try:
            parsed = output_schema.model_validate_json(payload)
        except ValidationError as exc:
            raise PermanentAIError("schema", "invalid_output") from exc
        return LLMResult(parsed=parsed, model=task.model, input_tokens=100, output_tokens=20, latency_ms=5)


def ctx(**kw) -> extraction.AIContext:
    rows: list[dict] = []
    c = extraction.AIContext(company_id=kw.pop("company_id", uuid.uuid4()), job_id=None, item_id=None,
                             policy=kw.pop("policy", AIPolicy()), log=rows.append, sleep=lambda s: None, **kw)
    c.rows = rows  # type: ignore[attr-defined]
    return c


def low_confidence_json() -> str:
    data = json.loads(envelope_json(qty_produced="500", product="A mahsulot"))
    for fv in data["records"][0].values():
        if not fv["not_found"]:
            fv["confidence"] = 0.3
    return json.dumps(data)


RT = record_type("production_report")


def full_json() -> str:
    """A complete, confident extraction (no reason to escalate)."""
    return envelope_json(qty_produced="500", product="A mahsulot", production_date="21.10.2026", department="1-sex")


# --------------------------------------------------------------------------- configuration


def test_startup_validation_of_ai_settings():
    with pytest.raises(ValidationError, match="OPENAI_MODEL_DEFAULT must be set"):
        Settings(openai_api_key="sk-x", _env_file=None)
    with pytest.raises(ValidationError, match="GEMINI_API_KEY is required"):
        Settings(ai_provider="gemini", _env_file=None)
    with pytest.raises(ValidationError, match="no AI provider credentials"):
        Settings(app_env="prod", ai_provider="auto", jwt_signing_key="x" * 40, cookie_secure=True, field_encryption_key="k", celery_always_eager=False,
                 _env_file=None)
    s = Settings(openai_api_key="sk-x", openai_model_default="m1", ai_fallback_providers="anthropic, gemini", _env_file=None)
    assert s.ai_fallback_providers == ["anthropic", "gemini"]
    assert s.provider_model("openai", "economy") == "m1" and s.provider_model("openai", "complex") is None


def test_ai_models_checks_configured_ids_and_lists_the_available_ones(ai_config, monkeypatch, capsys):
    from app import cli

    ai_config(**LIVE)

    class Listing:
        def list_models(self):
            return ["oa-default", "oa-economy", "gpt-z-mini", "gpt-a"]

    monkeypatch.setattr(router, "get_provider", lambda name: Listing())
    assert cli.ai_models("openai") == 1  # oa-complex is not served to this key
    out = capsys.readouterr().out

    def tier_line(tier: str) -> str:
        return next(line for line in out.splitlines() if line.split()[:1] == [tier])

    assert "oa-default" in tier_line("default") and tier_line("default").endswith(" ok")
    assert "oa-complex" in tier_line("complex") and tier_line("complex").endswith("NOT AVAILABLE to this API key")
    assert "available: gpt-a, gpt-z-mini, oa-default, oa-economy" in out

    # Gemini ids may be configured with the "models/" prefix the listing does not carry.
    ai_config(AI_PROVIDER="auto", GEMINI_API_KEY="g", GEMINI_MODEL_DEFAULT="models/gm-1")
    monkeypatch.setattr(router, "get_provider", lambda name: type("L", (), {"list_models": lambda self: ["gm-1"]})())
    assert cli.ai_models("gemini") == 0
    # With AI_PROVIDER=demo the keys are not used, so a clean check would mislead.
    ai_config(AI_PROVIDER="demo")
    assert cli.ai_models("gemini") == 1
    assert "AI_PROVIDER=demo" in capsys.readouterr().out


def test_no_credentials_means_labelled_demo_mode(ai_config):
    ai_config(AI_PROVIDER="auto")
    assert router.demo_mode() and router.provider_order() == ["demo"]
    info = extraction.provider_info()
    assert info["is_mock"] and info["mode"] == "demo" and "not real AI" in info["demo_notice"]


# --------------------------------------------------------------------------- routing


def test_task_routing_uses_configured_tiers(ai_config):
    ai_config(**LIVE)
    policy = AIPolicy()
    assert [(c.provider, c.model, c.tier) for c in router.route("classify", policy)] == [
        ("openai", "oa-economy", "economy"), ("gemini", "gm-default", "economy")]
    assert router.route("extract", policy)[0].model == "oa-default"
    escalated = router.route("extract", policy, escalate=True, reason="low confidence")
    assert escalated[0].model == "oa-complex" and "low confidence" in escalated[0].reason
    assert escalated[1].model == "gm-default"  # no complex model configured: the default stands in
    assert router.route("extract", policy)[1].fallback_from == "openai"


def test_routing_and_fallback_switches(ai_config):
    ai_config(**LIVE, AI_ROUTING_ENABLED="false")
    assert {c.tier for c in router.route("classify", AIPolicy())} == {"default"}
    ai_config(**LIVE, AI_FALLBACK_ENABLED="false")
    assert [c.provider for c in router.route("extract", AIPolicy())] == ["openai"]
    ai_config(**{**LIVE, "AI_PROVIDER": "gemini", "AI_FALLBACK_ENABLED": "true"})
    assert [c.provider for c in router.route("extract", AIPolicy())] == ["gemini", "openai"]


def test_company_policy_limits_providers(ai_config):
    ai_config(**LIVE)
    only_gemini = AIPolicy(allowed_providers=("gemini",))
    assert [c.provider for c in router.route("extract", only_gemini)] == ["gemini"]
    with pytest.raises(PolicyBlocked):
        router.route("extract", AIPolicy(external_ai_allowed=False))


def test_routing_file_override(ai_config, tmp_path):
    table = json.loads(router._DEFAULT_FILE.read_text())
    table["tasks"]["classify"]["tier"] = "default"
    path = tmp_path / "routing.json"
    path.write_text(json.dumps(table))
    ai_config(**LIVE, AI_ROUTING_FILE=str(path))
    assert router.route("classify", AIPolicy())[0].model == "oa-default"


# --------------------------------------------------------------------------- reliability


def test_transient_errors_are_retried_with_backoff(ai_config):
    oa = Scripted("openai", TransientAIError("rate limit", retry_after=1.5), TransientAIError("timeout"), full_json())
    ai_config(providers={"openai": oa}, **LIVE)
    c = ctx()
    waits: list[float] = []
    c.sleep = waits.append
    result = extraction.extract(c, RT, "500 dona A mahsulot ishlab chiqarildi")
    assert result.candidate.provider == "openai" and result.records
    assert [(r["status"], r["attempt"]) for r in c.rows] == [("error", 1), ("error", 2), ("ok", 3)]
    assert waits[0] == 1.5 and len(waits) == 2  # retry-after honoured, then exponential backoff


def test_fallback_to_next_provider_when_primary_is_unavailable(ai_config):
    oa = Scripted("openai", TransientAIError("503"))
    gm = Scripted("gemini", full_json())
    ai_config(providers={"openai": oa, "gemini": gm}, **LIVE)
    c = ctx()
    result = extraction.extract(c, RT, "500 dona A mahsulot")
    assert result.candidate.provider == "gemini" and result.candidate.fallback_from == "openai"
    assert [(r["provider"], r["status"]) for r in c.rows] == [("openai", "error")] * 3 + [("gemini", "ok")]
    assert c.rows[-1]["fallback_from"] == "openai"


def test_all_providers_down_keeps_the_job_retryable(ai_config):
    ai_config(providers={"openai": Scripted("openai", TransientAIError("503")), "gemini": Scripted("gemini", TransientAIError("503"))},
              **LIVE)
    with pytest.raises(TransientAIError, match="retried safely"):
        extraction.extract(ctx(), RT, "500 dona A mahsulot")


def test_fallback_disabled_fails_on_primary(ai_config):
    gm = Scripted("gemini", full_json())
    ai_config(providers={"openai": Scripted("openai", TransientAIError("503")), "gemini": gm}, **LIVE, AI_FALLBACK_ENABLED="false")
    with pytest.raises(TransientAIError):
        extraction.extract(ctx(), RT, "500 dona A mahsulot")
    assert gm.models == []


def test_auth_error_falls_back_but_bad_request_does_not(ai_config):
    gm = Scripted("gemini", full_json())
    ai_config(providers={"openai": Scripted("openai", PermanentAIError("401", "auth")), "gemini": gm}, **LIVE)
    assert extraction.extract(ctx(), RT, "500 dona").candidate.provider == "gemini"
    gm2 = Scripted("gemini", full_json())
    ai_config(providers={"openai": Scripted("openai", PermanentAIError("400", "bad_request")), "gemini": gm2}, **LIVE)
    with pytest.raises(PermanentAIError):
        extraction.extract(ctx(), RT, "500 dona")
    assert gm2.models == []


def test_health_monitor_moves_a_failing_provider_to_the_back(ai_config):
    ai_config(**LIVE, AI_HEALTH_FAILURE_THRESHOLD="2")
    for _ in range(2):
        health.record_failure("openai", "503")
    assert not health.is_available("openai")
    assert [c.provider for c in router.route("extract", AIPolicy())] == ["gemini", "openai"]
    assert router.health_report()["openai"]["state"] == "down"
    health.record_success("openai", 120)
    assert router.route("extract", AIPolicy())[0].provider == "openai"


def test_invalid_output_is_repaired_then_escalated(ai_config):
    oa = Scripted("openai", '{"records": "bad"}', '{"records": "bad"}', envelope_json())
    ai_config(providers={"openai": oa}, **{**LIVE, "GEMINI_API_KEY": ""})
    c = ctx()
    result = extraction.extract(c, RT, "500 dona A mahsulot")
    assert oa.models == ["oa-default", "oa-default", "oa-complex"]
    assert result.escalated and result.escalation_reason == "schema-invalid output"
    assert [r["status"] for r in c.rows] == ["invalid_output", "invalid_output", "ok"]


def test_input_size_limits(ai_config):
    ai_config(providers={"openai": Scripted("openai", envelope_json())}, **LIVE, AI_MAX_INPUT_TOKENS="50")
    with pytest.raises(PermanentAIError) as info:
        extraction.extract(ctx(), RT, "слово " * 200)
    assert info.value.kind == "too_large"


# --------------------------------------------------------------------------- escalation and cost controls


def test_uncertain_extraction_escalates_to_the_complex_model(ai_config):
    oa = Scripted("openai", lambda task: low_confidence_json() if task.model == "oa-default" else full_json())
    ai_config(providers={"openai": oa}, **LIVE)
    result = extraction.extract(ctx(), RT, "500 dona A mahsulot")
    assert oa.models == ["oa-default", "oa-complex"]
    assert result.escalated and result.candidate.model == "oa-complex" and "mean field confidence" in result.escalation_reason
    assert result.concerns == []


def test_escalation_keeps_first_result_when_stronger_model_is_not_better(ai_config):
    oa = Scripted("openai", low_confidence_json())
    ai_config(providers={"openai": oa}, **LIVE)
    result = extraction.extract(ctx(), RT, "500 dona A mahsulot")
    assert oa.models == ["oa-default", "oa-complex"] and result.candidate.model == "oa-default"
    assert result.concerns and any("not better supported" in n for n in result.notes)


def test_no_escalation_without_a_stronger_model(ai_config):
    oa = Scripted("openai", low_confidence_json())
    ai_config(providers={"openai": oa}, **{**LIVE, "OPENAI_MODEL_COMPLEX": "", "GEMINI_API_KEY": ""})
    result = extraction.extract(ctx(), RT, "500 dona")
    assert oa.models == ["oa-default"] and result.concerns and "goes to review" in result.notes[0]


def test_classification_cache_is_company_scoped(ai_config):
    answer = json.dumps({"record_types": [{"record_type": "production_report", "count_estimate": 1, "confidence": 0.9,
                                           "reason": "r"}], "language": "uz-Latn", "contains_instructions": False})
    oa = Scripted("openai", answer)
    ai_config(providers={"openai": oa}, **LIVE)
    company = uuid.uuid4()
    candidates = [RT, record_type("task")]
    first, second, other = ctx(company_id=company), ctx(company_id=company), ctx(company_id=uuid.uuid4())
    extraction.classify(first, UZ_TEXT, candidates)
    extraction.classify(second, UZ_TEXT, candidates)
    extraction.classify(other, UZ_TEXT, candidates)
    assert len(oa.models) == 2  # the second call of the same company was served from cache
    assert second.rows[0]["status"] == "cached" and second.rows[0]["cost_usd"] == 0
    assert other.rows[0]["status"] == "ok"


def test_configured_prices_drive_cost_estimates(ai_config):
    from app.ai import budget

    ai_config(**LIVE, AI_PRICING_JSON='{"oa-default": [0.5, 2.0]}')
    assert budget.estimate_cost("oa-default", 1_000_000, 100_000) == Decimal("0.7")
    assert budget.estimate_cost("oa-default-2026-08-01", 1_000_000, 0) == Decimal("0.5")  # dated snapshot of the alias
    assert budget.estimate_cost("unknown-model", 10, 10) is None


# --------------------------------------------------------------------------- end to end on the OpenAI adapter


def openai_handler(seen: list[dict], extract_payload: str | None = None):
    def handler(request: httpx2.Request) -> httpx2.Response:
        body = json.loads(request.content)
        seen.append(body)
        if "Allowed record types" in body["input"][0]["content"][-1]["text"]:
            return httpx2.Response(200, json=openai_response(json.dumps({"record_types": [
                {"record_type": "production_report", "count_estimate": 1, "confidence": 0.93, "reason": "report"}],
                "language": "uz-Latn", "contains_instructions": False})))
        return httpx2.Response(200, json=openai_response(extract_payload or envelope_json(
            production_date="21-oktabr", product="A mahsulot", department="1-sex", qty_produced="500", qty_accepted="480",
            qty_defective="20", unit="dona")))
    return handler


def live_openai(ai_config, seen: list[dict], **env) -> None:
    client = openai.OpenAI(api_key="sk-test", base_url="https://openai.test/v1", max_retries=0,
                           http_client=httpx2.Client(transport=httpx2.MockTransport(openai_handler(seen, env.pop("payload", None)))))
    ai_config(providers={"openai": OpenAIProvider(client=client)},
              **{**LIVE, "GEMINI_API_KEY": "", "AI_PRICING_JSON": '{"test-model": [1.0, 4.0]}', **env})


def test_end_to_end_with_openai_adapter_and_review_view(client, world, ai_config, owner_engine):
    seen: list[dict] = []
    live_openai(ai_config, seen)
    headers = world.login(client, "manager")
    job = submit_text(client, headers, UZ_TEXT)
    assert job["status"] == "awaiting_review" and job["ai_mode"] == "live" and job["ai_provider"] == "openai", job
    assert [b["model"] for b in seen] == ["oa-economy", "oa-default"]  # classify on economy, extract on default tier
    assert not any("Demo mode" in w for w in job["warnings"])

    calls = owner_query(owner_engine, "SELECT task, provider, tier, status, attempt, input_tokens, cost_usd, outcome "
                                      "FROM agent.ai_calls ORDER BY created_at")
    assert [(c[0], c[1], c[2], c[3], c[4]) for c in calls] == [("classify", "openai", "economy", "ok", 1),
                                                               ("extract", "openai", "default", "ok", 1)]
    assert calls[1][5] == 1500 and calls[1][6] == Decimal("0.0027")  # 1500 in + 300 out at the configured price
    assert calls[1][7]["records"] == 1

    rec = records(client, headers, job["id"])["items"][0]
    detail = client.get(f"/api/v1/agent/records/{rec['id']}", headers=headers).json()
    assert detail["original_input"]["text"] == UZ_TEXT
    assert detail["ai"]["provider"] == "openai" and detail["ai"]["model"] == "test-model-2026-08-01" and not detail["ai"]["is_demo"]
    assert detail["proposed_change"]["action"] == "create" and detail["proposed_change"]["record_type"] == "production_report"
    assert detail["proposed_change"]["values"]["qty_produced"] == "500"
    assert {c["task"] for c in detail["ai"]["calls"]} == {"classify", "extract"}

    usage = client.get("/api/v1/agent/ai/usage", headers=headers).json()
    assert usage["totals"]["calls"] == 2 and usage["totals"]["documents"] == 1 and usage["totals"]["records"] == 1
    assert usage["totals"]["cost_per_document_usd"] > 0
    status = client.get("/api/v1/agent/ai/status", headers=headers).json()
    assert status["mode"] == "live" and status["provider_order"] == ["openai"]
    assert status["providers"]["openai"]["state"] == "up" and "sk-test" not in json.dumps(status)


def test_uncertain_live_extraction_goes_to_review_with_reason(client, world, ai_config):
    seen: list[dict] = []
    live_openai(ai_config, seen, payload=low_confidence_json(), OPENAI_MODEL_COMPLEX="")
    headers = world.login(client, "manager")
    job = submit_text(client, headers, UZ_TEXT)
    rec = records(client, headers, job["id"])["items"][0]
    assert rec["review_group"] in ("needs_approval", "needs_correction")
    detail = client.get(f"/api/v1/agent/records/{rec['id']}", headers=headers).json()
    assert any(i["rule_code"] == "ai_uncertain_extraction" for i in detail["issues"])
    assert detail["ai"]["concerns"] and detail["uncertain_values"]


def test_provider_outage_preserves_job_for_safe_retry(client, world, ai_config, owner_engine):
    seen: list[dict] = []
    outage = {"on": True}

    def handler(request):
        if outage["on"]:
            return httpx2.Response(503, json={"error": {"message": "overloaded"}})
        return openai_handler(seen)(request)

    client_ = openai.OpenAI(api_key="sk-test", base_url="https://openai.test/v1", max_retries=0,
                            http_client=httpx2.Client(transport=httpx2.MockTransport(handler)))
    ai_config(providers={"openai": OpenAIProvider(client=client_)}, **{**LIVE, "GEMINI_API_KEY": ""})
    headers = world.login(client, "manager")
    job = submit_text(client, headers, UZ_TEXT)
    assert job["status"] == "failed" and job["error"]["retryable"] is True
    assert "retried safely" in job["error"]["message"]
    assert owner_query(owner_engine, "SELECT count(*) FROM agent.extracted_records")[0][0] == 0  # nothing marked processed
    outage["on"] = False
    health.reset()
    r = client.post(f"/api/v1/agent/jobs/{job['id']}/retry", headers=headers)
    assert r.status_code == 202, r.text
    job = client.get(f"/api/v1/agent/jobs/{job['id']}", headers=headers).json()
    assert job["status"] == "awaiting_review", job


def test_demo_results_are_labelled_in_review(client, world):
    headers = world.login(client, "manager")
    job = submit_text(client, headers, UZ_TEXT)
    rec = records(client, headers, job["id"])["items"][0]
    detail = client.get(f"/api/v1/agent/records/{rec['id']}", headers=headers).json()
    assert detail["ai"]["is_demo"] and "not real AI" in detail["ai"]["label"]
    assert all(f["extracted_by"] in ("demo", "none") for f in detail["fields"])
    usage = client.get("/api/v1/agent/ai/usage", headers=headers).json()
    assert usage["totals"]["demo_calls"] == usage["totals"]["calls"] > 0


def test_policy_blocked_extraction_is_a_visible_failure(ai_config):
    ai_config(**LIVE)
    with pytest.raises(PolicyBlocked):
        extraction.extract(ctx(policy=AIPolicy(external_ai_allowed=False)), RT, "x")
    with pytest.raises(PermanentAIError):
        extraction.classify(ctx(policy=AIPolicy(allowed_providers=("anthropic",))), "x", [RT])


