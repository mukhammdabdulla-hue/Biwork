"""Data access scope: department-restricted members, entity search permissions, tenant-owned references,
and company module switches."""

from __future__ import annotations

import uuid
from datetime import date, timedelta

from sqlalchemy import text

from tests.conftest import PASSWORD, owner_query, records
from tests.files import xlsx
from tests.test_scenarios import UZ_TEXT, submit_file, submit_text

UZ_TEXT_SEX2 = ("20-oktabr kuni 300 dona B mahsulot ishlab chiqarildi. 290 dona sifat nazoratidan o'tdi, "
                "10 donasi brak bo'ldi. Mas'ul sex — 2-sex.")


# --------------------------------------------------------------------------- BIWORK Agent


def test_agent_jobs_and_records_follow_department_scope(client, world):
    operator = world.login(client, "operator")
    scoped = world.login(client, "scoped")  # a manager limited to 2-sex
    sex1 = submit_text(client, operator, UZ_TEXT)
    sex1_rec = records(client, operator, sex1["id"])["items"][0]["id"]
    sex2 = submit_text(client, operator, UZ_TEXT_SEX2)
    sex2_rec = records(client, operator, sex2["id"])["items"][0]["id"]

    listed = {j["id"] for j in client.get("/api/v1/agent/jobs", headers=scoped).json()["items"]}
    assert sex2["id"] in listed and sex1["id"] not in listed
    for path in (f"jobs/{sex1['id']}", f"jobs/{sex1['id']}/records", f"jobs/{sex1['id']}/report", f"jobs/{sex1['id']}/error-report",
                 f"records/{sex1_rec}"):
        assert client.get(f"/api/v1/agent/{path}", headers=scoped).status_code == 404, path
    assert client.post(f"/api/v1/agent/records/{sex1_rec}/approve", headers=scoped).status_code == 404
    assert client.post(f"/api/v1/agent/records/{sex1_rec}/reject", headers=scoped).status_code == 404
    assert client.post(f"/api/v1/agent/jobs/{sex1['id']}/approve", headers=scoped, json={"group": None}).status_code == 404
    all_jobs = {j["id"] for j in client.get("/api/v1/agent/jobs", headers=operator).json()["items"]}
    assert {sex1["id"], sex2["id"]} <= all_jobs

    # Moving a visible record into a department outside the member's scope is refused.
    r = client.patch(f"/api/v1/agent/records/{sex2_rec}", headers=scoped,
                     json={"references": {"department": str(world.a.departments["SEX-1"])}})
    assert r.status_code == 403
    assert client.get(f"/api/v1/agent/records/{sex2_rec}", headers=scoped).json()["fields"]
    r = client.post(f"/api/v1/agent/records/{sex2_rec}/approve", headers=scoped)
    assert r.status_code == 200 and r.json()["status"] == "approved"

    # A member sees their own submissions, but not the records of other departments inside them.
    own = submit_text(client, scoped, UZ_TEXT.replace("21-oktabr", "19-oktabr"))
    assert client.get(f"/api/v1/agent/jobs/{own['id']}", headers=scoped).status_code == 200
    assert records(client, scoped, own["id"])["total"] == 0


def test_mixed_department_spreadsheet_shows_only_the_members_rows(client, world):
    operator = world.login(client, "operator")
    scoped = world.login(client, "scoped")
    day = date.today() - timedelta(days=1)
    job = submit_file(client, operator, "mixed.xlsx", xlsx([["Sana", "Mahsulot", "Sex", "Ishlab chiqarildi"],
                                                            [day, "A mahsulot", "1-sex", 10], [day, "B mahsulot", "2-sex", 5]]))
    assert records(client, operator, job["id"])["total"] == 2
    visible = records(client, scoped, job["id"])
    assert visible["total"] == 1 and sum(visible["statuses"].values()) == 1
    detail = client.get(f"/api/v1/agent/records/{visible['items'][0]['id']}", headers=scoped).json()
    assert detail["payload"]["department"] == "2-sex"
    report = client.get(f"/api/v1/agent/jobs/{job['id']}/report", headers=scoped).json()
    assert sum(report["records_by_status"].values()) == 1
    r = client.post(f"/api/v1/agent/jobs/{job['id']}/cancel", headers=scoped)  # would discard the 1-sex row too
    assert r.status_code == 403 and r.json()["code"] == "department_restricted"
    assert client.post(f"/api/v1/agent/jobs/{job['id']}/cancel", headers=operator).status_code == 200


