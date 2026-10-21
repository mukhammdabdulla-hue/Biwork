from datetime import date

from app.agent.intake.schemas import DocumentType, FieldStatus, IssueSeverity, SpecParameter
from app.agent.intake.validation import validate_extraction
from tests.factories import envelope, ex, invoice, specification, work_order


def codes(report):
    return {issue.code for issue in report.issues}


def status_of(report, path):
    return next(f.status for f in report.fields if f.field == path)


def test_consistent_invoice_has_no_issues():
    report = validate_extraction(envelope(DocumentType.INVOICE, invoice()))
    assert report.issues == []
    assert status_of(report, "invoice.total_amount") is FieldStatus.HIGH_CONFIDENCE
    assert status_of(report, "invoice.buyer_tax_id") is FieldStatus.MISSING


def test_invoice_line_total_mismatch_is_conflicting():
    inv = invoice()
    inv.line_items[0].line_total = 1400000
    report = validate_extraction(envelope(DocumentType.INVOICE, inv))
    assert {"line_total_mismatch", "subtotal_mismatch"} <= codes(report)
    assert status_of(report, "invoice.line_items[0]") is FieldStatus.CONFLICTING


def test_invoice_total_must_equal_subtotal_plus_tax():
    report = validate_extraction(envelope(DocumentType.INVOICE, invoice(total_amount=ex(2000000.0))))
    assert codes(report) == {"total_mismatch"}
    assert status_of(report, "invoice.total_amount") is FieldStatus.CONFLICTING


def test_missing_required_invoice_fields_are_errors():
    report = validate_extraction(
        envelope(DocumentType.INVOICE, invoice(invoice_number=ex(None), total_amount=ex(None)))
    )
    missing = [i for i in report.issues if i.code == "missing_required"]
    assert {i.field for i in missing} == {"invoice.invoice_number", "invoice.total_amount"}
    assert all(i.severity is IssueSeverity.ERROR for i in missing)


def test_low_confidence_value_is_a_warning_not_a_fact():
    report = validate_extraction(envelope(DocumentType.INVOICE, invoice(supplier_name=ex("T0shkent Meta11", "low"))))
    assert status_of(report, "invoice.supplier_name") is FieldStatus.LOW_CONFIDENCE
    [issue] = report.issues
    assert issue.code == "low_confidence" and issue.severity is IssueSeverity.WARNING


def test_invoice_due_date_before_issue_date():
    report = validate_extraction(envelope(DocumentType.INVOICE, invoice(due_date=ex(date(2026, 9, 1)))))
    assert codes(report) == {"date_order"}


def test_invoice_currency_must_be_iso_code():
    report = validate_extraction(envelope(DocumentType.INVOICE, invoice(currency=ex("so'm"))))
    assert codes(report) == {"invalid_currency"}
    assert status_of(report, "invoice.currency") is FieldStatus.INVALID


def test_work_order_reconciles_quantities():
    assert validate_extraction(envelope(DocumentType.WORK_ORDER, work_order())).issues == []

    report = validate_extraction(envelope(DocumentType.WORK_ORDER, work_order(quantity_rejected=ex(40.0))))
    [issue] = report.issues
    assert issue.code == "quantity_exceeds_order"
    assert issue.severity is IssueSeverity.WARNING


def test_work_order_rejects_negative_quantity():
    report = validate_extraction(envelope(DocumentType.WORK_ORDER, work_order(quantity_completed=ex(-5.0))))
    assert "negative_quantity" in codes(report)


def test_specification_ranges():
    assert validate_extraction(envelope(DocumentType.SPECIFICATION, specification())).issues == []

    bad = SpecParameter(
        name="Thickness",
        nominal=5.0,
        min_value=6.0,
        max_value=4.0,
        unit="mm",
        text_requirement=None,
        confidence="high",
        source_page=2,
    )
    out_of_range = bad.model_copy(update={"name": "Width", "min_value": 1.0, "max_value": 2.0})
    report = validate_extraction(
        envelope(DocumentType.SPECIFICATION, specification(parameters=[bad, out_of_range]))
    )
    assert codes(report) == {"invalid_range", "nominal_out_of_range"}


def test_specification_without_parameters():
    report = validate_extraction(envelope(DocumentType.SPECIFICATION, specification(parameters=[])))
    assert codes(report) == {"no_parameters"}


def test_unknown_document_type():
    report = validate_extraction(envelope(DocumentType.UNKNOWN))
    assert codes(report) == {"unsupported_document_type"}


def test_classified_type_without_payload():
    report = validate_extraction(envelope(DocumentType.INVOICE, None))
    assert codes(report) == {"missing_payload"}


def test_low_confidence_classification_and_stray_payload_are_warnings():
    env = envelope(
        DocumentType.WORK_ORDER,
        work_order(),
        document_type_confidence="low",
        invoice=invoice(),
    )
    report = validate_extraction(env)
    assert codes(report) == {"low_confidence_classification", "unexpected_payload"}
    assert all(i.severity is IssueSeverity.WARNING for i in report.issues)
