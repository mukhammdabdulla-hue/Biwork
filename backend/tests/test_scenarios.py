"""MVP acceptance scenarios A–G (task spec §12, docs/architecture.md §12)."""

from __future__ import annotations

import io
from datetime import date, timedelta

import openpyxl
import pytest
from sqlalchemy import text

from app.agent.normalization import parse_date
from tests.conftest import owner_query, records, wait_job
from tests.files import docx_with_table, pdf_with_table, purchase_request_pdf, xlsx

UZ_TEXT = ("21-oktabr kuni 500 dona A mahsulot ishlab chiqarildi. 480 dona sifat nazoratidan o'tdi, "
           "20 donasi brak bo'ldi. Mas'ul sex — 1-sex.")


def submit_text(client, headers, text_input, **form):
    r = client.post("/api/v1/agent/jobs", headers=headers, data={"text": text_input, **form})
    assert r.status_code == 202, r.text
    return wait_job(client, headers, r.json()["id"])


def submit_file(client, headers, name, data, **form):
    r = client.post("/api/v1/agent/jobs", headers=headers, files=[("files", (name, data))], data=form)
    assert r.status_code == 202, r.text
    return wait_job(client, headers, r.json()["id"])


def commit(client, headers, job_id, **json):
    r = client.post(f"/api/v1/agent/jobs/{job_id}/commit", headers=headers, json=json or None)
    assert r.status_code == 202, r.text
    return wait_job(client, headers, job_id)


# --------------------------------------------------------------------------- Scenario A


def test_scenario_a_text_ingestion(client, world, owner_engine):
    operator = world.login(client, "operator")
    manager = world.login(client, "manager")
    job = submit_text(client, operator, UZ_TEXT)
    assert job["status"] == "awaiting_review"
    assert job["ai_is_mock"] is True and any("Demo mode" in w for w in job["warnings"])

    recs = records(client, operator, job["id"])
    assert recs["total"] == 1
    rec = client.get(f"/api/v1/agent/records/{recs['items'][0]['id']}", headers=operator).json()
    assert rec["record_type"] == "production_report"
    values = {f["field"]: f["normalized_value"] for f in rec["fields"]}
    expected_date = parse_date("21-oktabr", reference=date.today()).value.isoformat()
    assert values["production_date"] == expected_date
    assert (values["qty_produced"], values["qty_accepted"], values["qty_defective"]) == ("500", "480", "20")
    assert values["unit"] == "pcs"
    fields = {f["field"]: f for f in rec["fields"]}
    assert fields["product"]["match"]["label"].startswith("A-001")
    assert fields["department"]["match"]["label"].startswith("1-sex")
    assert all(f["sources"] for f in rec["fields"] if f["raw_value"]), "every extracted value cites its source"
    rules = {i["rule_code"] for i in rec["issues"]}
    assert "quantities_reconciled" in rules and not [i for i in rec["issues"] if i["severity"] == "error"]
    assert rec["review_group"] == "ready"

    # Operators can review but not approve; approval requires production.approve.
    r = client.post(f"/api/v1/agent/records/{rec['id']}/approve", headers=operator)
    assert r.status_code == 403 and r.json()["code"] == "permission_denied"
    r = client.post(f"/api/v1/agent/records/{rec['id']}/approve", headers=manager)
    assert r.status_code == 200 and r.json()["status"] == "approved"

    job = commit(client, manager, job["id"])
    assert job["status"] == "completed"
    assert any(m.startswith("1 record(s) saved: PRD-") for m in job["report"]["messages"])
    assert "6 field(s) validated." in job["report"]["messages"] or "7 field(s) validated." in job["report"]["messages"]

    rows = owner_query(owner_engine, "SELECT r.qty_produced, r.qty_accepted, r.qty_defective, r.status, r.production_date, "
                       "w.target_id FROM production.production_reports r JOIN agent.committed_writes w ON w.target_id = r.id "
                       "WHERE r.source_record_id = :rid", rid=rec["id"])
    assert len(rows) == 1
    produced, accepted, defective, status, prod_date, _ = rows[0]
    assert (int(produced), int(accepted), int(defective), status) == (500, 480, 20, "approved")
    assert prod_date.isoformat() == expected_date


