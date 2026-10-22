"""API conventions under misuse: malformed query input, idempotency scope, report cancellation rights."""

from __future__ import annotations

from datetime import date, timedelta

import pytest


@pytest.mark.parametrize("path, params", [
    ("/api/v1/production/reports", {"cursor": "MTIz"}),  # base64 of 123
    ("/api/v1/production/reports", {"cursor": "WyJ4Il0"}),  # ["x"]
    ("/api/v1/production/reports", {"cursor": "WyJnYXJiYWdlLWRhdGUiLCAiMSJd"}),  # ["garbage-date", "1"]
    ("/api/v1/production/reports", {"cursor": "!!!"}),
    ("/api/v1/master/products", {"cursor": "W251bGwsIG51bGxd"}),  # [null, null]
    ("/api/v1/master/products", {"cursor": "WyJ4IiwgIjEyMyJd"}),  # ["x", "123"]: not a uuid
])
def test_malformed_cursor_is_a_client_error(client, world, path, params):
    r = client.get(path, headers=world.login(client, "manager"), params=params)
    assert r.status_code == 400 and r.json()["code"] == "invalid_cursor", r.text


@pytest.mark.parametrize("path, params", [
    ("/api/v1/audit/events", {"before_id": "99999999999999999999"}),
    ("/api/v1/production/calendar", {"month": "2026-13"}),
    ("/api/v1/production/calendar", {"month": "0000-01"}),
    ("/api/v1/production/calendar", {"month": "9999-12"}),
])
def test_out_of_range_query_values_are_validation_errors(client, world, path, params):
    r = client.get(path, headers=world.login(client, "admin"), params=params)
    assert r.status_code == 422, r.text


def test_valid_pages_and_months_still_work(client, world):
    headers = world.login(client, "manager")
    first = client.get("/api/v1/master/products", headers=headers, params={"limit": 2}).json()
    rest = client.get("/api/v1/master/products", headers=headers, params={"limit": 2, "cursor": first["next_cursor"]}).json()
    assert len(first["items"]) == 2 and len(rest["items"]) == 2
    assert not {p["id"] for p in first["items"]} & {p["id"] for p in rest["items"]}
    assert client.get("/api/v1/production/calendar", headers=headers, params={"month": "2026-12"}).status_code == 200


def test_idempotency_keys_are_scoped_to_the_caller(client, world):
    body = {"movement_date": date.today().isoformat(), "movement_type": "receipt", "warehouse_id": str(world.a.warehouses["RM"]),
            "product_id": str(world.a.products["RM-CEM"]), "quantity": "10", "unit": "bag"}
    manager = {**world.login(client, "manager"), "Idempotency-Key": "K-1"}
    first = client.post("/api/v1/inventory/movements", headers=manager, json=body)
    assert first.status_code == 201
    again = client.post("/api/v1/inventory/movements", headers=manager, json=body)
    assert again.headers.get("idempotent-replay") == "true" and again.json()["id"] == first.json()["id"]

    # Another user presenting the same key gets no one else's response, and is checked for permission.
    r = client.post("/api/v1/inventory/movements", headers={**world.login(client, "viewer"), "Idempotency-Key": "K-1"}, json=body)
    assert r.status_code == 403 and "idempotent-replay" not in r.headers
    r = client.post("/api/v1/inventory/movements", headers={**world.login(client, "operator"), "Idempotency-Key": "K-1"}, json=body)
    assert r.status_code == 201 and r.json()["id"] != first.json()["id"]


def test_report_writers_cancel_drafts_and_approvers_cancel_or_correct_approved_reports(client, world):
    operator, manager = world.login(client, "operator"), world.login(client, "manager")
    body = {"production_date": (date.today() - timedelta(days=1)).isoformat(), "product_id": str(world.a.products["A-001"]),
            "department_id": str(world.a.departments["SEX-1"]), "qty_produced": "100"}
    draft = client.post("/api/v1/production/reports", headers=operator, json=body).json()
    r = client.post(f"/api/v1/production/reports/{draft['id']}/cancel", headers=operator, json={"reason": "Entered twice"})
    assert r.status_code == 200 and r.json()["status"] == "cancelled"

    approved = client.post("/api/v1/production/reports", headers=manager, json={**body, "approve": True}).json()
    r = client.post(f"/api/v1/production/reports/{approved['id']}/cancel", headers=operator, json={"reason": "Wrong"})
    assert r.status_code == 403 and r.json()["missing"] == ["production.approve"]
    r = client.post(f"/api/v1/production/reports/{approved['id']}/correct", headers=operator,
                    json={"report": {**body, "qty_produced": "90"}, "reason": "Recount"})
    assert r.status_code == 403
    r = client.post(f"/api/v1/production/reports/{approved['id']}/cancel", headers=manager, json={"reason": "Wrong product"})
    assert r.status_code == 200 and r.json()["status"] == "cancelled"
    viewer = world.login(client, "viewer")
    other = client.post("/api/v1/production/reports", headers=operator, json={**body, "qty_produced": "5"}).json()
    assert client.post(f"/api/v1/production/reports/{other['id']}/cancel", headers=viewer, json={"reason": "Nope"}).status_code == 403
