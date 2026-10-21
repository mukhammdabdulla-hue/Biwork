"""The evaluation runner works end to end (demo provider, a subset of the synthetic dataset)."""

from __future__ import annotations

import json

from app.agent.evaluation import DEFAULT_DATASET, evaluate, markdown


def test_dataset_covers_languages_and_entities():
    cases = json.loads((DEFAULT_DATASET / "cases.json").read_text(encoding="utf-8"))["cases"]
    assert {"uz-Latn", "uz-Cyrl", "ru", "en"} <= {c["language"] for c in cases}
    assert {"product", "production_order", "production_report", "stock_movement", "employee", "supplier", "purchase_request",
            "business_document", "task"} <= {c["record_type"] for c in cases}
    assert any(c.get("duplicate") for c in cases) and len({c["id"] for c in cases}) == len(cases)


def test_evaluation_runner_measures_the_pipeline():
    report = evaluate("demo", case_ids=["pr-ru-1", "sup-ru-dup", "doc-uz-mismatch", "emp-uz-1"])
    assert report["demo"] is True and report["cases"] == 4 and report["failed_jobs"] == 0
    assert report["record_type_accuracy"] == 1.0 and report["field_accuracy"] > 0.8
    assert report["schema_validity"] == 1.0
    assert report["duplicate_recall"] == 1.0 and report["duplicate_precision"] == 1.0
    mismatch = next(c for c in report["cases_detail"] if c["id"] == "doc-uz-mismatch")
    assert mismatch["review_group"] == "needs_correction" and not mismatch["false_acceptance"]
    md = markdown([report])
    assert "DEMO" in md and "not AI results" in md