def test_scenario_a_low_confidence_and_missing_values_are_not_invented(client, world):
    manager = world.login(client, "manager")
    job = submit_text(client, manager, "Bugun 120 dona Noma'lum detal ishlab chiqarildi.")
    rec_id = records(client, manager, job["id"])["items"][0]["id"]
    rec = client.get(f"/api/v1/agent/records/{rec_id}", headers=manager).json()
    fields = {f["field"]: f for f in rec["fields"]}
    assert fields["department"]["raw_value"] is None and fields["department"]["state"] == "missing"
    assert fields["product"]["match"]["id"] is None  # unknown product is never guessed
    codes = {i["rule_code"] for i in rec["issues"] if i["severity"] == "error"}
    assert {"required_missing", "reference_not_found"} <= codes
    assert rec["review_group"] == "needs_correction"
    r = client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=manager)
    assert r.status_code == 422 and r.json()["code"] == "record_has_errors"

    # Correct it: choose the department and the product explicitly, then approve.
    product = client.get("/api/v1/master/search/product", headers=manager, params={"q": "B mahsulot"}).json()[0]
    dep = client.get("/api/v1/master/search/department", headers=manager, params={"q": "2-sex"}).json()[0]
    r = client.patch(f"/api/v1/agent/records/{rec_id}", headers=manager,
                     json={"fields": {"department": "2-sex"}, "references": {"product": product["id"], "department": dep["id"]}})
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["review_group"] in ("ready", "needs_approval"), body["issues"]
    assert {f["field"]: f["match"]["match_kind"] for f in body["fields"] if f["match"]}["product"] == "manual"
    # The summary shows what will be saved, not the text as extracted.
    summary = records(client, manager, job["id"])["items"][0]["summary"]
    assert summary.startswith(f"{product['label']} · 120 pcs · {dep['label']}") and "Noma'lum" not in summary
    assert client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=manager).status_code == 200


def test_scenario_a_a_single_weak_candidate_is_not_called_ambiguous(client, world):
    manager = world.login(client, "manager")
    day = (date.today() - timedelta(days=1)).strftime("%d.%m.%Y")
    job = submit_text(client, manager, f"{day} 2-sex 1-smenada 120 dona A-002 ishlab chiqarildi")
    rec = client.get(f"/api/v1/agent/records/{records(client, manager, job['id'])['items'][0]['id']}", headers=manager).json()
    issue = next(i for i in rec["issues"] if i["field"] == "product")
    assert issue["rule_code"] == "reference_not_found" and "several" not in issue["message"]
    assert issue["message"].startswith("No product matches 'A-002'. Closest: A-001")


# --------------------------------------------------------------------------- Scenario B


