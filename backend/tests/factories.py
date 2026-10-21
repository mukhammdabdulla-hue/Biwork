"""Builders for extraction payloads used across tests."""

from __future__ import annotations

from datetime import date

from app.agent.intake.schemas import (
    DocumentType,
    Extracted,
    ExtractionEnvelope,
    InvoiceExtraction,
    InvoiceLine,
    SpecificationExtraction,
    SpecParameter,
    WorkOrderExtraction,
)


def ex(value, confidence: str = "high", page: int | None = 1) -> Extracted:
    return Extracted(
        value=value,
        confidence=confidence if value is not None else "low",
        source_page=page if value is not None else None,
        source_text=str(value) if value is not None else None,
    )


def invoice(**overrides) -> InvoiceExtraction:
    fields = dict(
        invoice_number=ex("INV-2026-0142"),
        issue_date=ex(date(2026, 10, 1)),
        due_date=ex(date(2026, 10, 31)),
        supplier_name=ex("Toshkent Metall MChJ"),
        supplier_tax_id=ex("301234567"),
        buyer_name=ex("BIWORK Demo Zavod"),
        buyer_tax_id=ex(None),
        currency=ex("UZS"),
        line_items=[
            InvoiceLine(
                description="Steel sheet 2mm",
                product_code="ST-2MM",
                quantity=10,
                unit="pcs",
                unit_price=150000,
                line_total=1500000,
                confidence="high",
                source_page=1,
            ),
            InvoiceLine(
                description="Bolt M8",
                product_code="B-M8",
                quantity=200,
                unit="pcs",
                unit_price=1250.5,
                line_total=250100,
                confidence="high",
                source_page=1,
            ),
        ],
        subtotal=ex(1750100.0),
        tax_amount=ex(210012.0),
        total_amount=ex(1960112.0),
    )
    fields.update(overrides)
    return InvoiceExtraction(**fields)


def work_order(**overrides) -> WorkOrderExtraction:
    fields = dict(
        order_number=ex("WO-551"),
        product_name=ex("A mahsulot"),
        product_code=ex("A-100"),
        quantity_ordered=ex(500.0),
        quantity_completed=ex(480.0),
        quantity_rejected=ex(20.0),
        unit=ex("dona"),
        production_unit=ex("1-sex"),
        responsible_person=ex(None),
        start_date=ex(date(2026, 10, 20)),
        due_date=ex(date(2026, 10, 21)),
        operations=[],
    )
    fields.update(overrides)
    return WorkOrderExtraction(**fields)


def specification(**overrides) -> SpecificationExtraction:
    fields = dict(
        document_number=ex("TS-12"),
        revision=ex("B"),
        product_name=ex("Flange DN50"),
        product_code=ex("FL-50"),
        material=ex("St3"),
        issue_date=ex(date(2026, 5, 4)),
        parameters=[
            SpecParameter(
                name="Outer diameter",
                nominal=165.0,
                min_value=164.5,
                max_value=165.5,
                unit="mm",
                text_requirement=None,
                confidence="high",
                source_page=1,
            ),
            SpecParameter(
                name="Surface",
                nominal=None,
                min_value=None,
                max_value=None,
                unit=None,
                text_requirement="No visible cracks",
                confidence="high",
                source_page=1,
            ),
        ],
    )
    fields.update(overrides)
    return SpecificationExtraction(**fields)


def envelope(doc_type: DocumentType, payload=None, **overrides) -> ExtractionEnvelope:
    fields = dict(
        document_type=doc_type,
        document_type_confidence="high",
        detected_language="uz",
        invoice=payload if doc_type is DocumentType.INVOICE else None,
        work_order=payload if doc_type is DocumentType.WORK_ORDER else None,
        specification=payload if doc_type is DocumentType.SPECIFICATION else None,
        extraction_notes=[],
    )
    fields.update(overrides)
    return ExtractionEnvelope(**fields)
