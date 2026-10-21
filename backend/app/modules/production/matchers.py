"""Existing-record detection and entity resolvers for production."""

from __future__ import annotations

from collections.abc import Iterable
from datetime import date

from sqlalchemy import select

from app.core.manifest import DuplicateCandidate, RuleInput
from app.core.matching import ListResolver, register_resolver
from app.core.text import fmt_qty
from app.modules.production.models import ProductionLine, ProductionOrder, Shift
from app.modules.production.service import find_live_report


def match_production_report(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    product, department = inp.ref_id("product"), inp.ref_id("department")
    if not (product and department and inp.payload.get("production_date")):
        return
    existing = find_live_report(inp.session, inp.company_id, date.fromisoformat(inp.payload["production_date"]),
                                product, department, inp.ref_id("shift"))
    if existing:
        yield DuplicateCandidate(
            "production_report", existing.id,
            f"{existing.number}: {fmt_qty(existing.qty_produced)} {existing.unit.code} on {existing.production_date}",
            1.0, details={"qty_produced": str(existing.qty_produced), "qty_accepted": str(existing.qty_accepted),
                          "qty_defective": str(existing.qty_defective), "status": existing.status},
        )


def match_production_order(inp: RuleInput) -> Iterable[DuplicateCandidate]:
    ext = inp.payload.get("external_number")
    if ext:
        for o in inp.session.execute(select(ProductionOrder).where(
            ProductionOrder.company_id == inp.company_id, ProductionOrder.external_number == ext,
            ProductionOrder.status != "cancelled",
        )).scalars():
            yield DuplicateCandidate("production_order", o.id, f"{o.number} (external {ext})", 1.0)


register_resolver(ListResolver(
    entity_type="shift",
    load=lambda s, cid: s.execute(select(Shift).where(Shift.company_id == cid, Shift.is_active.is_(True))).scalars().all(),
    label=lambda sh: sh.name, keys=lambda sh: [sh.code, sh.name, f"{sh.code} smena", f"{sh.code}-smena", f"смена {sh.code}"],
))
register_resolver(ListResolver(
    entity_type="production_line",
    load=lambda s, cid: s.execute(select(ProductionLine).where(ProductionLine.company_id == cid, ProductionLine.is_active.is_(True))).scalars().all(),
    label=lambda line: f"{line.name} ({line.code})", keys=lambda line: [line.code, line.name],
))
register_resolver(ListResolver(
    entity_type="production_order",
    load=lambda s, cid: s.execute(select(ProductionOrder).where(
        ProductionOrder.company_id == cid, ProductionOrder.status.notin_(("cancelled",)))).scalars().all(),
    label=lambda o: f"{o.number} — {o.product.code} {fmt_qty(o.planned_qty)} {o.unit.code}",
    keys=lambda o: [o.number, o.external_number or ""],
    data=lambda o: {"product_id": str(o.product_id), "status": o.status},
    fuzzy=False,
))