def test_sources_of_mixed_jobs_are_hidden_from_restricted_members(client, world):
    operator = world.login(client, "operator")
    scoped = world.login(client, "scoped")
    day = date.today() - timedelta(days=1)
    sheet = submit_file(client, operator, "mixed.xlsx", xlsx([["Sana", "Mahsulot", "Sex", "Ishlab chiqarildi"],
                                                              [day, "A mahsulot", "1-sex", 777], [day, "B mahsulot", "2-sex", 5]]))
    mixed = submit_text(client, operator, f"{UZ_TEXT}\n{UZ_TEXT_SEX2}")
    assert records(client, operator, mixed["id"])["total"] == 2 and records(client, scoped, mixed["id"])["total"] == 1
    own = submit_text(client, scoped, f"{UZ_TEXT_SEX2}\n{UZ_TEXT}".replace("oktabr", "noyabr"))
    sex2 = submit_text(client, operator, UZ_TEXT_SEX2.replace("300", "310"))

    def source(job, who):
        item = job["items_detail"][0]
        detail = client.get(f"/api/v1/agent/jobs/{job['id']}", headers=who).json()
        params = {"table_seq": item["tables"][0]["table_seq"]} if item["tables"] else {}
        preview = client.get(f"/api/v1/agent/items/{item['id']}/preview", headers=who, params=params)
        return detail, preview

    # The text of a job that also holds 1-sex records shows 1-sex data, so it stays hidden ...
    for job in (sheet, mixed):
        assert source(job, scoped)[0]["items_detail"][0]["text_preview"] is None
        assert source(job, operator)[1].status_code == 200
    for item in (mixed["items_detail"][0]["id"], sheet["items_detail"][0]["id"]):
        r = client.get(f"/api/v1/agent/items/{item}/preview", headers=scoped)
        assert r.status_code == 403 and r.json()["code"] == "department_restricted"
    # ... and of a sheet, only the rows of records the member may see are shown.
    assert [row["cells"][2] for row in source(sheet, scoped)[1].json()["rows"]] == ["2-sex"]
    assert [row["cells"][2:] for row in source(sheet, operator)[1].json()["rows"]] == [["1-sex", 777], ["2-sex", 5]]
    assert source(mixed, scoped)[0]["title"] is None and source(sheet, scoped)[0]["title"] == "mixed.xlsx"
    listed = {j["id"]: j["title"] for j in client.get("/api/v1/agent/jobs", headers=scoped).json()["items"]}
    assert listed[mixed["id"]] is None and listed[sex2["id"]] == sex2["title"] and listed[own["id"]] == own["title"]
    # Their own submissions and jobs of their departments only are shown in full.
    for job in (own, sex2):
        detail, preview = source(job, scoped)
        assert detail["items_detail"][0]["text_preview"] and preview.status_code == 200

    # Re-mapping the sheet would re-create its 1-sex rows too.
    table = sheet["items_detail"][0]["tables"][0]
    body = {"table_seq": table["table_seq"], "record_type": table["record_type"],
            "mapping": {str(m["source_index"]): m["target_field"] for m in table["mapping"]}, "save_template": False}
    r = client.put(f"/api/v1/agent/jobs/{sheet['id']}/items/{sheet['items_detail'][0]['id']}/mapping", headers=scoped, json=body)
    assert r.status_code == 403 and r.json()["code"] == "department_restricted"
    r = client.put(f"/api/v1/agent/jobs/{sheet['id']}/items/{sheet['items_detail'][0]['id']}/mapping", headers=operator, json=body)
    assert r.status_code == 200, r.text


# --------------------------------------------------------------------------- overview


