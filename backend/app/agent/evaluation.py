"""Provider / model evaluation on BIWORK's own document types (docs/ai-engine.md §Evaluation).

Runs every case of a dataset (synthetic, anonymized Uzbek / Russian / English business documents)
through the real pipeline — parse, classify, extract, normalize, validate, match — once per
provider/model under test, each in a fresh evaluation company seeded with the same master data, and
measures:

- record-type accuracy and field-level extraction accuracy (against normalized expected values),
- schema validity (share of AI responses that passed schema validation),
- duplicate-detection precision and recall,
- false-acceptance rate (records the pipeline would call `ready` although they are wrong),
- human-correction rate (expected records that would need at least one correction),
- processing latency, cost per document and cost per successfully validated record.

Nothing is committed: the run stops at review, so no business data is written. Evaluation companies
are flagged as sample data. Run it against a staging database, never production:

    python -m app.cli ai-eval --provider openai --provider openai:<other-model> --provider gemini --out eval/report.json

Demo-mode results measure the rule-based stand-in only and are labelled as such.
"""

from __future__ import annotations

import json
import os
import secrets
import time
import uuid
from contextlib import contextmanager
from dataclasses import dataclass, field
from datetime import UTC, date, datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any

from sqlalchemy import func, select

from app.agent.models import AICall, ExtractedRecord, IngestionJob, MatchCandidate
from app.agent.pipeline import orchestrator
from app.agent.pipeline.ingest import create_job
from app.config import get_settings
from app.core.models import User
from app.core.services import documents, identity, master_data, personnel
from app.core.text import normalize_key
from app.db.session import SessionLocal, tenant_session

DEFAULT_DATASET = Path(__file__).resolve().parents[2] / "eval" / "dataset"
EVAL_USER = "ai-eval@biwork.invalid"
DUPLICATE_SCORE = 0.85


# --------------------------------------------------------------------------- configuration per run


@contextmanager
def provider_settings(spec: str):
    """Route every task to one provider (and optionally one model) with fallback off, so the run
    measures exactly that provider/model. `spec` is `demo`, `<provider>` or `<provider>:<model>`."""
    from app.agent import versioning
    from app.ai import cache, health, router

    provider, _, model = spec.partition(":")
    env = {"AI_FALLBACK_ENABLED": "false", "AI_PROVIDER": provider}
    if model:
        for tier in ("DEFAULT", "COMPLEX", "ECONOMY", "VISION"):
            env[f"{provider.upper()}_MODEL_{tier}"] = model
    saved = {k: os.environ.get(k) for k in env}

    def reset() -> None:
        get_settings.cache_clear()
        router.reset()
        health.reset()
        cache.clear()
        versioning.pipeline_version.cache_clear()

    os.environ.update(env)
    reset()
    try:
        yield
    finally:
        for k, v in saved.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v
        reset()


# --------------------------------------------------------------------------- seeding


def _eval_user(session) -> User:
    user = session.execute(select(User).where(func.lower(User.email) == EVAL_USER)).scalar_one_or_none()
    if user is None:
        from app.core.security import hash_password

        user = User(email=EVAL_USER, full_name="AI evaluation", password_hash=hash_password(secrets.token_urlsafe(24)),
                    is_platform_admin=True)
        session.add(user)
        session.flush()
    return user