def test_scenario_b_excel_import(client, world, owner_engine):
    manager = world.login(client, "manager")
    today = date.today()
    d1, d2, d3 = (today - timedelta(days=n) for n in (3, 2, 1))
    # An existing report that one spreadsheet row duplicates.
    r = client.post("/api/v1/production/reports", headers=manager, json={
        "production_date": d1.isoformat(), "product_id": str(world.a.products["B-001"]), "department_id": str(world.a.departments["SEX-2"]),
        "qty_produced": "100", "qty_accepted": "98", "qty_defective": "2", "approve": True})
    assert r.status_code == 201, r.text
    data = xlsx([
        ["Sana", "Mahsulot", "Sex", "Ishlab chiqarildi", "Sifatli", "Brak"],
        [d1, "A mahsulot", "1-sex", 400, 390, 10],          # valid
        [d2, "A mahsulot", "1-sex", 420, 400, 20],          # valid
        [d3, "Product A", "Цех №1", 410, 405, 5],           # valid via aliases
        [d1, "B mahsulot", "2-sex", 100, 98, 2],            # duplicates the existing report
        [d2, "Nomalum mahsulot", "1-sex", 50, 50, 0],       # unknown product
        [d3, "B mahsulot", "2-sex", 300, 290, 30],          # accepted + defective > produced
        [d2, "A mahsulot", "1-sex", 420, 400, 20],          # repeated row inside the file
        ["Jami", None, None, 2100, 2033, 87],               # totals row, skipped
    ], title="Ishlab chiqarish hisoboti")
    job = submit_file(client, manager, "hisobot.xlsx", data)
    assert job["status"] == "awaiting_review", job
    item = job["items_detail"][0]
    table = item["tables"][0]
    assert table["record_type"] == "production_report" and table["header_row"] == 2
    mapped = {m["source_column"]: m["target_field"] for m in table["mapping"]}
    assert mapped == {"Sana": "production_date", "Mahsulot": "product", "Sex": "department",
                      "Ishlab chiqarildi": "qty_produced", "Sifatli": "qty_accepted", "Brak": "qty_defective"}
    assert any("total row" in w for w in item["warnings"])

    recs = records(client, manager, job["id"])
    assert recs["total"] == 7
    by_row = {r["row_ref"]["row"]: r for r in recs["items"]}
    assert by_row[3]["review_group"] == "ready" and by_row[5]["review_group"] == "ready"
    expected = {6: {"duplicate_record"}, 7: {"reference_not_found", "reference_ambiguous"},
                8: {"quantities_exceed_produced"}, 9: {"duplicate_in_file"}}
    for row, rules in expected.items():
        detail = client.get(f"/api/v1/agent/records/{by_row[row]['id']}", headers=manager).json()
        assert detail["review_group"] == "needs_correction", (row, detail["issues"])
        assert rules & {i["rule_code"] for i in detail["issues"] if i["severity"] == "error"}, (row, detail["issues"])

    # Error report lists the problems with sheet/row references.
    r = client.get(f"/api/v1/agent/jobs/{job['id']}/error-report", headers=manager)
    assert r.status_code == 200
    sheet = openpyxl.load_workbook(io.BytesIO(r.content)).active
    problems = " ".join(str(c.value) for row in sheet.iter_rows() for c in row if c.value)
    assert "Already recorded" in problems and "row 7" in problems

    # Approve everything approvable, reject the rest, then save.
    result = client.post(f"/api/v1/agent/jobs/{job['id']}/approve", headers=manager, json={"group": "ready"}).json()
    assert len(result["approved"]) == 3
    job = commit(client, manager, job["id"])
    saved = owner_query(owner_engine, "SELECT count(*) FROM production.production_reports WHERE source_record_id IS NOT NULL")[0][0]
    assert saved == 3
    assert job["status"] == "awaiting_review"  # error rows remain pending for correction
    assert job["report"]["records_by_status"] == {"committed": 3, "needs_review": 4}


def test_scenario_b_unconfirmed_ai_mapping_needs_approval(client, world):
    manager = world.login(client, "manager")
    day = (date.today() - timedelta(days=1)).isoformat()
    rows = [["Kun", "Nomi", "Uchastok", "Jami (dona)"], [day, "A mahsulot", "1-sex", 300], [day, "B mahsulot", "2-sex", 200]]
    job = submit_file(client, manager, "custom.xlsx", xlsx(rows), record_type="production_report")
    mapping = {m["source_column"]: (m["target_field"], m["mapped_by"]) for m in job["items_detail"][0]["tables"][0]["mapping"]}
    assert mapping["Kun"] == ("production_date", "rule") and mapping["Uchastok"] == ("department", "rule")
    assert mapping["Nomi"] == ("product", "ai")
    recs = records(client, manager, job["id"])
    assert recs["total"] == 2 and all(r["review_group"] == "needs_approval" for r in recs["items"])


def test_scenario_b_column_mapping_correction_and_template(client, world):
    manager = world.login(client, "manager")
    day = (date.today() - timedelta(days=1)).isoformat()
    rows = [["Ustun-1", "Ustun-2", "Ustun-3", "Ustun-4"], [day, "A mahsulot", "1-sex", 300], [day, "B mahsulot", "2-sex", 200]]
    job = submit_file(client, manager, "custom.xlsx", xlsx(rows), record_type="production_report")
    item = job["items_detail"][0]
    table = item["tables"][0]
    assert any("required field" in w for w in item["warnings"])
    recs = records(client, manager, job["id"])
    assert all(r["review_group"] == "needs_correction" for r in recs["items"])  # nothing recognised, nothing invented

    mapping = {0: "production_date", 1: "product", 2: "department", 3: "qty_produced"}
    r = client.put(f"/api/v1/agent/jobs/{job['id']}/items/{item['id']}/mapping", headers=manager,
                   json={"table_seq": table["table_seq"], "record_type": "production_report", "mapping": mapping})
    assert r.status_code == 200, r.text
    recs = records(client, manager, job["id"])
    assert recs["total"] == 2 and all(r["review_group"] == "ready" for r in recs["items"]), recs
    item = client.get(f"/api/v1/agent/jobs/{job['id']}", headers=manager).json()["items_detail"][0]
    assert not any("required field" in w for w in item["warnings"])  # the fixed mapping clears its warning

    # The same layout next time (another day's report) is mapped from the saved template.
    other_day = (date.today() - timedelta(days=2)).isoformat()
    rows[1][0], rows[2][0] = other_day, other_day
    job2 = submit_file(client, manager, "custom-2.xlsx", xlsx(rows))
    assert {m["mapped_by"] for m in job2["items_detail"][0]["tables"][0]["mapping"]} == {"template"}
    assert all(r["review_group"] == "ready" for r in records(client, manager, job2["id"])["items"])


