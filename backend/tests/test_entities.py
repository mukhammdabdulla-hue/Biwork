"""New ingestible entities: employees, suppliers, business documents — schemas, deterministic
validation, duplicate detection, and writes through the authorized backend services."""

from __future__ import annotations

from datetime import date, timedelta

from tests.conftest import owner_query, records, wait_job
from tests.test_scenarios import commit, submit_text


def submit_payload(client, headers, payload, **extra):
    r = client.post("/api/v1/agent/jobs/json", headers=headers, json={"payload": payload, **extra})
    assert r.status_code == 202, r.text
    return wait_job(client, headers, r.json()["id"])


def detail(client, headers, job):
    rec = records(client, headers, job["id"])["items"][0]
    return client.get(f"/api/v1/agent/records/{rec['id']}", headers=headers).json()


def test_record_types_are_published_with_schemas(client, world):
    headers = world.login(client, "manager")
    types = {t["code"]: t for t in client.get("/api/v1/agent/record-types", headers=headers).json()}
    for code in ("product", "production_order", "production_report", "stock_movement", "employee", "supplier", "purchase_request",
                 "business_document", "task"):
        assert code in types and types[code]["enabled"], code
    assert {f["name"] for f in types["business_document"]["line_fields"]} == {"item", "quantity", "unit", "unit_price", "amount"}


def test_employee_from_text_and_duplicate_by_personnel_number(client, world, owner_engine):
    headers = world.login(client, "manager")
    text = ("Yangi xodim\nF.I.Sh: Karimov Aziz Bahodirovich\nTabel raqami: T-0457\nLavozim: Payvandchi\n"
            "Bo'lim: 1-sex\nIshga qabul qilingan sana: 01.10.2026")
    job = submit_text(client, headers, text)
    d = detail(client, headers, job)
    assert d["record_type"] == "employee" and d["payload"]["personnel_number"] == "T-0457"
    assert d["payload"]["hire_date"] == "2026-10-01" and d["proposed_change"]["references"]["department"]["id"]
    _approve_and_commit(client, headers, job)
    assert owner_query(owner_engine, "SELECT full_name, personnel_number, position FROM core.employees") == [
        ("Karimov Aziz Bahodirovich", "T-0457", "Payvandchi")]
    # The same person again is a blocking duplicate, not a second row.
    again = submit_text(client, headers, text, force="true")
    d = detail(client, headers, again)
    assert d["review_group"] == "needs_correction" and any(x["kind"] == "duplicate_record" for x in d["duplicates"])


def _approve_and_commit(client, headers, job):
    r = client.post(f"/api/v1/agent/jobs/{job['id']}/approve", headers=headers, json={"group": None})
    assert r.status_code == 200 and r.json()["approved"], r.text
    return commit(client, headers, job["id"])


def test_supplier_payload_validation_and_tin_duplicate(client, world, owner_engine):
    headers = world.login(client, "manager")
    job = submit_payload(client, headers, {"record_type": "supplier", "fields": {
        "name": "Olmaliq Metall MChJ", "tin": "305123987", "email": "sales@olmaliq.test", "address": "Olmaliq sh."}})
    d = detail(client, headers, job)
    assert d["review_group"] == "ready", d["issues"]
    _approve_and_commit(client, headers, job)
    row = owner_query(owner_engine, "SELECT code, name, tin FROM supply.suppliers WHERE tin = '305123987'")[0]
    assert row[0].startswith("SUP") and row[1] == "Olmaliq Metall MChJ"
    # Existing supplier from master data (same TIN) -> blocking duplicate; malformed e-mail -> invalid.
    job = submit_payload(client, headers, {"record_type": "supplier", "fields": {"name": "QQS", "tin": "200123456", "email": "nope"}})
    d = detail(client, headers, job)
    assert d["review_group"] == "needs_correction"
    assert any(x["kind"] == "duplicate_record" for x in d["duplicates"])
    assert any(i["rule_code"] == "email_format" for i in d["issues"])


def test_business_document_totals_are_checked_deterministically(client, world, owner_engine):
    headers = world.login(client, "manager")
    day = (date.today() - timedelta(days=2)).isoformat()
    lines = [{"item": "Sement M400", "quantity": "10", "unit": "qop", "unit_price": "55000", "amount": "550000"},
             {"item": "Po'lat list 2mm", "quantity": "100", "unit": "kg", "unit_price": "12000", "amount": "1300000"}]
    job = submit_payload(client, headers, {"record_type": "business_document", "fields": {
        "doc_type": "hisob-faktura", "number": "HF-112", "doc_date": day, "counterparty": "Qizilqumsement AJ",
        "total_amount": "1750000", "currency": "so'm", "lines": lines}})
    d = detail(client, headers, job)
    codes = {i["rule_code"] for i in d["issues"]}
    assert {"line_amount_mismatch", "total_mismatch"} <= codes and d["review_group"] == "needs_correction"
    assert d["proposed_change"]["references"]["counterparty"]["id"]  # matched to the existing supplier
    assert d["payload"]["doc_type"] == "invoice" and d["payload"]["currency"] == "UZS"

    lines[1]["amount"] = "1200000"
    job = submit_payload(client, headers, {"record_type": "business_document", "fields": {
        "doc_type": "invoice", "number": "HF-113", "doc_date": day, "counterparty": "Qizilqumsement AJ",
        "total_amount": "1750000", "currency": "UZS", "lines": lines}})
    d = detail(client, headers, job)
    assert not {"line_amount_mismatch", "total_mismatch"} & {i["rule_code"] for i in d["issues"]}, d["issues"]
    _approve_and_commit(client, headers, job)
    row = owner_query(owner_engine, "SELECT doc_type, number, total_amount, currency, supplier_id IS NOT NULL, "
                                    "jsonb_array_length(lines) FROM core.business_documents")
    assert row == [("invoice", "HF-113", 1750000, "UZS", True, 2)]
    # Registering the same invoice again is a blocking duplicate.
    job = submit_payload(client, headers, {"record_type": "business_document", "fields": {
        "doc_type": "invoice", "number": "HF-113", "doc_date": day, "counterparty": "Qizilqumsement AJ"}}, force=True)
    assert detail(client, headers, job)["review_group"] == "needs_correction"


def test_document_from_russian_text_in_demo_mode(client, world):
    headers = world.login(client, "manager")
    text = ("СЧЁТ-ФАКТУРА № 77/10\nДата: 15.10.2026\nПоставщик: Qizilqumsement AJ\nИтого: 550 000\nВалюта: сум\n\n"
            "| Наименование | Количество | Ед. изм | Цена | Сумма |\n|---|---|---|---|---|\n| Цемент М400 | 10 | мешок | 55000 | 550000 |")
    job = submit_text(client, headers, text)
    d = detail(client, headers, job)
    assert d["record_type"] == "business_document", d
    assert d["payload"]["number"] == "77/10" and d["payload"]["doc_date"] == "2026-10-15"
    assert d["payload"]["lines"][0]["amount"] == "550000" and d["ai"]["is_demo"]
