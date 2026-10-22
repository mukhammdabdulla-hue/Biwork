"""Identity and access: sessions after a password change, MFA re-enrolment, member administration."""

from __future__ import annotations

import uuid

import pyotp
from fastapi.testclient import TestClient

from tests.conftest import PASSWORD


def _login(client: TestClient, email: str, password: str = PASSWORD, **extra) -> dict:
    r = client.post("/api/v1/auth/login", json={"email": email, "password": password, **extra})
    assert r.status_code == 200, r.text
    return r.json()


def _bearer(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


# --------------------------------------------------------------------------- sessions


def test_password_change_keeps_this_session_and_ends_all_others(client, world):
    from app.main import app

    other = TestClient(app)  # a second browser
    first = _login(client, world.users["operator"])["access_token"]
    second = _login(other, world.users["operator"])["access_token"]

    r = client.post("/api/v1/auth/password", headers=_bearer(first), json={"current_password": PASSWORD, "new_password": PASSWORD})
    assert r.status_code == 422 and r.json()["code"] == "password_unchanged"

    r = client.post("/api/v1/auth/password", headers=_bearer(first),
                    json={"current_password": PASSWORD, "new_password": "New-Password-456"})
    assert r.status_code == 200, r.text
    fresh = r.json()["access_token"]
    assert fresh and r.json()["expires_in"] > 0

    # Access tokens issued before the change stop working at once, in every session.
    assert client.get("/api/v1/auth/me", headers=_bearer(first)).json()["code"] == "session_revoked"
    assert other.get("/api/v1/auth/me", headers=_bearer(second)).status_code == 401
    assert client.get("/api/v1/auth/me", headers=_bearer(fresh)).status_code == 200

    # This browser got a new refresh cookie and stays signed in; the other one is signed out.
    r = client.post("/api/v1/auth/refresh", headers={"X-Requested-With": "biwork"})
    assert r.status_code == 200 and client.get("/api/v1/auth/me", headers=_bearer(r.json()["access_token"])).status_code == 200
    assert other.post("/api/v1/auth/refresh", headers={"X-Requested-With": "biwork"}).status_code == 401
    _login(other, world.users["operator"], "New-Password-456")


def test_a_refresh_in_flight_during_a_password_change_is_revoked_too(client, world, owner_engine):
    import threading
    import time

    import pytest
    from sqlalchemy import text

    from app.config import get_settings
    from app.core.errors import Unauthorized
    from app.core.models import User
    from app.core.services import identity
    from app.db.session import SessionLocal

    user_id = uuid.UUID(_login(client, world.users["operator"])["user"]["id"])
    cookie = client.cookies.get(get_settings().refresh_cookie_name)
    rotating, changing = SessionLocal(), SessionLocal()
    try:
        # A refresh request has written its new token but not committed yet ...
        pair = identity.rotate_refresh(rotating, cookie, None, None)
        rotating.flush()
        # ... when the password change starts: it waits for that refresh and then revokes its token too.
        pid = changing.execute(text("SELECT pg_backend_pid()")).scalar_one()
        user = changing.get(User, user_id)
        change = threading.Thread(target=lambda: (identity.change_password(changing, user, PASSWORD, "New-Password-456", None, None),
                                                  changing.commit()))
        change.start()
        deadline = time.monotonic() + 15
        with owner_engine.connect() as conn:
            while not conn.execute(text("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = :pid AND NOT granted)"), {"pid": pid}).scalar():
                assert time.monotonic() < deadline and change.is_alive(), "the password change did not wait for the refresh"
                time.sleep(0.02)
        rotating.commit()
        change.join(15)
        assert not change.is_alive()
    finally:
        rotating.close()
        changing.close()
    with SessionLocal() as s, pytest.raises(Unauthorized) as refused:
        identity.rotate_refresh(s, pair.refresh_token, None, None)
    assert refused.value.code == "session_expired"


def test_logout_ends_only_its_own_session(client, world):
    from app.main import app

    other = TestClient(app)
    _login(client, world.users["viewer"])
    token = _login(other, world.users["viewer"])["access_token"]
    assert client.post("/api/v1/auth/logout", headers={"X-Requested-With": "biwork"}).status_code == 204
    assert client.post("/api/v1/auth/refresh", headers={"X-Requested-With": "biwork"}).status_code == 401
    assert other.post("/api/v1/auth/refresh", headers={"X-Requested-With": "biwork"}).status_code == 200
    assert other.get("/api/v1/auth/me", headers=_bearer(token)).status_code == 200


def test_access_token_with_a_malformed_subject_is_rejected(client, world):
    from app.core.security import create_access_token

    token, _ = create_access_token(uuid.uuid4())
    assert client.get("/api/v1/auth/me", headers=_bearer(token)).json()["code"] == "account_disabled"
    import jwt

    from app.config import get_settings

    forged = jwt.encode({"sub": "not-a-uuid", "typ": "access", "iat": 1, "exp": 4102444800}, get_settings().jwt_signing_key, "HS256")
    r = client.get("/api/v1/auth/me", headers=_bearer(forged))
    assert r.status_code == 401 and r.json()["code"] == "invalid_token"


# --------------------------------------------------------------------------- MFA


def test_mfa_setup_cannot_remove_an_enabled_second_factor(client, world):
    email = world.users["manager"]
    headers = _bearer(_login(client, email)["access_token"])
    totp = pyotp.parse_uri(client.post("/api/v1/auth/mfa/setup", headers=headers).json()["otpauth_uri"])
    assert client.post("/api/v1/auth/mfa/confirm", headers=headers, json={"code": totp.now()}).status_code == 204

    # A bare access token is not enough to replace the authenticator, and MFA stays on.
    r = client.post("/api/v1/auth/mfa/setup", headers=headers)
    assert r.status_code == 422 and r.json()["code"] == "mfa_reauth_required"
    r = client.post("/api/v1/auth/mfa/setup", headers=headers, json={"code": "000000"})
    assert r.status_code == 422 and r.json()["code"] == "mfa_invalid"
    r = client.post("/api/v1/auth/mfa/setup", headers=headers, json={"password": "Wrong-Password-1"})
    assert r.status_code == 422 and r.json()["code"] == "invalid_password"
    assert client.get("/api/v1/auth/me", headers=headers).json()["mfa_enabled"] is True
    r = client.post("/api/v1/auth/login", json={"email": email, "password": PASSWORD})
    assert r.status_code == 401 and r.json()["code"] == "mfa_required"

    # With the current code a new authenticator can be enrolled; the old one works until it is confirmed.
    r = client.post("/api/v1/auth/mfa/setup", headers=headers, json={"code": totp.now()})
    assert r.status_code == 200
    replacement = pyotp.parse_uri(r.json()["otpauth_uri"])
    assert replacement.secret != totp.secret
    assert client.post("/api/v1/auth/login", json={"email": email, "password": PASSWORD}).json()["code"] == "mfa_required"
    _login(client, email, otp=totp.now())
    assert client.post("/api/v1/auth/mfa/confirm", headers=headers, json={"code": replacement.now()}).status_code == 204
    _login(client, email, otp=replacement.now())

    # The password is accepted instead of the code (e.g. a lost phone while still signed in).
    assert client.post("/api/v1/auth/mfa/setup", headers=headers, json={"password": PASSWORD}).status_code == 200
    assert client.get("/api/v1/auth/me", headers=headers).json()["mfa_enabled"] is True


# --------------------------------------------------------------------------- members


def _member_id(client, headers, email: str) -> str:
    return next(m["id"] for m in client.get("/api/v1/members", headers=headers).json() if m["email"] == email)


def test_user_admin_cannot_change_members_who_hold_more_permissions(client, world):
    admin = world.login(client, "admin")
    r = client.post("/api/v1/roles", headers=admin, json={"code": "rolemgr", "name": "Role manager",
                                                          "permissions": ["admin.roles", "admin.users", "production.read", "tasks.read"]})
    assert r.status_code == 201, r.text
    client.post("/api/v1/roles", headers=admin, json={"code": "reader", "name": "Reader", "permissions": ["production.read"]})
    for email, role in (("rolemgr@a.test", "rolemgr"), ("reader@a.test", "reader")):
        r = client.post("/api/v1/members", headers=admin, json={"email": email, "full_name": role, "role": role, "password": PASSWORD})
        assert r.status_code == 201, r.text
    r = client.post("/api/v1/auth/login", json={"email": "rolemgr@a.test", "password": PASSWORD})
    rolemgr = {**_bearer(r.json()["access_token"]), "X-Company-Id": world.a.id}

    target = _member_id(client, admin, world.users["admin"])
    workshop = str(world.a.departments["SEX-1"])
    for change in ({"is_active": False}, {"department_ids": [workshop]}, {"role": "reader"}):
        r = client.patch(f"/api/v1/members/{target}", headers=rolemgr, json=change)
        assert r.status_code == 403, (change, r.text)
    member = next(m for m in client.get("/api/v1/members", headers=admin).json() if m["id"] == target)
    assert member["is_active"] is True and member["department_ids"] == [] and member["role"]["code"] == "admin"

    # Members whose permissions the actor holds can still be managed.
    reader = _member_id(client, admin, "reader@a.test")
    r = client.patch(f"/api/v1/members/{reader}", headers=rolemgr, json={"is_active": False, "department_ids": [workshop]})
    assert r.status_code == 200 and r.json()["is_active"] is False


def test_company_admin_cannot_attach_or_probe_accounts_of_other_companies(client, world):
    platform_admin = world.login(client, "admin", world.b)
    r = client.post("/api/v1/members", headers=platform_admin,
                    json={"email": "badmin@b.test", "full_name": "B admin", "role": "admin", "password": PASSWORD})
    assert r.status_code == 201, r.text
    r = client.post("/api/v1/auth/login", json={"email": "badmin@b.test", "password": PASSWORD})
    b_admin = {**_bearer(r.json()["access_token"]), "X-Company-Id": world.b.id}

    unknown = client.post("/api/v1/members", headers=b_admin, json={"email": "nobody@x.test", "full_name": "x", "role": "viewer"})
    foreign = client.post("/api/v1/members", headers=b_admin, json={"email": world.users["manager"], "full_name": "x", "role": "viewer"})
    assert unknown.status_code == foreign.status_code == 422
    assert unknown.json()["detail"] == foreign.json()["detail"]  # no oracle, no profile data
    r = client.post("/api/v1/members", headers=b_admin,
                    json={"email": world.users["manager"], "full_name": "x", "role": "viewer", "password": PASSWORD + "x"})
    assert r.status_code == 409 and "full_name" not in r.json() and "last_login_at" not in r.json()
    manager = _login(client, world.users["manager"])
    assert [c["name"] for c in manager["user"]["companies"]] == ["Company A"]

    # A platform administrator can add any account; so can an admin who already administers that person elsewhere.
    r = client.post("/api/v1/members", headers=platform_admin, json={"email": world.users["viewer"], "full_name": "x", "role": "viewer"})
    assert r.status_code == 201 and r.json()["full_name"] == "Viewer"
    r = client.post("/api/v1/members", headers=world.login(client, "admin"),
                    json={"email": "badmin@b.test", "full_name": "x", "role": "admin"})
    assert r.status_code == 201
    r = client.post("/api/v1/members", headers=b_admin, json={"email": world.users["operator"], "full_name": "x", "role": "viewer"})
    assert r.status_code == 201 and r.json()["full_name"] == "Operator"