@pytest.mark.parametrize("fmt", ["docx", "pdf"])
def test_scenario_b_tables_in_documents_become_one_record_per_row(client, world, fmt):
    manager = world.login(client, "manager")
    d1, d2 = ((date.today() - timedelta(days=n)).strftime("%d.%m.%Y") for n in (1, 2))
    rows = [["Sana", "Mahsulot", "Sex", "Smena", "Ishlab chiqarildi", "Sifatli", "Brak"],
            [d1, "A-001", "1-sex", "1-smena", "610", "600", "10"], [d2, "B-001", "2-sex", "2-smena", "50", "50", "0"]]
    build = docx_with_table if fmt == "docx" else pdf_with_table
    job = submit_file(client, manager, f"jadval.{fmt}", build("Ishlab chiqarish jadvali", rows))
    assert job["status"] == "awaiting_review", job
    item = job["items_detail"][0]
    assert item["detected_record_type"] == "production_report" and item["tables"][0]["record_type"] == "production_report"
    recs = records(client, manager, job["id"])["items"]
    assert len(recs) == 2
    for rec_id, (_, product, dep, _, produced, accepted, defective) in zip((r["id"] for r in recs), rows[1:], strict=True):
        rec = client.get(f"/api/v1/agent/records/{rec_id}", headers=manager).json()
        fields = {f["field"]: f for f in rec["fields"]}
        assert fields["product"]["match"]["label"].startswith(product) and fields["department"]["match"]["label"].startswith(dep)
        assert [fields[k]["normalized_value"] for k in ("qty_produced", "qty_accepted", "qty_defective")] == [produced, accepted, defective]
        assert fields["qty_produced"]["sources"][0]["cell"]
        assert rec["review_group"] != "needs_correction", rec["issues"]


def test_scenario_b_ambiguous_numbers_and_fractional_counts_need_approval(client, world):
    manager = world.login(client, "manager")
    days = [(date.today() - timedelta(days=n)).strftime("%d.%m.%Y") for n in (1, 2, 3)]
    tsv = ("Sana\tMahsulot\tSex\tSmena\tIshlab chiqarildi\tSifatli\tBrak\tBirlik\n"
           f"{days[0]}\tA-001\t1-sex\t1-smena\t1.500\t1.500\t0\tdona\n"  # '1.500': 1500 or 1.5?
           f"{days[1]}\tB-001\t2-sex\t2-smena\t612,5\t600\t12,5\tdona\n"  # half a piece
           f"{days[2]}\tA-001\t1-sex\t2-smena\t300\t300\t0\tdona")
    job = submit_text(client, manager, tsv)
    recs = {r["seq"]: r for r in records(client, manager, job["id"])["items"]}
    assert [recs[n]["review_group"] for n in (1, 2, 3)] == ["needs_approval", "needs_approval", "ready"]
    first = client.get(f"/api/v1/agent/records/{recs[1]['id']}", headers=manager).json()
    fields = {f["field"]: f for f in first["fields"]}
    assert fields["qty_produced"]["normalized_value"] == "1500" and fields["qty_produced"]["state"] == "low_confidence"
    second = client.get(f"/api/v1/agent/records/{recs[2]['id']}", headers=manager).json()
    flagged = {i["field"] for i in second["issues"] if i["rule_code"] == "fractional_count"}
    assert flagged == {"qty_produced", "qty_defective"}


# --------------------------------------------------------------------------- Scenario C


