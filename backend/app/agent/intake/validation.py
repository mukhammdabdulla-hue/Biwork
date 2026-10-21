"""Stage 5: deterministic validation of an extraction.

Nothing here calls a model. The rules decide each field's status and list the
issues a reviewer must resolve, so a confident-sounding model output can never
skip these checks.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal

from pydantic import BaseModel

from app.agent.intake.schemas import (
    DocumentType,
    Extracted,
    ExtractionEnvelope,
    FieldReport,
    FieldStatus,
    InvoiceExtraction,
    IssueSeverity,
    SpecificationExtraction,
    ValidationIssue,
    WorkOrderExtraction,
)

REQUIRED_FIELDS: dict[DocumentType, tuple[str, ...]] = {
    DocumentType.INVOICE: ("invoice_number", "issue_date", "supplier_name", "currency", "total_amount"),
    DocumentType.WORK_ORDER: ("order_number", "product_name", "quantity_ordered"),
    DocumentType.SPECIFICATION: ("product_name",),
}

_PAYLOAD_ATTR = {
    DocumentType.INVOICE: "invoice",
    DocumentType.WORK_ORDER: "work_order",
    DocumentType.SPECIFICATION: "specification",
}

MONEY_TOLERANCE = Decimal("0.01")
_CURRENCY_RE = re.compile(r"^[A-Z]{3}$")


def _dec(value: float) -> Decimal:
    return Decimal(str(value))


@dataclass
class ValidationReport:
    fields: list[FieldReport] = field(default_factory=list)
    issues: list[ValidationIssue] = field(default_factory=list)

    def flag(
        self,
        path: str | None,
        code: str,
        message: str,
        *,
        severity: IssueSeverity = IssueSeverity.ERROR,
        status: FieldStatus | None = None,
    ) -> None:
        self.issues.append(ValidationIssue(field=path, code=code, severity=severity, message=message))
        if path is None or status is None:
            return
        for report in self.fields:
            if report.field == path:
                report.status = status


def validate_extraction(envelope: ExtractionEnvelope) -> ValidationReport:
    report = ValidationReport()
    doc_type = envelope.document_type

    if doc_type is DocumentType.UNKNOWN:
        report.flag(
            None,
            "unsupported_document_type",
            "The document is not a supported type (invoice, work order, specification).",
        )
        return report

    attr = _PAYLOAD_ATTR[doc_type]
    payload = getattr(envelope, attr)
    if payload is None:
        report.flag(attr, "missing_payload", f"Document was classified as {doc_type.value} but no data was extracted.")
        return report

    for other_type, other_attr in _PAYLOAD_ATTR.items():
        if other_type is not doc_type and getattr(envelope, other_attr) is not None:
            report.flag(
                other_attr,
                "unexpected_payload",
                f"Ignored {other_attr} data on a document classified as {doc_type.value}.",
                severity=IssueSeverity.WARNING,
            )

    if envelope.document_type_confidence == "low":
        report.flag(
            None,
            "low_confidence_classification",
            f"Document type '{doc_type.value}' was identified with low confidence.",
            severity=IssueSeverity.WARNING,
        )

    _collect_fields(report, attr, payload, REQUIRED_FIELDS[doc_type])

    if isinstance(payload, InvoiceExtraction):
        _check_invoice(report, attr, payload)
    elif isinstance(payload, WorkOrderExtraction):
        _check_work_order(report, attr, payload)
    elif isinstance(payload, SpecificationExtraction):
        _check_specification(report, attr, payload)

    return report


def _collect_fields(report: ValidationReport, prefix: str, payload: BaseModel, required: tuple[str, ...]) -> None:
    for name in type(payload).model_fields:
        value = getattr(payload, name)
        path = f"{prefix}.{name}"
        if isinstance(value, Extracted):
            if value.value is None:
                status = FieldStatus.MISSING
            elif value.confidence == "high":
                status = FieldStatus.HIGH_CONFIDENCE
            else:
                status = FieldStatus.LOW_CONFIDENCE
            report.fields.append(
                FieldReport(
                    field=path,
                    value=value.value,
                    status=status,
                    source_page=value.source_page,
                    source_text=value.source_text,
                )
            )
            if status is FieldStatus.MISSING and name in required:
                report.flag(path, "missing_required", f"Required field '{name}' was not found in the document.")
            elif status is FieldStatus.LOW_CONFIDENCE:
                report.flag(
                    path,
                    "low_confidence",
                    f"'{name}' was read with low confidence and must be verified.",
                    severity=IssueSeverity.WARNING,
                )
        elif isinstance(value, list):
            for index, item in enumerate(value):
                item_path = f"{path}[{index}]"
                status = FieldStatus.HIGH_CONFIDENCE if item.confidence == "high" else FieldStatus.LOW_CONFIDENCE
                report.fields.append(
                    FieldReport(
                        field=item_path,
                        value=item.model_dump(exclude={"confidence", "source_page"}),
                        status=status,
                        source_page=item.source_page,
                    )
                )
                if status is FieldStatus.LOW_CONFIDENCE:
                    report.flag(
                        item_path,
                        "low_confidence",
                        f"Row {index + 1} of {name} was read with low confidence and must be verified.",
                        severity=IssueSeverity.WARNING,
                    )


def _check_dates(
    report: ValidationReport, path: str, start: date | None, end: date | None, start_name: str, end_name: str
) -> None:
    if start is not None and end is not None and end < start:
        report.flag(
            path,
            "date_order",
            f"{end_name} ({end.isoformat()}) is before {start_name} ({start.isoformat()}).",
            status=FieldStatus.CONFLICTING,
        )


def _check_invoice(report: ValidationReport, prefix: str, inv: InvoiceExtraction) -> None:
    currency = inv.currency.value
    if currency is not None and not _CURRENCY_RE.match(currency):
        report.flag(
            f"{prefix}.currency",
            "invalid_currency",
            f"'{currency}' is not an ISO 4217 currency code.",
            status=FieldStatus.INVALID,
        )

    _check_dates(report, f"{prefix}.due_date", inv.issue_date.value, inv.due_date.value, "issue date", "due date")

    line_totals: list[Decimal] = []
    for index, line in enumerate(inv.line_items):
        path = f"{prefix}.line_items[{index}]"
        if line.quantity is not None and line.unit_price is not None and line.line_total is not None:
            expected = _dec(line.quantity) * _dec(line.unit_price)
            if abs(expected - _dec(line.line_total)) > MONEY_TOLERANCE:
                report.flag(
                    path,
                    "line_total_mismatch",
                    f"Row {index + 1}: {line.quantity} x {line.unit_price} = {expected}, "
                    f"but the line total is {line.line_total}.",
                    status=FieldStatus.CONFLICTING,
                )
        if line.quantity is not None and line.quantity <= 0:
            report.flag(path, "invalid_quantity", f"Row {index + 1}: quantity must be positive.", status=FieldStatus.INVALID)
        if line.line_total is not None:
            line_totals.append(_dec(line.line_total))

    subtotal, tax, total = inv.subtotal.value, inv.tax_amount.value, inv.total_amount.value
    lines_complete = bool(inv.line_items) and len(line_totals) == len(inv.line_items)
    if lines_complete and subtotal is not None:
        lines_sum = sum(line_totals, Decimal(0))
        if abs(lines_sum - _dec(subtotal)) > MONEY_TOLERANCE * len(line_totals):
            report.flag(
                f"{prefix}.subtotal",
                "subtotal_mismatch",
                f"Line totals add up to {lines_sum}, but the subtotal is {subtotal}.",
                status=FieldStatus.CONFLICTING,
            )
    if subtotal is not None and tax is not None and total is not None:
        expected_total = _dec(subtotal) + _dec(tax)
        if abs(expected_total - _dec(total)) > MONEY_TOLERANCE:
            report.flag(
                f"{prefix}.total_amount",
                "total_mismatch",
                f"Subtotal {subtotal} + tax {tax} = {expected_total}, but the total is {total}.",
                status=FieldStatus.CONFLICTING,
            )
    if total is not None and total < 0:
        report.flag(
            f"{prefix}.total_amount",
            "negative_total",
            "Total is negative; confirm this is a credit note.",
            severity=IssueSeverity.WARNING,
        )


def _check_work_order(report: ValidationReport, prefix: str, wo: WorkOrderExtraction) -> None:
    ordered = wo.quantity_ordered.value
    completed = wo.quantity_completed.value
    rejected = wo.quantity_rejected.value

    for name, value in (("quantity_ordered", ordered), ("quantity_completed", completed), ("quantity_rejected", rejected)):
        if value is not None and value < 0:
            report.flag(f"{prefix}.{name}", "negative_quantity", f"{name} cannot be negative.", status=FieldStatus.INVALID)
    if ordered is not None and ordered == 0:
        report.flag(f"{prefix}.quantity_ordered", "invalid_quantity", "Ordered quantity must be positive.", status=FieldStatus.INVALID)

    if ordered is not None and ordered > 0 and (completed is not None or rejected is not None):
        produced = _dec(completed or 0) + _dec(rejected or 0)
        if produced > _dec(ordered):
            report.flag(
                f"{prefix}.quantity_completed",
                "quantity_exceeds_order",
                f"Completed ({completed or 0}) plus rejected ({rejected or 0}) exceeds the ordered quantity ({ordered}).",
                severity=IssueSeverity.WARNING,
            )

    _check_dates(report, f"{prefix}.due_date", wo.start_date.value, wo.due_date.value, "start date", "due date")


def _check_specification(report: ValidationReport, prefix: str, spec: SpecificationExtraction) -> None:
    if not spec.parameters:
        report.flag(f"{prefix}.parameters", "no_parameters", "No parameters or requirements were found in the specification.")
        return

    for index, param in enumerate(spec.parameters):
        path = f"{prefix}.parameters[{index}]"
        label = param.name or f"Parameter {index + 1}"
        numeric = (param.nominal, param.min_value, param.max_value)
        if all(v is None for v in numeric) and not param.text_requirement:
            report.flag(path, "empty_parameter", f"{label}: no value or requirement was found.", severity=IssueSeverity.WARNING)
            continue
        if param.min_value is not None and param.max_value is not None and param.min_value > param.max_value:
            report.flag(
                path,
                "invalid_range",
                f"{label}: minimum {param.min_value} is greater than maximum {param.max_value}.",
                status=FieldStatus.INVALID,
            )
            continue
        if param.nominal is not None:
            below = param.min_value is not None and param.nominal < param.min_value
            above = param.max_value is not None and param.nominal > param.max_value
            if below or above:
                report.flag(
                    path,
                    "nominal_out_of_range",
                    f"{label}: nominal {param.nominal} is outside [{param.min_value}, {param.max_value}].",
                    status=FieldStatus.CONFLICTING,
                )