def seed_company(seed: dict[str, Any], label: str) -> tuple[uuid.UUID, uuid.UUID]:
    from app.cli import admin_ctx
    from app.modules.inventory import service as inventory
    from app.modules.registry import available_module_codes
    from app.modules.supply import service as supply

    with SessionLocal() as s:
        user = _eval_user(s)
        s.commit()
        code = f"EVAL-{datetime.now(UTC):%m%d%H%M%S}-{secrets.token_hex(2)}".upper()
        company = identity.create_company(s, user, identity.CompanyIn(name=f"AI evaluation {label}", code=code,
                                                                      organization_code="BIWORK-EVAL", is_sample_data=True),
                                          available_module_codes())
        s.commit()
        ctx = admin_ctx(s, company.id, user.id)
        deps = {}
        for d in seed.get("departments", []):
            deps[d["code"]] = master_data.create_department(s, ctx, master_data.DepartmentIn(
                code=d["code"], name=d["name"], kind=d.get("kind", "office"), aliases=d.get("aliases", []))).id
        for p in seed.get("products", []):
            master_data.create_product(s, ctx, master_data.ProductIn(code=p["code"], name=p["name"], base_unit=p["unit"],
                                                                     product_type=p.get("type", "finished_good"),
                                                                     aliases=p.get("aliases", [])))
        for w in seed.get("warehouses", []):
            inventory.create_warehouse(s, ctx, inventory.WarehouseIn(code=w["code"], name=w["name"], kind=w.get("kind", "general")))
        for sup in seed.get("suppliers", []):
            supply.create_supplier(s, ctx, supply.SupplierIn(code=sup["code"], name=sup["name"], tin=sup.get("tin")))
        for e in seed.get("employees", []):
            personnel.create_employee(s, ctx, personnel.EmployeeIn(
                full_name=e["full_name"], personnel_number=e.get("personnel_number"), position=e.get("position"),
                department_id=deps.get(e.get("department"))))
        for d in seed.get("documents", []):
            documents.create_document(s, ctx, documents.DocumentIn(
                doc_type=d["doc_type"], number=d["number"], doc_date=date.fromisoformat(d["doc_date"]),
                counterparty=d.get("counterparty"), total_amount=Decimal(d["total_amount"]) if d.get("total_amount") else None,
                currency=d.get("currency")))
        s.commit()
        return company.id, user.id


# --------------------------------------------------------------------------- comparison


def _same(expected: Any, actual: Any) -> bool:
    if actual is None:
        return expected is None
    try:
        return Decimal(str(expected)) == Decimal(str(actual))
    except (InvalidOperation, ValueError):
        pass
    return normalize_key(str(expected)) == normalize_key(str(actual))


@dataclass
class CaseResult:
    id: str
    language: str
    record_type: str
    status: str
    detected_types: list[str]
    latency_ms: int
    fields_expected: int = 0
    fields_correct: int = 0
    mismatches: list[dict[str, Any]] = field(default_factory=list)
    review_group: str | None = None
    duplicate_expected: bool = False
    duplicate_flagged: bool = False
    false_acceptance: bool = False
    needs_correction: bool = False
    escalated: bool = False
    cost_usd: float | None = None
    error: str | None = None


def run_case(company_id: uuid.UUID, user_id: uuid.UUID, case: dict[str, Any]) -> CaseResult:
    from app.cli import admin_ctx

    started = time.monotonic()
    with SessionLocal() as s:
        ctx = admin_ctx(s, company_id, user_id)
        job = create_job(s, ctx, text=case["text"], force=True, channel="api", title=f"eval:{case['id']}")
        s.commit()
        job_id = job.id
    orchestrator.run_parse(company_id, job_id)
    error = None
    try:
        orchestrator.run_interpret(company_id, job_id)
        orchestrator.run_prepare(company_id, job_id)
    except Exception as exc:  # noqa: BLE001 - a provider failure is a measured outcome, not a crash
        error = f"{exc.__class__.__name__}: {exc}"
    latency = int((time.monotonic() - started) * 1000)

    with tenant_session(company_id) as s:
        job = s.get(IngestionJob, job_id)
        recs = s.execute(select(ExtractedRecord).where(ExtractedRecord.job_id == job_id).order_by(ExtractedRecord.seq)).scalars().all()
        cost = s.execute(select(func.sum(AICall.cost_usd)).where(AICall.job_id == job_id)).scalar()
        result = CaseResult(case["id"], case["language"], case["record_type"], job.status,
                            sorted({r.record_type for r in recs}), latency, cost_usd=float(cost) if cost is not None else None,
                            duplicate_expected=bool(case.get("duplicate")),
                            error=error or ((job.error or {}).get("message") if job.status == "failed" else None))
        expected = case.get("expected") or []
        of_type = [r for r in recs if r.record_type == case["record_type"]]
        for i, exp in enumerate(expected):
            rec = of_type[i] if i < len(of_type) else None
            wrong = False
            for name, value in exp.items():
                result.fields_expected += 1
                actual = (rec.payload or {}).get(name) if rec else None
                if _same(value, actual):
                    result.fields_correct += 1
                else:
                    wrong = True
                    result.mismatches.append({"field": name, "expected": value, "actual": actual})
            if rec is None:
                result.needs_correction = True
                continue
            result.review_group = rec.review_group
            result.escalated = result.escalated or bool((rec.ai_meta or {}).get("escalated"))
            flagged = s.execute(select(func.count()).where(
                MatchCandidate.record_id == rec.id, MatchCandidate.match_kind == "duplicate_record",
                MatchCandidate.score >= DUPLICATE_SCORE)).scalar() > 0
            result.duplicate_flagged = result.duplicate_flagged or flagged
            result.needs_correction = result.needs_correction or wrong
            should_not_pass = wrong or bool(case.get("duplicate")) or case.get("ready_ok") is False
            result.false_acceptance = result.false_acceptance or (rec.review_group == "ready" and should_not_pass)
        return result