def test_scenario_c_pdf_purchase_request(client, world, owner_engine):
    manager = world.login(client, "manager")
    today = date.today()
    pdf = purchase_request_pdf("ZK-2026-015", today, today + timedelta(days=14), "2-sex",
                               [("Цемент М400", "50", "мешок", "65000"), ("Стальной лист 2мм", "1200", "кг", "14500"),
                                ("Перчатки рабочие", "40", "пар", "")])
    job = submit_file(client, manager, "zayavka.pdf", pdf)
    assert job["status"] == "awaiting_review", job
    assert job["items_detail"][0]["parser"] == "pdfplumber" and job["items_detail"][0]["page_count"] == 1
    rec_id = records(client, manager, job["id"])["items"][0]["id"]
    rec = client.get(f"/api/v1/agent/records/{rec_id}", headers=manager).json()
    assert rec["record_type"] == "purchase_request"
    header = {f["field"]: f for f in rec["fields"]}
    assert header["external_number"]["normalized_value"] == "ZK-2026-015"
    assert header["request_date"]["normalized_value"] == today.isoformat()
    assert header["department"]["match"]["label"].startswith("2-sex")
    # Source references point at the page and a bounding box in the original PDF.
    src = header["request_date"]["sources"][0]
    assert src["page"] == 1 and src["bbox"] and len(src["bbox"]) == 4
    # Missing information is flagged, not filled in.
    assert header["supplier"]["state"] == "missing"
    assert "supplier_not_specified" in {i["rule_code"] for i in rec["issues"]}
    lines = [{f["field"].split(".")[-1]: f for f in line} for line in rec["lines"]]
    assert len(lines) == 3
    assert lines[0]["item"]["match"]["label"].startswith("RM-CEM") and lines[0]["unit"]["normalized_value"] == "bag"
    assert lines[1]["unit"]["normalized_value"] == "kg"
    assert lines[2]["item"]["match"]["id"] is None  # not in the catalog: requested by description
    assert rec["review_group"] != "needs_correction", rec["issues"]

    assert client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=manager).status_code == 200
    job = commit(client, manager, job["id"])
    assert job["status"] == "completed"
    pr = owner_query(owner_engine, "SELECT id, status, external_number, source_file_id IS NOT NULL, "
                     "(SELECT count(*) FROM supply.purchase_request_lines l WHERE l.request_id = p.id) "
                     "FROM supply.purchase_requests p WHERE source_record_id = :rid", rid=rec_id)
    assert len(pr) == 1
    pr_id, status, ext, has_source, n_lines = pr[0]
    assert (status, ext, has_source, n_lines) == ("submitted", "ZK-2026-015", True, 3)
    # Routed to the procurement approval workflow: an approval task and notifications for approvers.
    tasks = owner_query(owner_engine, "SELECT title FROM core.tasks WHERE ref_type = 'purchase_request' AND ref_id = :p", p=pr_id)
    assert tasks and "Review purchase request" in tasks[0][0]
    admin = world.login(client, "admin")
    notes = client.get("/api/v1/notifications", headers=admin).json()
    assert any(n["kind"] == "purchase_request.submitted" for n in notes["items"])


# --------------------------------------------------------------------------- Scenario D


def test_scenario_d_duplicate_prevention(client, world, owner_engine):
    manager = world.login(client, "manager")
    day = (date.today() - timedelta(days=1))
    data = xlsx([["Sana", "Mahsulot", "Sex", "Ishlab chiqarildi", "Sifatli", "Brak"],
                 [day, "A mahsulot", "1-sex", 400, 390, 10], [day, "B mahsulot", "2-sex", 250, 245, 5]])
    job = submit_file(client, manager, "daily.xlsx", data)
    client.post(f"/api/v1/agent/jobs/{job['id']}/approve", headers=manager, json={"group": "ready"})
    commit(client, manager, job["id"])
    count = "SELECT count(*) FROM production.production_reports"
    assert owner_query(owner_engine, count)[0][0] == 2

    # Same file again: detected before any processing.
    r = client.post("/api/v1/agent/jobs", headers=manager, files=[("files", ("daily-copy.xlsx", data))])
    assert r.status_code == 409 and r.json()["code"] == "duplicate_upload"
    assert r.json()["duplicate_of_job_id"] == job["id"]

    # Forced re-processing still writes nothing: every row matches an existing report.
    job2 = submit_file(client, manager, "daily-copy.xlsx", data, force="true")
    assert job2["duplicate_of_job_id"] == job["id"]
    recs = records(client, manager, job2["id"])
    assert all(r["review_group"] == "needs_correction" for r in recs["items"])
    result = client.post(f"/api/v1/agent/jobs/{job2['id']}/approve", headers=manager, json={"group": "ready"}).json()
    assert result["approved"] == []
    r = client.post(f"/api/v1/agent/jobs/{job2['id']}/commit", headers=manager)
    assert r.status_code == 422 and r.json()["code"] == "nothing_to_commit"
    assert owner_query(owner_engine, count)[0][0] == 2

    # Idempotency-Key on submission returns the same job instead of a new one.
    headers = {**manager, "Idempotency-Key": "upload-42"}
    text_input = "Kecha 10 dona A mahsulot ishlab chiqarildi. Mas'ul sex — 2-sex."
    first = client.post("/api/v1/agent/jobs", headers=headers, data={"text": text_input}).json()
    second = client.post("/api/v1/agent/jobs", headers=headers, data={"text": text_input}).json()
    assert first["id"] == second["id"]


