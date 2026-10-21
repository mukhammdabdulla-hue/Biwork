"""Operational commands.

    python -m app.cli bootstrap                       # system roles (run after every migration)
    python -m app.cli create-admin --email ... --password ... --name ...
    python -m app.cli seed-demo [--password ...]      # demo company flagged as SAMPLE DATA
"""

from __future__ import annotations

import argparse
import random
import sys
from datetime import date, time, timedelta
from decimal import Decimal

from sqlalchemy import func, select

from app.config import get_settings
from app.core.context import RequestContext
from app.core.models import Company, Department, Membership, User
from app.core.security import hash_password, password_problems
from app.core.services import identity, master_data, tasks
from app.db.session import SessionLocal, bind_tenant
from app.modules.registry import available_module_codes, manifests


def bootstrap() -> None:
    manifests()
    with SessionLocal() as s:
        roles = identity.sync_system_roles(s)
        s.commit()
        print(f"System roles synchronized: {', '.join(r.code for r in roles)}")


def create_admin(email: str, password: str, name: str) -> User:
    problems = password_problems(password)
    if problems:
        sys.exit("Password must have " + ", ".join(problems))
    with SessionLocal() as s:
        user = s.execute(select(User).where(func.lower(User.email) == email.lower())).scalar_one_or_none()
        if user is None:
            user = User(email=email.lower(), full_name=name, password_hash=hash_password(password), is_platform_admin=True)
            s.add(user)
        else:
            user.is_platform_admin = True
            user.password_hash = hash_password(password)
        s.commit()
        print(f"Platform administrator ready: {user.email}")
        return user


def admin_ctx(session, company_id, user_id) -> RequestContext:
    ctx = identity.context_for_user_id(session, user_id, company_id, request_id="seed")
    bind_tenant(session, company_id, user_id)
    return ctx