def test_overview_is_limited_to_the_members_departments(client, world, owner_engine):
    manager = world.login(client, "manager")
    scoped = world.login(client, "scoped")
    day = (date.today() - timedelta(days=1)).isoformat()
    for dep, product, qty in (("SEX-1", "A-001", "100"), ("SEX-2", "B-001", "40")):
        r = client.post("/api/v1/production/reports", headers=manager, json={
            "production_date": day, "product_id": str(world.a.products[product]), "department_id": str(world.a.departments[dep]),
            "qty_produced": qty, "approve": True})
        assert r.status_code == 201, r.text
    sex1 = str(world.a.departments["SEX-1"])
    client.post("/api/v1/production/reports", headers=manager, json={
        "production_date": (date.today() - timedelta(days=2)).isoformat(), "product_id": str(world.a.products["A-001"]),
        "department_id": sex1, "qty_produced": "5"})
    order = client.post("/api/v1/production/orders", headers=manager, json={
        "product_id": str(world.a.products["A-001"]), "planned_qty": "500", "department_id": sex1}).json()
    etag = client.get(f"/api/v1/production/orders/{order['id']}", headers=manager).headers["etag"]
    client.post(f"/api/v1/production/orders/{order['id']}/status", headers={**manager, "If-Match": etag}, json={"status": "released"})
    client.post("/api/v1/tasks", headers=manager, json={"title": "Clean line 1", "department_id": sex1,
                                                         "due_date": (date.today() - timedelta(days=3)).isoformat()})
    client.post("/api/v1/supply/purchase-requests", headers=manager, json={
        "request_date": day, "department_id": sex1, "lines": [{"description": "Bolts", "quantity": "10", "unit": "pcs"}]})
    submit_text(client, manager, UZ_TEXT)  # a 1-sex record awaiting review

    full = client.get("/api/v1/analytics/overview", headers=manager).json()
    assert full["kpis"]["total_production"]["value"] == 140
    assert {d["name"] for d in full["charts"]["by_department"]} == {"1-sex", "2-sex"}

    ov = client.get("/api/v1/analytics/overview", headers=scoped).json()
    assert ov["kpis"]["total_production"]["value"] == 40
    assert sum(d["produced"] for d in ov["charts"]["production_trend"]) == 40
    assert [(d["name"], d["produced"]) for d in ov["charts"]["by_department"]] == [("2-sex", 40.0)]
    assert [(p["code"], p["produced"]) for p in ov["charts"]["by_product"]] == [("B-001", 40.0)]
    assert ov["kpis"]["active_orders"]["value"] == 0 and ov["kpis"]["overdue_tasks"]["value"] == 0
    assert ov["kpis"]["pending_approvals"] == {"value": 0, "agent_records": 0, "purchase_requests": 0, "draft_reports": 0}
    assert not [a for a in ov["alerts"] if a["kind"] in ("order_late", "task_overdue")]
    assert ov["recent_activity"] == [] and full["recent_activity"]


def test_failed_jobs_card_counts_every_failed_job(client, world, owner_engine):
    manager = world.login(client, "manager")
    for i in range(7):
        submit_text(client, manager, UZ_TEXT.replace("500", str(500 + i)))
    with owner_engine.begin() as conn:
        conn.execute(text("UPDATE agent.ingestion_jobs SET status = 'failed', error = '{\"message\": \"boom\"}'::jsonb"))
    ov = client.get("/api/v1/analytics/overview", headers=manager).json()
    assert ov["kpis"]["failed_jobs"]["currently_failed"] == 7
    assert len([a for a in ov["alerts"] if a["kind"] == "job_failed"]) == 5


# --------------------------------------------------------------------------- audit


def test_audit_trail_is_refused_to_department_restricted_members(client, world, owner_engine):
    manager = world.login(client, "manager")
    scoped = world.login(client, "scoped")
    report = client.post("/api/v1/production/reports", headers=manager, json={
        "production_date": (date.today() - timedelta(days=1)).isoformat(), "product_id": str(world.a.products["A-001"]),
        "department_id": str(world.a.departments["SEX-1"]), "qty_produced": "10", "approve": True}).json()
    assert client.get(f"/api/v1/production/reports/{report['id']}", headers=scoped).status_code == 404
    for path in ("/api/v1/audit/events", "/api/v1/audit/verify", f"/api/v1/audit/trace/production_report/{report['id']}"):
        r = client.get(path, headers=scoped)
        assert r.status_code == 403 and r.json()["code"] == "department_restricted", path
        assert client.get(path, headers=manager).status_code == 200, path


# --------------------------------------------------------------------------- entity search


