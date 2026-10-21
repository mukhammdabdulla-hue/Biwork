"""Analytics: read-only aggregation over module data (docs/architecture.md §3.4). Never writes."""

from __future__ import annotations

from collections import defaultdict
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal
from typing import Any

from sqlalchemy import Date, cast, func, select
from sqlalchemy.orm import Session

from app.agent.models import AICall, Approval, ExtractedRecord, ExtractionFeedback, IngestionJob, StageRun
from app.core.context import RequestContext
from app.core.models import AuditEvent, Company, Department, Product, Task, Unit, User
from app.modules.inventory.service import shortages
from app.modules.production.models import ProductionOrder, ProductionReport
from app.modules.production.service import plan_vs_actual
from app.modules.supply.models import PurchaseRequest


def _f(value: Any) -> float:
    return float(value) if isinstance(value, Decimal) else float(value or 0)


def _pct(part: float, whole: float) -> float | None:
    return round(part / whole * 100, 1) if whole else None


def _delta(current: float, previous: float) -> float | None:
    return round((current - previous) / previous * 100, 1) if previous else None


def _reports(ctx: RequestContext):
    stmt = select(ProductionReport).where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved")
    if ctx.department_ids is not None:
        stmt = stmt.where(ProductionReport.department_id.in_(ctx.department_ids))
    return stmt


def _primary_unit(db: Session, ctx: RequestContext, start: date, end: date) -> str | None:
    row = db.execute(select(Unit.code, func.sum(ProductionReport.qty_produced).label("q"))
                     .join(ProductionReport, ProductionReport.unit_id == Unit.id)
                     .where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved",
                            ProductionReport.production_date.between(start, end))
                     .group_by(Unit.code).order_by(func.count().desc())).first()
    return row[0] if row else None


def _production_totals(db: Session, ctx: RequestContext, start: date, end: date, unit: str | None) -> dict[str, float]:
    stmt = (select(func.sum(ProductionReport.qty_produced), func.sum(func.coalesce(ProductionReport.qty_accepted, 0)),
                   func.sum(func.coalesce(ProductionReport.qty_defective, 0)))
            .join(Unit, Unit.id == ProductionReport.unit_id)
            .where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved",
                   ProductionReport.production_date.between(start, end)))
    if unit:
        stmt = stmt.where(Unit.code == unit)
    if ctx.department_ids is not None:
        stmt = stmt.where(ProductionReport.department_id.in_(ctx.department_ids))
    produced, accepted, defective = db.execute(stmt).one()
    return {"produced": _f(produced), "accepted": _f(accepted), "defective": _f(defective)}


