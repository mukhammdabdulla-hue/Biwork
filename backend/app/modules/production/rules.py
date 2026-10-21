"""Deterministic business rules for production record types (run after entity matching)."""

from __future__ import annotations

from collections.abc import Iterable
from datetime import date

from sqlalchemy import select

from app.core.manifest import Issue, RuleInput
from app.core.rules import date_plausible, today_for, unit_compatible  # noqa: F401  (re-exported for the manifest)
from app.core.text import fmt_qty
from app.modules.production.models import ProductionOrder


def quantities_reconcile(inp: RuleInput) -> Iterable[Issue]:
    produced, accepted, defective = inp.decimal("qty_produced"), inp.decimal("qty_accepted"), inp.decimal("qty_defective")
    if produced is None:
        return
    for name, value in (("qty_produced", produced), ("qty_accepted", accepted), ("qty_defective", defective)):
        if value is not None and value < 0:
            yield Issue("quantity_negative", "error", "Quantity cannot be negative", name)
    if accepted is not None and defective is not None:
        total = accepted + defective
        if total > produced:
            yield Issue("quantities_exceed_produced", "error",
                        f"Accepted ({fmt_qty(accepted)}) + defective ({fmt_qty(defective)}) = {fmt_qty(total)} "
                        f"exceeds produced ({fmt_qty(produced)})", "qty_produced",
                        {"produced": str(produced), "accepted": str(accepted), "defective": str(defective)})
        elif total < produced:
            yield Issue("quantities_not_reconciled", "warning",
                        f"{fmt_qty(produced - total)} of {fmt_qty(produced)} units are neither accepted nor "
                        "defective (rework or inspection pending?)", "qty_produced")
        else:
            yield Issue("quantities_reconciled", "info",
                        f"Accepted + defective = produced ({fmt_qty(produced)})", "qty_produced")
    elif accepted is not None and accepted > produced:
        yield Issue("quantities_exceed_produced", "error", "Accepted quantity exceeds produced quantity", "qty_accepted")
    elif defective is not None and defective > produced:
        yield Issue("quantities_exceed_produced", "error", "Defective quantity exceeds produced quantity", "qty_defective")
    elif accepted is None and defective is None:
        yield Issue("quality_not_reported", "info", "Accepted/defective quantities were not reported", "qty_accepted")


def department_is_workshop(inp: RuleInput) -> Iterable[Issue]:
    ref = inp.references.get("department")
    if ref and ref.get("data", {}).get("kind") not in (None, "workshop"):
        yield Issue("not_a_workshop", "error", f"{ref['label']} is not a production unit (workshop)", "department")


def order_consistent(inp: RuleInput) -> Iterable[Issue]:
    order_id = inp.ref_id("order")
    if not order_id:
        return
    order = inp.session.get(ProductionOrder, order_id)
    if order is None:
        return
    if inp.ref_id("product") and order.product_id != inp.ref_id("product"):
        yield Issue("order_product_mismatch", "error", f"Order {order.number} is for a different product", "order")
    if inp.ref_id("department") and order.department_id and order.department_id != inp.ref_id("department"):
        yield Issue("order_department_mismatch", "warning", f"Order {order.number} is planned for another production unit", "order")
    if order.status in ("completed", "cancelled"):
        yield Issue("order_closed", "warning", f"Order {order.number} is {order.status}", "order")


def order_dates(inp: RuleInput) -> Iterable[Issue]:
    start, end = inp.payload.get("planned_start"), inp.payload.get("planned_end")
    if start and end and date.fromisoformat(end) < date.fromisoformat(start):
        yield Issue("end_before_start", "error", "Planned end is before planned start", "planned_end")
    if end and date.fromisoformat(end) < today_for(inp):
        yield Issue("deadline_passed", "warning", f"Planned end {end} is already in the past", "planned_end")


def open_orders_for(inp: RuleInput) -> Iterable[Issue]:
    """Suggest linking a report to the single open order for the same product and unit."""
    if inp.ref_id("order") or not inp.ref_id("product") or not inp.payload.get("production_date"):
        return
    d = date.fromisoformat(inp.payload["production_date"])
    orders = inp.session.execute(
        select(ProductionOrder).where(
            ProductionOrder.company_id == inp.company_id, ProductionOrder.product_id == inp.ref_id("product"),
            ProductionOrder.status.in_(("released", "in_progress")),
            (ProductionOrder.planned_start.is_(None)) | (ProductionOrder.planned_start <= d),
            (ProductionOrder.planned_end.is_(None)) | (ProductionOrder.planned_end >= d),
        )
    ).scalars().all()
    if len(orders) == 1:
        yield Issue("open_order_available", "info", f"Open order {orders[0].number} matches this product and date; "
                    "set the Order field to link it", "order", {"order_id": str(orders[0].id), "number": orders[0].number})
