"""Personnel register: employees as business records (not login accounts)."""

from __future__ import annotations

import uuid
from datetime import date

from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.context import RequestContext
from app.core.errors import Conflict
from app.core.manifest import WriteResult
from app.core.models import Department, Employee
from app.core.services.master_data import check_reference
from app.core.text import clean_text, normalize_key

EMPLOYEE_STATUSES = ("active", "on_leave", "dismissed")


class EmployeeIn(BaseModel):
    full_name: str = Field(min_length=2, max_length=250)
    personnel_number: str | None = Field(default=None, max_length=40)
    position: str | None = Field(default=None, max_length=200)
    department_id: uuid.UUID | None = None
    hire_date: date | None = None
    phone: str | None = Field(default=None, max_length=50)
    status: str = "active"


def create_employee(session: Session, ctx: RequestContext, data: EmployeeIn, source_record_id: uuid.UUID | None = None) -> Employee:
    ctx.require("master.write")
    if data.personnel_number and session.execute(select(Employee.id).where(
        Employee.company_id == ctx.company_id, Employee.personnel_number == data.personnel_number,
    )).first():
        raise Conflict(f"An employee with personnel number {data.personnel_number} already exists", code="duplicate_employee")
    check_reference(session, ctx, Department, data.department_id, "department", "department_id")
    employee = Employee(
        company_id=ctx.company_id, personnel_number=data.personnel_number, full_name=clean_text(data.full_name, 250),
        normalized_name=normalize_key(data.full_name)[:250], position=clean_text(data.position, 200),
        department_id=data.department_id, hire_date=data.hire_date, phone=data.phone,
        status=data.status if data.status in EMPLOYEE_STATUSES else "active",
        created_by=ctx.user_id, updated_by=ctx.user_id, source_record_id=source_record_id,
    )
    session.add(employee)
    session.flush()
    audit.record(session, "employee.created", ctx=ctx, entity_type="employee", entity_id=employee.id,
                 changes={"personnel_number": employee.personnel_number, "full_name": employee.full_name,
                          "position": employee.position})
    return employee


def write_employee_from_agent(session: Session, ctx: RequestContext, data: BaseModel, source_record_id: uuid.UUID | None) -> WriteResult:
    p = data.model_dump()
    employee = create_employee(session, ctx, EmployeeIn(
        full_name=p["full_name"], personnel_number=p.get("personnel_number"), position=p.get("position"),
        department_id=p.get("department_id"), hire_date=p.get("hire_date"), phone=p.get("phone"),
        status=p.get("status") or "active",
    ), source_record_id=source_record_id)
    label = f"{employee.personnel_number} — {employee.full_name}" if employee.personnel_number else employee.full_name
    return WriteResult("core.employees", employee.id, employee.personnel_number, label)