def overview(db: Session, ctx: RequestContext, days: int = 30) -> dict[str, Any]:
    today = date.today()
    start, prev_start = today - timedelta(days=days - 1), today - timedelta(days=2 * days - 1)
    prev_end = start - timedelta(days=1)
    company = db.get(Company, ctx.company_id)
    unit = _primary_unit(db, ctx, start, today)
    cur = _production_totals(db, ctx, start, today, unit)
    prev = _production_totals(db, ctx, prev_start, prev_end, unit)

    pva = plan_vs_actual(db, ctx, start, today + timedelta(days=days))
    in_scope = [o for o in pva if o["status"] in ("released", "in_progress", "completed")]
    planned = sum(_f(o["planned_qty"]) for o in in_scope)
    actual = sum(min(_f(o["actual_qty"]), _f(o["planned_qty"])) for o in in_scope)
    order_counts = dict(db.execute(select(ProductionOrder.status, func.count()).where(ProductionOrder.company_id == ctx.company_id)
                                   .group_by(ProductionOrder.status)).all())
    shortage_list = shortages(db, ctx.company_id) if ctx.has("inventory.read") else []
    overdue_q = select(Task).where(Task.company_id == ctx.company_id, Task.due_date < today, Task.status.in_(("open", "in_progress")))
    overdue = db.execute(overdue_q.order_by(Task.due_date)).scalars().all()
    pending_records = db.execute(select(func.count()).select_from(ExtractedRecord).where(
        ExtractedRecord.company_id == ctx.company_id, ExtractedRecord.status.in_(("needs_review", "approved")))).scalar_one()
    pending_prs = db.execute(select(func.count()).select_from(PurchaseRequest).where(
        PurchaseRequest.company_id == ctx.company_id, PurchaseRequest.status == "submitted")).scalar_one()
    draft_reports = db.execute(select(func.count()).select_from(ProductionReport).where(
        ProductionReport.company_id == ctx.company_id, ProductionReport.status == "draft")).scalar_one()
    job_stats = dict(db.execute(select(IngestionJob.status, func.count()).where(
        IngestionJob.company_id == ctx.company_id, IngestionJob.created_at >= datetime.combine(start, datetime.min.time(), UTC)
    ).group_by(IngestionJob.status)).all())
    ok_jobs = sum(v for k, v in job_stats.items() if k in ("awaiting_review", "completed", "committing"))
    failed_jobs = job_stats.get("failed", 0)
    failed_now = db.execute(select(IngestionJob).where(IngestionJob.company_id == ctx.company_id, IngestionJob.status == "failed")
                            .order_by(IngestionJob.created_at.desc()).limit(5)).scalars().all()

    # Charts
    trend_rows = db.execute(
        select(ProductionReport.production_date, func.sum(ProductionReport.qty_produced),
               func.sum(func.coalesce(ProductionReport.qty_accepted, 0)), func.sum(func.coalesce(ProductionReport.qty_defective, 0)))
        .join(Unit, Unit.id == ProductionReport.unit_id)
        .where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved",
               ProductionReport.production_date.between(start, today), Unit.code == unit)
        .group_by(ProductionReport.production_date).order_by(ProductionReport.production_date)).all() if unit else []
    by_day = {d: (p, a, x) for d, p, a, x in trend_rows}
    trend = []
    for i in range(days):
        d = start + timedelta(days=i)
        p, a, x = by_day.get(d, (0, 0, 0))
        trend.append({"date": d.isoformat(), "produced": _f(p), "accepted": _f(a), "defective": _f(x)})
    by_product = db.execute(
        select(Product.code, Product.name, Unit.code, func.sum(ProductionReport.qty_produced), func.sum(func.coalesce(ProductionReport.qty_defective, 0)))
        .join(Product, Product.id == ProductionReport.product_id).join(Unit, Unit.id == ProductionReport.unit_id)
        .where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved",
               ProductionReport.production_date.between(start, today))
        .group_by(Product.code, Product.name, Unit.code).order_by(func.sum(ProductionReport.qty_produced).desc()).limit(8)).all()
    by_department = db.execute(
        select(Department.name, func.sum(ProductionReport.qty_produced), func.sum(func.coalesce(ProductionReport.qty_defective, 0)))
        .join(Department, Department.id == ProductionReport.department_id)
        .where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved",
               ProductionReport.production_date.between(start, today))
        .group_by(Department.name).order_by(Department.name)).all()

    alerts: list[dict[str, Any]] = []
    for s in shortage_list[:5]:
        alerts.append({"severity": "warning", "kind": "shortage", "title": f"Low stock: {s['code']} {s['name']}",
                       "detail": f"{s['on_hand']} {s['unit']} on hand, reorder level {s['reorder_level']}", "link": "/inventory?tab=shortages"})
    for o in pva:
        if o["planned_end"] and o["planned_end"] < today and o["status"] not in ("completed", "cancelled"):
            alerts.append({"severity": "danger", "kind": "order_late", "title": f"Order {o['number']} is past its deadline",
                           "detail": f"{o['completion_pct'] or 0}% complete, planned end {o['planned_end']}", "link": "/production?tab=orders"})
    for t in overdue[:5]:
        alerts.append({"severity": "warning", "kind": "task_overdue", "title": f"Overdue task {t.number}",
                       "detail": f"{t.title} (due {t.due_date})", "link": "/tasks"})
    for j in failed_now:
        alerts.append({"severity": "danger", "kind": "job_failed", "title": "BIWORK Agent job failed",
                       "detail": f"{j.title or j.id}: {(j.error or {}).get('message', '')[:120]}", "link": f"/agent/jobs/{j.id}"})
    week = today - timedelta(days=6)
    for code, produced, defective in db.execute(
        select(Product.code, func.sum(ProductionReport.qty_produced), func.sum(func.coalesce(ProductionReport.qty_defective, 0)))
        .join(Product, Product.id == ProductionReport.product_id)
        .where(ProductionReport.company_id == ctx.company_id, ProductionReport.status == "approved",
               ProductionReport.production_date >= week).group_by(Product.code)).all():
        rate = _pct(_f(defective), _f(produced))
        if rate is not None and rate >= 5:
            alerts.append({"severity": "warning", "kind": "defect_rate", "title": f"High defect rate for {code}",
                           "detail": f"{rate}% defective over the last 7 days", "link": "/quality"})

    activity = []
    if ctx.has("audit.read"):
        events = db.execute(select(AuditEvent).where(AuditEvent.company_id == ctx.company_id).order_by(AuditEvent.id.desc()).limit(15)).scalars().all()
        names = dict(db.execute(select(User.id, User.full_name).where(User.id.in_({e.actor_id for e in events if e.actor_id})) ).all()) if events else {}
        activity = [{"at": e.occurred_at, "action": e.action, "summary": e.summary, "actor": names.get(e.actor_id) or e.actor_type,
                     "entity_type": e.entity_type, "entity_id": e.entity_id} for e in events]

    return {
        "is_sample_data": company.is_sample_data, "period": {"from": start, "to": today, "days": days}, "unit": unit,
        "kpis": {
            "total_production": {"value": cur["produced"], "unit": unit, "delta_pct": _delta(cur["produced"], prev["produced"])},
            "plan_completion": {"value": _pct(actual, planned), "orders": len(in_scope)},
            "active_orders": {"value": order_counts.get("released", 0) + order_counts.get("in_progress", 0),
                              "planned": order_counts.get("planned", 0)},
            "inventory_shortages": {"value": len(shortage_list)},
            "overdue_tasks": {"value": len(overdue)},
            "pending_approvals": {"value": pending_records + pending_prs + draft_reports, "agent_records": pending_records,
                                  "purchase_requests": pending_prs, "draft_reports": draft_reports},
            "ingestion_success_rate": {"value": _pct(ok_jobs, ok_jobs + failed_jobs), "jobs": ok_jobs + failed_jobs},
            "failed_jobs": {"value": failed_jobs, "currently_failed": len(failed_now)},
            "defect_rate": {"value": _pct(cur["defective"], cur["produced"]), "delta_pct": _delta(
                _pct(cur["defective"], cur["produced"]) or 0, _pct(prev["defective"], prev["produced"]) or 0)},
        },
        "charts": {
            "production_trend": trend,
            "by_product": [{"code": c, "name": n, "unit": u, "produced": _f(p), "defective": _f(x), "defect_rate": _pct(_f(x), _f(p))}
                           for c, n, u, p, x in by_product],
            "by_department": [{"name": n, "produced": _f(p), "defective": _f(x)} for n, p, x in by_department],
            "plan_vs_actual": [{**o, "planned_qty": _f(o["planned_qty"]), "actual_qty": _f(o["actual_qty"])}
                               for o in pva if o["status"] in ("released", "in_progress")][:8],
        },
        "alerts": alerts[:12],
        "recent_activity": activity,
    }


