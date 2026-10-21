"""Generic deterministic rules shared by record types of several modules."""

from __future__ import annotations

from collections.abc import Iterable
from datetime import date, timedelta
from decimal import Decimal

from app.core.manifest import Issue, RuleInput
from app.core.models import Product, Unit
from app.core.services.master_data import convert, find_unit


def today_for(inp: RuleInput) -> date:
    return inp.settings.get("today") or date.today()


def date_plausible(field: str):
    def rule(inp: RuleInput) -> Iterable[Issue]:
        value = inp.payload.get(field)
        if not value:
            return
        d = date.fromisoformat(value)
        today = today_for(inp)
        if d > today:
            yield Issue("date_in_future", "error", f"Date {d.isoformat()} is in the future", field)
        elif d < today - timedelta(days=366):
            yield Issue("date_too_old", "warning", f"Date {d.isoformat()} is more than a year ago", field)

    rule.__name__ = f"date_plausible_{field}"
    return rule


def unit_compatible(inp: RuleInput) -> Iterable[Issue]:
    product_id = inp.ref_id("product")
    unit_code = inp.payload.get("unit")
    if not product_id or not unit_code:
        return
    product = inp.session.get(Product, product_id)
    unit = find_unit(inp.session, unit_code)
    if product is None or unit is None:
        return
    base = inp.session.get(Unit, product.base_unit_id)
    if convert(Decimal(1), unit, base) is None:
        yield Issue("unit_incompatible", "error",
                    f"Unit '{unit.code}' is not compatible with {product.code} (base unit '{base.code}')", "unit")