def test_entity_search_needs_the_owning_modules_read_permission(client, world):
    admin = world.login(client, "admin")
    key = client.post("/api/v1/api-clients", headers=admin, json={"name": "Reader", "scopes": ["agent.read"]}).json()["api_key"]
    as_key = {"Authorization": f"Bearer {key}"}
    for entity, q in (("user", "a.test"), ("supplier", "Qizilqum"), ("product", "mahsulot"), ("production_order", "MO")):
        r = client.get(f"/api/v1/master/search/{entity}", headers=as_key, params={"q": q})
        assert r.status_code == 403, entity

    r = client.post("/api/v1/roles", headers=admin, json={"code": "tasks_only", "name": "Tasks only", "permissions": ["tasks.read"]})
    assert r.status_code == 201, r.text
    client.post("/api/v1/members", headers=admin, json={"email": "tasks@a.test", "full_name": "Tasks Only", "role": "tasks_only",
                                                         "password": PASSWORD})
    r = client.post("/api/v1/auth/login", json={"email": "tasks@a.test", "password": PASSWORD})
    tasks_only = {"Authorization": f"Bearer {r.json()['access_token']}", "X-Company-Id": world.a.id}
    assert client.get("/api/v1/supply/suppliers", headers=tasks_only).status_code == 403
    assert client.get("/api/v1/master/search/supplier", headers=tasks_only, params={"q": "Qizilqum"}).status_code == 403
    assert client.get("/api/v1/master/search/user", headers=tasks_only, params={"q": "Manager"}).status_code == 200

    manager = world.login(client, "manager")
    labels = {}
    for dep in ("SEX-1", "SEX-2"):
        o = client.post("/api/v1/production/orders", headers=manager, json={
            "product_id": str(world.a.products["A-001"]), "planned_qty": "50", "department_id": str(world.a.departments[dep])}).json()
        labels[dep] = o["number"]
    scoped = world.login(client, "scoped")
    found = client.get("/api/v1/master/search/production_order", headers=scoped, params={"q": labels["SEX-1"]}).json()
    assert found == []
    found = client.get("/api/v1/master/search/production_order", headers=scoped, params={"q": labels["SEX-2"]}).json()
    assert [f["label"].split(" ")[0] for f in found] == [labels["SEX-2"]]
    assert client.get("/api/v1/master/search/production_order", headers=manager, params={"q": labels["SEX-1"]}).json()


# --------------------------------------------------------------------------- references owned by the tenant


def test_references_to_another_companys_rows_are_unknown(client, world, owner_engine):
    admin_b = world.login(client, "admin", world.b)
    b_line = client.post("/api/v1/production/lines", headers=admin_b, json={
        "code": "L1", "name": "Line 1", "department_id": str(world.b.departments["SEX-1"])}).json()["id"]
    b_category = client.post("/api/v1/master/product-categories", headers=admin_b, json={"code": "FG", "name": "Finished"}).json()["id"]
    b_department = str(world.b.departments["SEX-1"])

    admin = world.login(client, "admin")
    for foreign in (b_line, b_category, b_department, str(uuid.uuid4())):
        calls = (
            ("/api/v1/production/orders", {"product_id": str(world.a.products["A-001"]), "planned_qty": "1", "line_id": foreign}),
            ("/api/v1/master/products", {"code": f"X-{foreign[:6]}", "name": "X", "base_unit": "pcs", "category_id": foreign}),
            ("/api/v1/inventory/warehouses", {"code": f"W-{foreign[:6]}", "name": "W", "department_id": foreign}),
            ("/api/v1/departments", {"code": f"D-{foreign[:6]}", "name": "D", "parent_id": foreign}),
            ("/api/v1/master/product-categories", {"code": f"C-{foreign[:6]}", "name": "C", "parent_id": foreign}),
            ("/api/v1/tasks", {"title": "T", "department_id": foreign}),
        )
        for path, body in calls:
            r = client.post(path, headers=admin, json=body)
            assert r.status_code == 422, (path, r.text)
    product = str(world.a.products["A-001"])
    etag = client.get(f"/api/v1/master/products/{product}", headers=admin).headers["etag"]
    r = client.patch(f"/api/v1/master/products/{product}", headers={**admin, "If-Match": etag}, json={"category_id": b_category})
    assert r.status_code == 422
    assert owner_query(owner_engine, "SELECT count(*) FROM production.production_orders")[0][0] == 0


# --------------------------------------------------------------------------- module switches


def test_disabled_modules_refuse_their_routes(client, world):
    admin = world.login(client, "admin")
    assert client.put("/api/v1/company/modules/supply", headers=admin, json={"enabled": False}).status_code == 200
    r = client.get("/api/v1/supply/purchase-requests", headers=admin)
    assert r.status_code == 403 and r.json()["code"] == "module_disabled"
    assert client.get("/api/v1/production/reports", headers=admin).status_code == 200
    assert client.get("/api/v1/agent/jobs", headers=admin).status_code == 200
    assert client.get("/api/v1/company", headers=admin).status_code == 200
    client.put("/api/v1/company/modules/supply", headers=admin, json={"enabled": True})
    assert client.get("/api/v1/supply/purchase-requests", headers=admin).status_code == 200

    client.put("/api/v1/company/modules/analytics", headers=admin, json={"enabled": False})
    modules = {m["code"]: m["enabled"] for m in client.get("/api/v1/company", headers=admin).json()["modules"]}
    assert modules["analytics"] is False
    for path in ("/api/v1/analytics/quality", "/api/v1/analytics/agent/stats"):
        r = client.get(path, headers=admin)
        assert r.status_code == 403 and r.json()["code"] == "module_disabled", path
    assert client.get("/api/v1/analytics/overview", headers=admin).status_code == 200  # the home dashboard