def agent_stats(db: Session, ctx: RequestContext, days: int = 30) -> dict[str, Any]:
    since = datetime.now(UTC) - timedelta(days=days)
    jobs = db.execute(select(IngestionJob.status, IngestionJob.source_kind, cast(IngestionJob.created_at, Date))
                      .where(IngestionJob.company_id == ctx.company_id, IngestionJob.created_at >= since)).all()
    by_status: dict[str, int] = defaultdict(int)
    by_kind: dict[str, int] = defaultdict(int)
    by_day: dict[str, dict[str, int]] = defaultdict(lambda: defaultdict(int))
    for status, kind, day in jobs:
        by_status[status] += 1
        by_kind[kind] += 1
        bucket = "failed" if status == "failed" else ("completed" if status == "completed" else "in_review")
        by_day[day.isoformat()][bucket] += 1
    ok = sum(v for k, v in by_status.items() if k in ("awaiting_review", "completed", "committing"))
    records = dict(db.execute(select(ExtractedRecord.status, func.count()).join(IngestionJob, IngestionJob.id == ExtractedRecord.job_id)
                              .where(ExtractedRecord.company_id == ctx.company_id, IngestionJob.created_at >= since)
                              .group_by(ExtractedRecord.status)).all())
    groups = dict(db.execute(select(ExtractedRecord.review_group, func.count()).where(
        ExtractedRecord.company_id == ctx.company_id, ExtractedRecord.status == "needs_review").group_by(ExtractedRecord.review_group)).all())
    decisions: dict[str, int] = defaultdict(int)
    for auto, decision, n in db.execute(select(Approval.auto, Approval.decision, func.count())
                                        .where(Approval.company_id == ctx.company_id, Approval.decided_at >= since)
                                        .group_by(Approval.auto, Approval.decision)).all():
        decisions["auto_approved" if auto else decision] += n
    stage_times = db.execute(select(StageRun.stage, func.avg(StageRun.duration_ms), func.count().filter(StageRun.status == "failed"))
                             .where(StageRun.company_id == ctx.company_id, StageRun.started_at >= since)
                             .group_by(StageRun.stage)).all()
    ai = db.execute(select(AICall.task, AICall.model, func.count(), func.coalesce(func.sum(AICall.input_tokens), 0),
                           func.coalesce(func.sum(AICall.output_tokens), 0), func.coalesce(func.sum(AICall.cost_usd), 0),
                           func.count().filter(AICall.status != "ok"), func.avg(AICall.latency_ms))
                    .where(AICall.company_id == ctx.company_id, AICall.created_at >= since).group_by(AICall.task, AICall.model)).all()
    corrected = db.execute(select(ExtractionFeedback.record_type, ExtractionFeedback.field, func.count())
                           .where(ExtractionFeedback.company_id == ctx.company_id, ExtractionFeedback.created_at >= since)
                           .group_by(ExtractionFeedback.record_type, ExtractionFeedback.field)
                           .order_by(func.count().desc()).limit(10)).all()
    return {
        "period_days": days, "jobs_total": len(jobs), "jobs_by_status": dict(by_status), "jobs_by_source": dict(by_kind),
        "success_rate": _pct(ok, ok + by_status.get("failed", 0)),
        "jobs_by_day": [{"date": d, **v} for d, v in sorted(by_day.items())],
        "records_by_status": records, "review_queue": groups, "decisions": dict(decisions),
        "stages": [{"stage": s, "avg_ms": round(_f(a)), "failures": f} for s, a, f in stage_times],
        "ai_usage": [{"task": t, "model": m, "calls": c, "input_tokens": int(i), "output_tokens": int(o), "cost_usd": _f(cost),
                      "errors": e, "avg_latency_ms": round(_f(lat))} for t, m, c, i, o, cost, e, lat in ai],
        "most_corrected_fields": [{"record_type": rt, "field": f, "corrections": n} for rt, f, n in corrected],
    }