# --------------------------------------------------------------------------- Scenario E


def test_scenario_e_authorization(client, world, owner_engine):
    manager_a = world.login(client, "manager")
    job = submit_text(client, manager_a, UZ_TEXT)

    # A user of company B cannot reach company A, whatever header they send.
    as_b_in_a = world.login(client, "manager_b", world.a)
    for path in (f"/api/v1/agent/jobs/{job['id']}", "/api/v1/production/reports", "/api/v1/master/products", "/api/v1/company"):
        r = client.get(path, headers=as_b_in_a)
        assert r.status_code == 403 and r.json()["code"] == "company_access_denied", path
    # In their own company, A's resources simply do not exist.
    as_b = world.login(client, "manager_b", world.b)
    assert client.get(f"/api/v1/agent/jobs/{job['id']}", headers=as_b).status_code == 404
    rec_id = records(client, manager_a, job["id"])["items"][0]["id"]
    assert client.get(f"/api/v1/agent/records/{rec_id}", headers=as_b).status_code == 404
    assert client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=as_b).status_code == 404
    products_b = client.get("/api/v1/master/products", headers=as_b).json()["items"]
    assert {p["id"] for p in products_b}.isdisjoint({str(v) for v in world.a.products.values()})

    # An API key is bound to its company.
    admin_b = world.login(client, "admin", world.b)
    key = client.post("/api/v1/api-clients", headers=admin_b, json={"name": "ERP", "scopes": ["agent.submit", "agent.read"]}).json()["api_key"]
    r = client.get(f"/api/v1/agent/jobs/{job['id']}", headers={"Authorization": f"Bearer {key}", "X-Company-Id": world.a.id})
    assert r.status_code == 401 and r.json()["code"] == "company_mismatch"
    r = client.get(f"/api/v1/agent/jobs/{job['id']}", headers={"Authorization": f"Bearer {key}"})
    assert r.status_code == 404
    assert client.get("/api/v1/production/reports", headers={"Authorization": f"Bearer {key}"}).status_code == 403  # scope

    # Unauthenticated and role-limited access.
    assert client.get("/api/v1/agent/jobs").status_code == 401
    viewer = world.login(client, "viewer")
    assert client.post("/api/v1/agent/jobs", headers=viewer, data={"text": "x"}).status_code == 403

    # Department scoping: a manager limited to 2-sex cannot see or approve 1-sex data.
    scoped = world.login(client, "scoped")
    client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=manager_a)
    commit(client, manager_a, job["id"])
    reports = client.get("/api/v1/production/reports", headers=scoped).json()["items"]
    assert reports == []
    report_id = owner_query(owner_engine, "SELECT id FROM production.production_reports")[0][0]
    assert client.get(f"/api/v1/production/reports/{report_id}", headers=scoped).status_code == 404

    # Database level: under company B's RLS context, A's rows are invisible.
    from app.db.session import SessionLocal, bind_tenant

    with SessionLocal() as s:
        bind_tenant(s, world.b.id)
        assert s.execute(text("SELECT count(*) FROM production.production_reports")).scalar() == 0
        assert s.execute(text("SELECT count(*) FROM agent.ingestion_jobs")).scalar() == 0
        bind_tenant(s, world.a.id)
        assert s.execute(text("SELECT count(*) FROM agent.ingestion_jobs")).scalar() == 1
    with SessionLocal() as s:  # no tenant context at all: nothing
        assert s.execute(text("SELECT count(*) FROM core.products")).scalar() == 0


# --------------------------------------------------------------------------- Scenario F