# --------------------------------------------------------------------------- aggregation


def _ratio(a: float, b: float) -> float | None:
    return round(a / b, 4) if b else None


def summarize(spec: str, results: list[CaseResult], company_id: uuid.UUID) -> dict[str, Any]:
    with tenant_session(company_id) as s:
        statuses = dict(s.execute(select(AICall.status, func.count()).where(AICall.company_id == company_id)
                                  .group_by(AICall.status)).all())
        models = sorted({m for (m,) in s.execute(select(AICall.model).where(AICall.company_id == company_id).distinct())})
        is_demo = bool(s.execute(select(func.bool_or(AICall.is_mock)).where(AICall.company_id == company_id)).scalar())
    latencies = sorted(r.latency_ms for r in results)
    costs = [r.cost_usd for r in results if r.cost_usd is not None]
    total_cost = round(sum(costs), 6) if costs else None
    validated = [r for r in results if not r.needs_correction and r.review_group not in (None, "needs_correction")
                 and not r.duplicate_expected and r.status != "failed"]
    dup_tp = sum(1 for r in results if r.duplicate_flagged and r.duplicate_expected)
    dup_flagged = sum(1 for r in results if r.duplicate_flagged)
    dup_expected = sum(1 for r in results if r.duplicate_expected)
    ready = [r for r in results if r.review_group == "ready"]
    by_language: dict[str, dict[str, Any]] = {}
    for r in results:
        b = by_language.setdefault(r.language, {"cases": 0, "fields_expected": 0, "fields_correct": 0})
        b["cases"] += 1
        b["fields_expected"] += r.fields_expected
        b["fields_correct"] += r.fields_correct
    for b in by_language.values():
        b["field_accuracy"] = _ratio(b["fields_correct"], b["fields_expected"])
    schema_total = statuses.get("ok", 0) + statuses.get("invalid_output", 0)
    return {
        "provider": spec, "models": models, "demo": is_demo, "cases": len(results),
        "failed_jobs": sum(1 for r in results if r.status == "failed"),
        "record_type_accuracy": _ratio(sum(1 for r in results if r.record_type in r.detected_types), len(results)),
        "field_accuracy": _ratio(sum(r.fields_correct for r in results), sum(r.fields_expected for r in results)),
        "schema_validity": _ratio(statuses.get("ok", 0), schema_total),
        "duplicate_precision": _ratio(dup_tp, dup_flagged), "duplicate_recall": _ratio(dup_tp, dup_expected),
        "false_acceptance_rate": _ratio(sum(1 for r in ready if r.false_acceptance), len(ready)),
        "human_correction_rate": _ratio(sum(1 for r in results if r.needs_correction), len(results)),
        "escalated_cases": sum(1 for r in results if r.escalated),
        "latency_ms": {"avg": round(sum(latencies) / len(latencies)) if latencies else None,
                       "p95": latencies[int(0.95 * (len(latencies) - 1))] if latencies else None},
        "cost_usd": total_cost,
        "cost_per_document_usd": _ratio(total_cost, len(results)) if total_cost is not None else None,
        "cost_per_validated_record_usd": _ratio(total_cost, len(validated)) if total_cost is not None else None,
        "ai_call_statuses": statuses,
        "by_language": by_language,
        "cases_detail": [r.__dict__ for r in results],
    }