def quality(db: Session, ctx: RequestContext, days: int = 30) -> dict[str, Any]:
    today = date.today()
    start = today - timedelta(days=days - 1)
    base = _reports(ctx).where(ProductionReport.production_date.between(start, today)).subquery()
    by_product = db.execute(select(Product.code, Product.name, func.sum(base.c.qty_produced), func.sum(func.coalesce(base.c.qty_defective, 0)),
                                   func.count()).join(Product, Product.id == base.c.product_id)
                            .group_by(Product.code, Product.name).order_by(Product.code)).all()
    by_dep = db.execute(select(Department.name, func.sum(base.c.qty_produced), func.sum(func.coalesce(base.c.qty_defective, 0)))
                        .join(Department, Department.id == base.c.department_id).group_by(Department.name)).all()
    daily = db.execute(select(base.c.production_date, func.sum(base.c.qty_produced), func.sum(func.coalesce(base.c.qty_defective, 0)))
                       .group_by(base.c.production_date).order_by(base.c.production_date)).all()
    worst = db.execute(select(base.c.id, base.c.number, base.c.production_date, Product.code, base.c.qty_produced, base.c.qty_defective)
                       .join(Product, Product.id == base.c.product_id).where(base.c.qty_produced > 0)
                       .order_by((func.coalesce(base.c.qty_defective, 0) / base.c.qty_produced).desc()).limit(10)).all()
    return {
        "period": {"from": start, "to": today},
        "by_product": [{"code": c, "name": n, "produced": _f(p), "defective": _f(x), "reports": r, "defect_rate": _pct(_f(x), _f(p))}
                       for c, n, p, x, r in by_product],
        "by_department": [{"name": n, "produced": _f(p), "defective": _f(x), "defect_rate": _pct(_f(x), _f(p))} for n, p, x in by_dep],
        "daily": [{"date": d.isoformat(), "produced": _f(p), "defective": _f(x), "defect_rate": _pct(_f(x), _f(p))} for d, p, x in daily],
        "worst_reports": [{"id": str(i), "number": num, "date": d, "product": code, "produced": _f(p), "defective": _f(x),
                           "defect_rate": _pct(_f(x), _f(p))} for i, num, d, code, p, x in worst],
        "source": "Defect quantities recorded on approved production reports",
    }