def test_scenario_f_processing_failure_and_retry(client, world, monkeypatch, owner_engine):
    from app.ai.providers.demo import MockProvider

    manager = world.login(client, "manager")
    original = MockProvider.generate
    calls = {"n": 0}

    def flaky(self, **kwargs):
        calls["n"] += 1
        if kwargs["task"].prompt_id == "extract" and calls["n"] <= 2:
            raise RuntimeError("worker lost connection to the AI provider")
        return original(self, **kwargs)

    monkeypatch.setattr(MockProvider, "generate", flaky)
    job = submit_text(client, manager, UZ_TEXT)
    assert job["status"] == "failed"
    assert job["error"]["stage"] == "interpret" and job["error"]["retryable"] is True
    runs = [(r["stage"], r["status"]) for r in job["stage_runs"]]
    assert ("parse", "succeeded") in runs and ("interpret", "failed") in runs

    r = client.post(f"/api/v1/agent/jobs/{job['id']}/retry", headers=manager)
    assert r.status_code == 202 and r.json()["resumed"] == "process"
    job = wait_job(client, manager, job["id"])
    assert job["status"] == "awaiting_review" and job["attempt"] == 2
    runs = [(r["stage"], r["status"]) for r in job["stage_runs"]]
    assert runs.count(("parse", "succeeded")) == 1, "parse is not repeated: retry resumes from the failed stage"
    assert ("interpret", "succeeded") in runs
    assert records(client, manager, job["id"])["total"] == 1


def test_scenario_f_unreadable_file_fails_at_parse_and_later_stages_do_not_run(client, world):
    manager = world.login(client, "manager")
    job = submit_file(client, manager, "broken.pdf", b"%PDF-1.4 garbage")
    assert job["status"] == "failed"
    assert job["error"]["stage"] == "parse" and job["error"]["retryable"] is False, job["error"]
    assert [(r["stage"], r["status"]) for r in job["stage_runs"]] == [("ingest", "succeeded"), ("parse", "failed")]

    # Retrying fails the same way, still at parse, without recording later stages as succeeded.
    assert client.post(f"/api/v1/agent/jobs/{job['id']}/retry", headers=manager).status_code == 202
    job = wait_job(client, manager, job["id"])
    assert job["status"] == "failed" and job["error"]["stage"] == "parse" and job["attempt"] == 2
    assert {r["stage"] for r in job["stage_runs"]} == {"ingest", "parse"}


def test_scenario_f_commit_crash_mid_batch_is_resumed_without_duplicates(client, world, monkeypatch, owner_engine):
    from app.config import get_settings
    from app.modules.production import service as production

    monkeypatch.setattr(get_settings(), "agent_commit_batch_size", 2)
    manager = world.login(client, "manager")
    rows = [["Sana", "Mahsulot", "Sex", "Ishlab chiqarildi"]]
    for n in range(1, 6):
        rows.append([date.today() - timedelta(days=n), "A mahsulot", "1-sex", 100 + n])
    job = submit_file(client, manager, "five.xlsx", xlsx(rows))
    assert len(client.post(f"/api/v1/agent/jobs/{job['id']}/approve", headers=manager, json={"group": None,
                           "record_ids": [r["id"] for r in records(client, manager, job["id"])["items"]]}).json()["approved"]) == 5

    original = production.create_report
    calls = {"n": 0}

    def crashing(*args, **kwargs):
        calls["n"] += 1
        if calls["n"] == 4:
            raise ConnectionError("database connection dropped mid-batch")
        return original(*args, **kwargs)

    monkeypatch.setattr(production, "create_report", crashing)
    job = commit(client, manager, job["id"])
    assert job["status"] == "failed" and job["error"]["stage"] == "commit"
    count = "SELECT count(*) FROM production.production_reports"
    assert owner_query(owner_engine, count)[0][0] == 2, "first batch committed, second rolled back"
    assert ("commit", "failed") in [(r["stage"], r["status"]) for r in job["stage_runs"]]

    r = client.post(f"/api/v1/agent/jobs/{job['id']}/retry", headers=manager)
    assert r.json()["resumed"] == "commit"
    job = wait_job(client, manager, job["id"])
    assert job["status"] == "completed"
    assert owner_query(owner_engine, count)[0][0] == 5
    assert owner_query(owner_engine, "SELECT count(*), count(DISTINCT record_id) FROM agent.committed_writes")[0] == (5, 5)