def evaluate(spec: str, dataset: Path = DEFAULT_DATASET, case_ids: list[str] | None = None) -> dict[str, Any]:
    seed = json.loads((dataset / "seed.json").read_text(encoding="utf-8"))
    cases = json.loads((dataset / "cases.json").read_text(encoding="utf-8"))["cases"]
    if case_ids:
        cases = [c for c in cases if c["id"] in case_ids]
    with provider_settings(spec):
        company_id, user_id = seed_company(seed, spec)
        results = [run_case(company_id, user_id, case) for case in cases]
        return summarize(spec, results, company_id)


def markdown(reports: list[dict[str, Any]]) -> str:
    def pct(v: float | None) -> str:
        return "—" if v is None else f"{v * 100:.1f}%"

    def usd(v: float | None) -> str:
        return "—" if v is None else f"${v:.4f}"

    lines = ["# BIWORK Agent — provider evaluation", "",
             f"Generated {datetime.now(UTC):%Y-%m-%d %H:%M} UTC on the synthetic dataset in `backend/eval/dataset`.", ""]
    if any(r["demo"] for r in reports):
        lines += ["> **Demo mode rows are not AI results.** They measure the rule-based stand-in used when no provider "
                  "credentials are configured.", ""]
    lines += ["| Provider / model | Type acc. | Field acc. | Schema valid | Dup. precision | Dup. recall | False accept | "
              "Correction rate | Avg latency | Cost / doc | Cost / validated record |",
              "|---|---|---|---|---|---|---|---|---|---|---|"]
    for r in reports:
        name = f"{r['provider']} ({', '.join(r['models'])})" + (" — DEMO" if r["demo"] else "")
        lines.append(f"| {name} | {pct(r['record_type_accuracy'])} | {pct(r['field_accuracy'])} | {pct(r['schema_validity'])} | "
                     f"{pct(r['duplicate_precision'])} | {pct(r['duplicate_recall'])} | {pct(r['false_acceptance_rate'])} | "
                     f"{pct(r['human_correction_rate'])} | {r['latency_ms']['avg']} ms | {usd(r['cost_per_document_usd'])} | "
                     f"{usd(r['cost_per_validated_record_usd'])} |")
    lines += ["", "## Field accuracy by language", "", "| Provider | " + " | ".join(sorted(reports[0]["by_language"])) + " |",
              "|---|" + "---|" * len(reports[0]["by_language"])]
    for r in reports:
        lines.append(f"| {r['provider']} | " + " | ".join(pct(r["by_language"][lang]["field_accuracy"])
                                                         for lang in sorted(reports[0]["by_language"])) + " |")
    lines += ["", "## Mismatches", ""]
    for r in reports:
        for c in r["cases_detail"]:
            if c["mismatches"] or c["error"]:
                detail = "; ".join(f"{m['field']}: expected {m['expected']!r}, got {m['actual']!r}" for m in c["mismatches"])
                lines.append(f"- **{r['provider']}** `{c['id']}`: {c['error'] or detail}")
    return "\n".join(lines) + "\n"


def main(dataset: str | None, providers: list[str] | None, out: str | None) -> int:
    path = Path(dataset) if dataset else DEFAULT_DATASET
    specs = providers or get_settings().configured_ai_providers() or ["demo"]
    reports = [evaluate(spec, path) for spec in specs]
    text = json.dumps({"generated_at": datetime.now(UTC).isoformat(), "dataset": str(path), "reports": reports},
                      indent=2, ensure_ascii=False, default=str)
    md = markdown(reports)
    if out:
        Path(out).write_text(text, encoding="utf-8")
        Path(out).with_suffix(".md").write_text(md, encoding="utf-8")
        print(f"Wrote {out} and {Path(out).with_suffix('.md')}")
    print(md)
    return 0