def seed_demo(password: str) -> None:
    """Create 'BIWORK Demo Factory' with master data and 45 days of sample operations."""
    from app.modules.inventory import service as inventory
    from app.modules.production import service as production
    from app.modules.supply import service as supply

    bootstrap()
    users = {
        "admin": ("admin@biwork.local", "Dilshod Karimov", "admin"),
        "manager": ("manager@biwork.local", "Malika Yusupova", "manager"),
        "operator": ("operator@biwork.local", "Jasur Toshmatov", "operator"),
        "viewer": ("viewer@biwork.local", "Nodira Rahimova", "viewer"),
    }
    admin = create_admin(users["admin"][0], password, users["admin"][1])
    rnd = random.Random(42)
    with SessionLocal() as s:
        if s.execute(select(Company).where(Company.code == "DEMO")).scalar_one_or_none():
            print("Demo company already exists; nothing to do")
            return
        admin = s.get(User, admin.id)
        company = identity.create_company(s, admin, identity.CompanyIn(
            name="BIWORK Demo Factory", code="DEMO", tin="301234567", organization_code="DEMO-HOLDING",
            organization_name="Demo Industrial Holding", is_sample_data=True,
        ), available_module_codes())
        s.commit()
        ctx = admin_ctx(s, company.id, admin.id)

        deps = {}
        for code, name, kind, aliases in [
            ("SEX-1", "1-sex", "workshop", ["Sex 1", "1 sex", "Цех №1", "Цех 1", "Workshop 1", "Birinchi sex"]),
            ("SEX-2", "2-sex", "workshop", ["Sex 2", "Цех №2", "Цех 2", "Workshop 2", "Ikkinchi sex"]),
            ("SEX-3", "3-sex (qadoqlash)", "workshop", ["3-sex", "Цех №3", "Packaging workshop", "Qadoqlash sexi"]),
            ("OMBOR", "Ombor xo'jaligi", "warehouse", ["Ombor", "Склад", "Warehouse"]),
            ("TAMINOT", "Ta'minot bo'limi", "office", ["Ta'minot", "Снабжение", "Procurement"]),
            ("SIFAT", "Sifat nazorati bo'limi", "office", ["OTK", "ОТК", "Quality control"]),
        ]:
            deps[code] = master_data.create_department(s, ctx, master_data.DepartmentIn(code=code, name=name, kind=kind, aliases=aliases))
        fg = master_data.create_category(s, ctx, master_data.CategoryIn(code="FG", name="Tayyor mahsulot"))
        rm = master_data.create_category(s, ctx, master_data.CategoryIn(code="RM", name="Xomashyo"))
        products = {}
        for code, name, unit, ptype, cat, reorder, aliases in [
            ("A-001", "A mahsulot", "pcs", "finished_good", fg, 500, ["А маҳсулот", "Product A", "Изделие А"]),
            ("B-001", "B mahsulot", "pcs", "finished_good", fg, 300, ["Б маҳсулот", "Product B", "Изделие Б"]),
            ("C-010", "C panel 60x60", "pcs", "finished_good", fg, 200, ["Panel C", "Панель 60x60"]),
            ("RM-CEM", "Sement M400", "bag", "raw_material", rm, 100, ["Цемент М400", "Cement M400", "Sement M-400"]),
            ("RM-STL", "Po'lat list 2mm", "kg", "raw_material", rm, 2000, ["Стальной лист 2мм", "Steel sheet 2mm"]),
            ("RM-PNT", "Bo'yoq oq", "l", "raw_material", rm, 150, ["Краска белая", "White paint"]),
            ("PK-BOX", "Karton quti", "pcs", "packaging", rm, 1000, ["Коробка картонная", "Carton box"]),
        ]:
            products[code] = master_data.create_product(s, ctx, master_data.ProductIn(
                code=code, name=name, base_unit=unit, product_type=ptype, category_id=cat.id,
                reorder_level=Decimal(reorder), aliases=aliases))
        whs = {
            "FG": inventory.create_warehouse(s, ctx, inventory.WarehouseIn(code="FG", name="Tayyor mahsulot ombori", kind="finished",
                                                                           department_id=deps["OMBOR"].id)),
            "RM": inventory.create_warehouse(s, ctx, inventory.WarehouseIn(code="RM", name="Xomashyo ombori", kind="raw",
                                                                           department_id=deps["OMBOR"].id)),
        }
        company.settings = {**company.settings, "production_output_warehouse_id": str(whs["FG"].id)}
        shifts = {
            "1": production.create_shift(s, ctx, production.ShiftIn(code="1", name="1-smena", starts_at=time(8), ends_at=time(20))),
            "2": production.create_shift(s, ctx, production.ShiftIn(code="2", name="2-smena", starts_at=time(20), ends_at=time(8))),
        }
        for code, name, tin, aliases in [("QQS", "Qizilqumsement AJ", "200123456", ["Кызылкумцемент", "Qizilqumsement"]),
                                         ("BKM", "Bekobod Metall MChJ", "200654321", ["Бекабад Металл"]),
                                         ("PNT", "Toshkent Bo'yoq Zavodi", "200777888", ["Ташкентский лакокрасочный"])]:
            supply.create_supplier(s, ctx, supply.SupplierIn(code=code, name=name, tin=tin, aliases=aliases))
        s.flush()

        for key, (email, name, role) in users.items():
            if key == "admin":
                continue
            identity.add_member(s, ctx, identity.MemberIn(email=email, full_name=name, role=role, password=password))
        s.flush()

        today = date.today()
        # Opening raw-material stock.
        for code, qty in [("RM-CEM", 400), ("RM-STL", 6500), ("RM-PNT", 120), ("PK-BOX", 3000)]:
            inventory.post_movement(s, ctx, inventory.MovementIn(
                movement_date=today - timedelta(days=46), movement_type="receipt", warehouse_id=whs["RM"].id,
                product_id=products[code].id, quantity=Decimal(qty), unit=products[code].base_unit.code, reference="Opening balance"))
        orders = []
        for i, (pcode, dep, qty, start, length, status) in enumerate([
            ("A-001", "SEX-1", 12000, -40, 30, "completed"), ("B-001", "SEX-2", 6000, -35, 30, "in_progress"),
            ("A-001", "SEX-1", 15000, -10, 30, "in_progress"), ("C-010", "SEX-3", 4000, -12, 25, "in_progress"),
            ("B-001", "SEX-2", 5000, 5, 20, "planned"), ("C-010", "SEX-3", 3000, 10, 15, "planned"),
        ]):
            order = production.create_order(s, ctx, production.OrderIn(
                product_id=products[pcode].id, planned_qty=Decimal(qty), department_id=deps[dep].id,
                planned_start=today + timedelta(days=start), planned_end=today + timedelta(days=start + length),
                priority=["normal", "high"][i % 2]))
            for step in {"completed": ["released", "in_progress", "completed"], "in_progress": ["released", "in_progress"]}.get(status, []):
                production.set_order_status(s, ctx, order.id, step)
            orders.append(order)
        # 45 days of approved production reports (two shifts, three workshops), excluding today.
        plan = [("A-001", "SEX-1", 220, orders[0], orders[2]), ("B-001", "SEX-2", 110, orders[1], orders[1]),
                ("C-010", "SEX-3", 75, orders[3], orders[3])]
        for back in range(45, 0, -1):
            d = today - timedelta(days=back)
            if d.weekday() == 6:
                continue
            for pcode, dep, base, old_order, new_order in plan:
                order = old_order if d <= (old_order.planned_end or d) and back > 10 else new_order
                if pcode == "C-010" and back > 12:
                    order = None
                for shift in ("1", "2"):
                    produced = Decimal(int(base * rnd.uniform(0.82, 1.12)))
                    defective = Decimal(int(produced * Decimal(rnd.uniform(0.005, 0.045))))
                    production.create_report(s, ctx, production.ReportIn(
                        production_date=d, product_id=products[pcode].id, department_id=deps[dep].id, shift_id=shifts[shift].id,
                        order_id=order.id if order else None, qty_produced=produced, qty_accepted=produced - defective,
                        qty_defective=defective), approve=True)
            if back % 7 == 0 and back <= 35:
                inventory.post_movement(s, ctx, inventory.MovementIn(
                    movement_date=d, movement_type="issue", warehouse_id=whs["FG"].id, product_id=products["A-001"].id,
                    quantity=Decimal(1500), unit="pcs", reference=f"Shipment {d:%d.%m}"))
        members = dict(s.execute(select(User.email, User.id).join(Membership, Membership.user_id == User.id)
                                 .where(Membership.company_id == company.id)).all())
        for title, who, due, prio, status in [
            ("2-sex liniyasini profilaktik ko'rikdan o'tkazish", "operator@biwork.local", -3, "high", "open"),
            ("Oktabr oyi ishlab chiqarish hisobotini tayyorlash", "manager@biwork.local", -1, "normal", "in_progress"),
            ("Sement zaxirasini tekshirish", "operator@biwork.local", 2, "normal", "open"),
            ("Yangi C panel partiyasi sifat nazorati", "manager@biwork.local", 5, "urgent", "open"),
            ("Bo'yoq yetkazib beruvchisi bilan shartnomani yangilash", "manager@biwork.local", -8, "low", "done"),
        ]:
            t = tasks.create_task(s, ctx, tasks.TaskIn(title=title, assignee_id=members.get(who), due_date=today + timedelta(days=due),
                                                       priority=prio))
            if status != "open":
                tasks.update_task(s, ctx, t.id, tasks.TaskUpdate(status=status))
        supply.create_purchase_request(s, ctx, supply.PurchaseRequestIn(
            request_date=today - timedelta(days=2), needed_by=today + timedelta(days=12), department_id=deps["SEX-2"].id,
            requested_by="Jasur Toshmatov", priority="high", currency="UZS", notes="Oy oxirigacha zaxira",
            lines=[supply.LineIn(description="Po'lat list 2mm", quantity=Decimal(3000), unit="kg", product_id=products["RM-STL"].id,
                                 estimated_unit_price=Decimal(14500)),
                   supply.LineIn(description="Bo'yoq oq", quantity=Decimal(200), unit="l", product_id=products["RM-PNT"].id,
                                 estimated_unit_price=Decimal(38000))]))
        s.commit()
        dep_count = s.execute(select(func.count()).select_from(Department).where(Department.company_id == company.id)).scalar()
        print(f"Demo company '{company.name}' created (SAMPLE DATA) with {dep_count} departments, {len(products)} products.")
        print("Users: " + ", ".join(u[0] for u in users.values()) + f" (password: {password})")