def test_scenario_f_commit_is_idempotent(client, world):
    from app.agent.models import ExtractedRecord, IngestionJob
    from app.agent.pipeline.commit import commit_record
    from app.agent.pipeline.review import build_env
    from app.db.session import SessionLocal, bind_tenant

    manager = world.login(client, "manager")
    job = submit_text(client, manager, UZ_TEXT)
    rec_id = records(client, manager, job["id"])["items"][0]["id"]
    client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=manager)
    commit(client, manager, job["id"])
    with SessionLocal() as s:
        bind_tenant(s, world.a.id)
        record = s.get(ExtractedRecord, rec_id)
        j = s.get(IngestionJob, record.job_id)
        replay = commit_record(s, record, j, build_env(s, j.company_id, j))
        assert replay["replayed"] is True
        assert s.execute(text("SELECT count(*) FROM production.production_reports")).scalar() == 1


# --------------------------------------------------------------------------- Scenario G


def test_scenario_g_auditability(client, world, owner_engine):
    operator = world.login(client, "operator")
    manager = world.login(client, "manager")
    admin = world.login(client, "admin")
    job = submit_text(client, operator, UZ_TEXT)
    rec_id = records(client, operator, job["id"])["items"][0]["id"]
    client.patch(f"/api/v1/agent/records/{rec_id}", headers=operator, json={"fields": {"notes": "Smena oxirida kiritildi"}})
    client.post(f"/api/v1/agent/records/{rec_id}/approve", headers=manager, json={"comment": "Tekshirildi"})
    commit(client, manager, job["id"])
    report_id = owner_query(owner_engine, "SELECT id FROM production.production_reports WHERE source_record_id = :r", r=rec_id)[0][0]

    assert client.get(f"/api/v1/audit/trace/production_report/{report_id}", headers=operator).status_code == 403
    trace = client.get(f"/api/v1/audit/trace/production_report/{report_id}", headers=admin).json()
    agent = trace["agent"]
    assert trace["created_via"] == "biwork_agent"
    assert agent["original_input"]["text"] == UZ_TEXT
    assert agent["original_input"]["submitted_by"] == "Operator"
    extracted = {v["field"]: v for v in agent["extracted_values"]}
    assert extracted["qty_produced"]["raw_value"] == "500" and extracted["unit"]["raw_value"] == "dona"
    assert extracted["unit"]["normalized_value"] == "pcs"
    assert extracted["notes"]["extracted_by"] == "user" and extracted["notes"]["edited_by"] == "Operator"
    assert any(v["rule"] == "quantities_reconciled" for v in agent["validation_results"])
    decisions = [(a["decision"], a["by"]) for a in agent["approvals"]]
    assert ("correct", "Operator") in decisions and ("approve", "Manager") in decisions
    assert agent["processing"]["pipeline_version"] and "extract@v1" in agent["processing"]["prompt_version"]
    assert agent["processing"]["ai_provider"] == "demo" and agent["processing"]["ai_is_mock"] is True
    assert {c["task"] for c in agent["processing"]["ai_calls"]} == {"classify", "extract"}
    assert agent["database_write"]["target_table"] == "production.production_reports"
    created = next(e for e in trace["audit_events"] if e["action"] == "production_report.created")
    assert created["changes"]["qty_produced"] == "500.0000" or float(created["changes"]["qty_produced"]) == 500
    assert created["context"]["ingestion_job_id"] == job["id"] and created["context"]["approved_by"]

    # Every event has a readable summary (the overview's recent activity shows it).
    activity = client.get("/api/v1/analytics/overview", headers=admin).json()["recent_activity"]
    by_action = {a["action"]: a["summary"] for a in activity}
    assert {"agent.record.corrected", "agent.job.commit_requested", "agent.record.approved"} <= by_action.keys()
    assert all(a["summary"] for a in activity), activity
    assert by_action["agent.record.corrected"].startswith("Record #1 corrected (notes): A-001")

    # The audit chain verifies, and tampering is detected.
    assert client.get("/api/v1/audit/verify", headers=admin).json()["ok"] is True
    with owner_engine.begin() as conn:
        conn.execute(text("UPDATE core.audit_events SET summary = 'tampered' WHERE action = 'production_report.created'"))
    result = client.get("/api/v1/audit/verify", headers=admin).json()
    assert result["ok"] is False and result["broken_at"]


@pytest.mark.parametrize("who", ["operator", "manager"])
def test_audit_log_requires_permission(client, world, who):
    headers = world.login(client, who)
    r = client.get("/api/v1/audit/events", headers=headers)
    assert r.status_code == (403 if who == "operator" else 200)


