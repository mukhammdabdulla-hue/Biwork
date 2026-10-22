"""AI usage monitoring (docs/ai-engine.md §Usage monitoring).

Every AI attempt is an `agent.ai_calls` row: provider, model, task type, routing tier and reason,
tokens, estimated cost, duration, retry number, fallback, status, and (after validation) the
extraction outcome. This service aggregates those rows per company and relates cost to results:
cost per processed document and cost per successfully validated (committed) record.
"""

from __future__ import annotations

import uuid
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.agent.models import AICall, ExtractedRecord, ExtractionFeedback, IngestionItem


def _f(value: Any) -> float | None:
    return float(value) if isinstance(value, Decimal | int | float) else None


def usage_report(db: Session, company_id: uuid.UUID, days: int = 30) -> dict[str, Any]:
    since = datetime.now(UTC) - timedelta(days=days)
    scope = (AICall.company_id == company_id, AICall.created_at >= since)
    by_model = db.execute(select(
        AICall.provider, AICall.model, AICall.task, AICall.tier, AICall.is_mock,
        func.count(), func.count().filter(AICall.status == "ok"), func.count().filter(AICall.status == "cached"),
        func.count().filter(AICall.status.in_(("error", "refused", "invalid_output"))),
        func.count().filter(AICall.attempt > 1), func.count().filter(AICall.fallback_from.is_not(None)),
        func.coalesce(func.sum(AICall.input_tokens), 0), func.coalesce(func.sum(AICall.output_tokens), 0),
        func.sum(AICall.cost_usd), func.avg(AICall.latency_ms),
        func.percentile_cont(0.95).within_group(AICall.latency_ms),
        func.count().filter(AICall.cost_usd.is_(None) & (AICall.status == "ok")),
    ).where(*scope).group_by(AICall.provider, AICall.model, AICall.task, AICall.tier, AICall.is_mock)
        .order_by(AICall.provider, AICall.model, AICall.task)).all()

    rows = []
    for (provider, model, task, tier, is_mock, calls, ok, cached, failed, retries, fallbacks, tin, tout, cost, avg_ms, p95_ms,
         unpriced) in by_model:
        rows.append({
            "provider": provider, "model": model, "task": task, "tier": tier, "demo": is_mock, "calls": calls, "succeeded": ok,
            "cached": cached, "failed": failed, "retries": retries, "fallbacks": fallbacks, "input_tokens": int(tin),
            "output_tokens": int(tout), "cost_usd": _f(cost), "unpriced_calls": unpriced,
            "avg_latency_ms": round(float(avg_ms)) if avg_ms is not None else None,
            "p95_latency_ms": round(float(p95_ms)) if p95_ms is not None else None,
        })

    total_cost = db.execute(select(func.sum(AICall.cost_usd)).where(*scope)).scalar()
    documents = db.execute(select(func.count(func.distinct(AICall.item_id))).where(*scope, AICall.task == "extract")).scalar() or 0
    record_scope = (ExtractedRecord.company_id == company_id, ExtractedRecord.created_at >= since)
    ai_records = select(ExtractedRecord.id).join(IngestionItem, IngestionItem.id == ExtractedRecord.item_id).where(
        *record_scope, ExtractedRecord.ai_meta["provider"].is_not(None))
    by_status = dict(db.execute(select(ExtractedRecord.status, func.count()).where(ExtractedRecord.id.in_(ai_records))
                                .group_by(ExtractedRecord.status)).all())
    by_group = dict(db.execute(select(ExtractedRecord.review_group, func.count()).where(ExtractedRecord.id.in_(ai_records))
                               .group_by(ExtractedRecord.review_group)).all())
    escalated = db.execute(select(func.count()).where(
        ExtractedRecord.id.in_(ai_records), ExtractedRecord.ai_meta["escalated"].as_boolean().is_(True))).scalar() or 0
    corrected_records = db.execute(select(func.count(func.distinct(ExtractionFeedback.record_id))).where(
        ExtractionFeedback.company_id == company_id, ExtractionFeedback.record_id.in_(ai_records))).scalar() or 0
    decided = sum(by_status.get(s, 0) for s in ("approved", "committed", "rejected"))
    committed = by_status.get("committed", 0)
    total_records = sum(by_status.values())
    return {
        "period_days": days,
        "by_model": rows,
        "totals": {
            "calls": sum(r["calls"] for r in rows), "failed": sum(r["failed"] for r in rows),
            "retries": sum(r["retries"] for r in rows), "fallbacks": sum(r["fallbacks"] for r in rows),
            "cached": sum(r["cached"] for r in rows), "input_tokens": sum(r["input_tokens"] for r in rows),
            "output_tokens": sum(r["output_tokens"] for r in rows), "cost_usd": _f(total_cost),
            "documents": documents, "records": total_records, "records_by_status": by_status, "records_by_group": by_group,
            "escalated_records": escalated, "corrected_records": corrected_records,
            "human_correction_rate": round(corrected_records / decided, 3) if decided else None,
            "cost_per_document_usd": round(float(total_cost) / documents, 6) if total_cost is not None and documents else None,
            "cost_per_validated_record_usd": round(float(total_cost) / committed, 6) if total_cost is not None and committed else None,
            "demo_calls": sum(r["calls"] for r in rows if r["demo"]),
        },
        "notes": ["cost_usd is an estimate from list prices (AI_PRICING_JSON); calls to models without a configured price "
                  "are counted in unpriced_calls and excluded from cost totals",
                  *(["demo-mode calls are simulated and cost nothing"] if any(r["demo"] for r in rows) else [])],
    }