def ai_models(provider: str | None) -> int:
    """List the model ids each configured provider currently serves and check the configured ones
    against that list (model ids are retired over time; this is the startup sanity check)."""
    from app.ai import router

    s = get_settings()
    names = [provider] if provider else s.configured_ai_providers()
    if not names:
        print("No AI provider credentials are configured: the Agent runs in labelled demo mode.")
        return 0
    status = 0
    for name in names:
        try:
            available = set(router.get_provider(name).list_models())
        except Exception as exc:  # noqa: BLE001 - report and continue with the next provider
            print(f"{name}: could not list models ({exc.__class__.__name__}: {exc})")
            status = 1
            continue
        print(f"{name}: {len(available)} model(s) available")
        for tier in router.TIERS:
            model = router.model_for(name, tier)
            if model:
                ok = model in available
                status |= 0 if ok else 1
                print(f"  {tier:<8} {model:<40} {'ok' if ok else 'NOT AVAILABLE to this API key'}")
    return status


def ai_check() -> int:
    """Print the effective routing; exit 1 when the configuration is unusable outside demo mode."""
    import json

    from app.ai import router

    info = router.describe()
    print(json.dumps(info, indent=2))
    return 0 if info["mode"] == "demo" or info["provider_order"] else 1


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(prog="python -m app.cli")
    sub = parser.add_subparsers(dest="cmd", required=True)
    sub.add_parser("bootstrap")
    p = sub.add_parser("create-admin")
    p.add_argument("--email", required=True)
    p.add_argument("--password", required=True)
    p.add_argument("--name", default="Platform Administrator")
    p = sub.add_parser("seed-demo")
    p.add_argument("--password", default="Biwork2026!demo")
    p = sub.add_parser("ai-models", help="list provider models and verify the configured model ids")
    p.add_argument("--provider", choices=["openai", "gemini", "anthropic"])
    sub.add_parser("ai-check", help="print the effective AI routing configuration")
    p = sub.add_parser("ai-eval", help="evaluate providers/models on the BIWORK evaluation dataset")
    p.add_argument("--dataset", default=None, help="dataset directory (default: eval/dataset)")
    p.add_argument("--provider", action="append", default=None,
                   help="provider[:model] to evaluate; repeatable (default: every configured provider, or demo)")
    p.add_argument("--out", default=None, help="write the JSON report here (a Markdown summary is written next to it)")
    args = parser.parse_args(argv)
    if args.cmd == "bootstrap":
        bootstrap()
    elif args.cmd == "create-admin":
        create_admin(args.email, args.password, args.name)
    elif args.cmd == "seed-demo":
        seed_demo(args.password)
    elif args.cmd == "ai-models":
        raise SystemExit(ai_models(args.provider))
    elif args.cmd == "ai-check":
        raise SystemExit(ai_check())
    elif args.cmd == "ai-eval":
        from app.agent.evaluation import main as eval_main

        raise SystemExit(eval_main(args.dataset, args.provider, args.out))


if __name__ == "__main__":
    main()
