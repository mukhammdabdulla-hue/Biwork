"""Deterministic normalization of extracted raw values (docs/architecture.md §6.1 stage 4).

Every function returns the canonical value plus human-readable notes; nothing here guesses a
missing value. Inferences that are safe and conventional (a missing year, a relative day word)
are reported as notes so reviewers see them.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import date, timedelta
from decimal import Decimal, InvalidOperation
from typing import Any

from app.core.locale_data import RELATIVE_DAYS, month_from_word
from app.core.text import clean_text, normalize_key


@dataclass
class Normalized:
    value: Any = None
    notes: list[str] = field(default_factory=list)
    error: str | None = None
    low_confidence: bool = False
    extras: dict[str, Any] = field(default_factory=dict)  # e.g. a unit found inside a quantity


# --------------------------------------------------------------------------- numbers

_NUMBER = re.compile(r"[-+]?\d[\d\s  .,'’]*\d|[-+]?\d")


def parse_decimal(raw: Any, *, comma_decimal: bool = True) -> Normalized:
    if raw is None or raw == "":
        return Normalized()
    if isinstance(raw, (int, float, Decimal)) and not isinstance(raw, bool):
        return Normalized(Decimal(str(raw)))
    text = str(raw).strip()
    m = _NUMBER.search(text)
    if not m:
        return Normalized(error=f"'{text[:40]}' is not a number")
    token = m.group(0)
    rest = (text[: m.start()] + " " + text[m.end():]).strip()
    # Spaces and apostrophes only group thousands ("1 200 300"); "50 50 0" is several numbers, not 50500.
    groups = re.split(r"[\s  '’]+", token.strip())
    if len(groups) > 1 and not (re.fullmatch(r"[-+]?\d{1,3}", groups[0]) and all(re.match(r"\d{3}(?!\d)", g) for g in groups[1:])):
        return Normalized(error=f"'{text[:40]}' contains more than one number")
    compact = re.sub(r"[\s  '’]", "", token)
    notes: list[str] = []
    low = False
    if "," in compact and "." in compact:
        dec = "," if compact.rfind(",") > compact.rfind(".") else "."
        thou = "." if dec == "," else ","
        compact = compact.replace(thou, "").replace(dec, ".")
    elif "," in compact:
        parts = compact.split(",")
        if len(parts) > 2:
            compact = compact.replace(",", "")
        elif len(parts[1]) == 3 and not comma_decimal:
            compact = compact.replace(",", "")
        elif len(parts[1]) == 3:
            compact = compact.replace(",", ".")
            notes.append(f"'{token.strip()}' read as {compact} (comma as decimal separator)")
            low = True
        else:
            compact = compact.replace(",", ".")
    elif compact.count(".") > 1:
        compact = compact.replace(".", "")
    elif comma_decimal and re.fullmatch(r"[-+]?[1-9]\d{0,2}\.\d{3}", compact):
        # With a comma decimal separator a lone dot before three digits groups thousands ("1.500"), but
        # it may be a decimal point: flagged like "1,500".
        compact = compact.replace(".", "")
        notes.append(f"'{token.strip()}' read as {compact} (dot as thousands separator)")
        low = True
    try:
        value = Decimal(compact)
    except InvalidOperation:
        return Normalized(error=f"'{text[:40]}' is not a number")
    result = Normalized(value, notes, low_confidence=low)
    unit_text = re.sub(r"^[\s:=\-–—]+", "", rest)
    unit_text = re.split(r"[\s,;]", unit_text)[0] if unit_text else ""
    if unit_text:
        result.extras["unit_text"] = unit_text
    return result


# --------------------------------------------------------------------------- dates

_ISO = re.compile(r"\b(\d{4})[-./](\d{1,2})[-./](\d{1,2})\b")
_NUMERIC = re.compile(r"\b(\d{1,2})[./\-](\d{1,2})[./\-](\d{2,4})\b")
_DAY_MONTH = re.compile(r"\b(\d{1,2})\s*[-–.]?\s*(?:chi\s+|-chi\s+)?([^\W\d_]{3,})\.?(?:\s*,?\s*(\d{4}))?", re.UNICODE)
_MONTH_DAY = re.compile(r"\b([^\W\d_]{3,})\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*,?\s*(\d{4}))?", re.UNICODE)
_YEAR_FIRST = re.compile(r"\b(\d{4})\s*[-–]?\s*(?:yil|йил|год|г\.?)?\s*,?\s*(\d{1,2})\s*[-–.]?\s*([^\W\d_]{3,})", re.UNICODE)


def _infer_year(month: int, day: int, reference: date) -> tuple[date, str]:
    candidate = date(reference.year, month, day)
    if candidate > reference + timedelta(days=31):
        candidate = date(reference.year - 1, month, day)
    return candidate, f"Year not stated; {candidate.year} assumed from the submission date"


def parse_date(raw: Any, *, date_order: str = "DMY", reference: date | None = None) -> Normalized:
    reference = reference or date.today()
    if raw is None or raw == "":
        return Normalized()
    if isinstance(raw, date):
        return Normalized(raw)
    text = str(raw).strip()
    lowered = text.lower()
    try:
        for word, offset in RELATIVE_DAYS.items():
            if re.search(rf"(?<!\w){word}(?!\w)", lowered):
                d = reference + timedelta(days=offset)
                return Normalized(d, [f"'{word}' interpreted relative to the submission date ({reference.isoformat()})"])
        m = _ISO.search(text)
        if m:
            return Normalized(date(int(m.group(1)), int(m.group(2)), int(m.group(3))))
        m = _NUMERIC.search(text)
        if m:
            a, b, y = int(m.group(1)), int(m.group(2)), int(m.group(3))
            if y < 100:
                y += 2000
            if date_order == "MDY" and a <= 12:
                month, day = a, b
            elif a > 12 or date_order != "MDY":
                day, month = a, b
            else:
                month, day = a, b
            notes = []
            if a <= 12 and b <= 12 and a != b:
                notes.append(f"'{m.group(0)}' read as {'day.month' if day == a else 'month.day'} (company date order {date_order})")
            return Normalized(date(y, month, day), notes)
        m = _YEAR_FIRST.search(text)
        if m and month_from_word(m.group(3)):
            return Normalized(date(int(m.group(1)), month_from_word(m.group(3)), int(m.group(2))))
        for pattern, day_idx, month_idx in ((_DAY_MONTH, 1, 2), (_MONTH_DAY, 2, 1)):
            for m in pattern.finditer(text):
                month = month_from_word(m.group(month_idx))
                if not month:
                    continue
                day = int(m.group(day_idx))
                if m.group(3):
                    return Normalized(date(int(m.group(3)), month, day))
                d, note = _infer_year(month, day, reference)
                return Normalized(d, [note])
    except ValueError as exc:
        return Normalized(error=f"'{text[:40]}' is not a valid date ({exc})")
    return Normalized(error=f"'{text[:40]}' is not a recognizable date")


# --------------------------------------------------------------------------- units, currency, enums

CURRENCIES = {
    "UZS": ("uzs", "som", "so'm", "soʻm", "sum", "сум", "сўм", "сом", "soum"),
    "USD": ("usd", "$", "dollar", "доллар", "долл", "us$"),
    "EUR": ("eur", "€", "euro", "евро"),
    "RUB": ("rub", "₽", "rubl", "руб", "рубль", "рублей"),
    "KZT": ("kzt", "₸", "tenge", "тенге"),
    "CNY": ("cny", "¥", "yuan", "юань"),
}


def normalize_currency(raw: Any) -> Normalized:
    if not raw:
        return Normalized()
    text = str(raw).strip()
    key = text.lower().replace(".", "").replace("ʻ", "'").replace("’", "'")
    for code, names in CURRENCIES.items():
        if key == code.lower() or key in names:
            return Normalized(code)
    if re.fullmatch(r"[A-Za-z]{3}", text):
        return Normalized(text.upper(), [f"Currency code {text.upper()} taken as written"], low_confidence=True)
    return Normalized(error=f"Unknown currency '{text[:20]}'")


def unit_key(raw: str) -> str:
    return " ".join(str(raw).strip().lower().replace(".", " ").split())


def normalize_unit(raw: Any, unit_map: dict[str, dict[str, Any]]) -> Normalized:
    if not raw:
        return Normalized()
    key = unit_key(raw)
    hit = unit_map.get(key) or unit_map.get(key.rstrip("s"))
    if hit:
        return Normalized(hit["code"])
    return Normalized(error=f"Unknown unit of measure '{str(raw)[:20]}'")


ENUM_SYNONYMS: dict[str, tuple[str, ...]] = {
    "receipt": ("kirim", "prixod", "приход", "поступление", "incoming", "in", "receipt", "received", "qabul"),
    "issue": ("chiqim", "rasxod", "расход", "отпуск", "выдача", "outgoing", "out", "issue", "issued", "berildi"),
    "adjustment_in": ("оприходование", "излишек", "surplus", "ortiqcha"),
    "adjustment_out": ("списание", "недостача", "write off", "writeoff", "kamomad", "hisobdan chiqarish"),
    "production_output": ("выпуск", "output", "ishlab chiqarish"),
    "production_consumption": ("consumption", "расход в производство", "sarflandi"),
    "urgent": ("shoshilinch", "srochno", "срочно", "срочный", "urgent", "asap", "juda muhim"),
    "high": ("yuqori", "высокий", "high", "muhim"),
    "normal": ("o'rta", "orta", "oddiy", "обычный", "средний", "normal", "medium"),
    "low": ("past", "низкий", "low"),
    "finished_good": ("tayyor mahsulot", "готовая продукция", "finished", "finished good", "fg"),
    "raw_material": ("xomashyo", "сырье", "сырьё", "raw material", "raw", "material"),
    "semi_finished": ("yarim tayyor", "полуфабрикат", "wip", "semi finished"),
    "consumable": ("sarflanadigan", "расходный материал", "consumable"),
    "packaging": ("qadoq", "тара", "packaging"),
    "service": ("xizmat", "услуга", "service"),
    # business documents
    "invoice": ("hisob-faktura", "hisob faktura", "schet-faktura", "счёт-фактура", "счет-фактура", "счёт", "счет", "invoice",
                "tax invoice", "faktura"),
    "waybill": ("nakladnoy", "nakladnaya", "yuk xati", "накладная", "товарная накладная", "ттн", "waybill", "delivery note"),
    "contract": ("shartnoma", "договор", "контракт", "contract", "agreement"),
    "act": ("dalolatnoma", "akt", "акт", "акт выполненных работ", "act", "acceptance act"),
    "specification": ("spetsifikatsiya", "спецификация", "specification", "spec"),
    # employees
    "active": ("faol", "ishlamoqda", "работает", "активный", "active", "employed"),
    "on_leave": ("ta'tilda", "tatilda", "в отпуске", "отпуск", "on leave", "leave"),
    "dismissed": ("bo'shatilgan", "boshatilgan", "уволен", "уволена", "dismissed", "terminated"),
}


def normalize_enum(raw: Any, allowed: tuple[str, ...]) -> Normalized:
    if not raw:
        return Normalized()
    key = normalize_key(str(raw))
    for value in allowed:
        if key == normalize_key(value):
            return Normalized(value)
    for value in allowed:
        if any(key == normalize_key(s) for s in ENUM_SYNONYMS.get(value, ())):
            return Normalized(value)
    return Normalized(error=f"'{str(raw)[:30]}' is not one of: {', '.join(allowed)}")


def normalize_text(raw: Any, max_length: int | None) -> Normalized:
    text = clean_text(None if raw is None else str(raw))
    if text is None:
        return Normalized()
    if max_length and len(text) > max_length:
        return Normalized(text[:max_length], [f"Truncated to {max_length} characters"], low_confidence=True)
    return Normalized(text)


def normalize_integer(raw: Any) -> Normalized:
    result = parse_decimal(raw)
    if result.error or result.value is None:
        return result
    if result.value != result.value.to_integral_value():
        return Normalized(error=f"'{raw}' is not a whole number")
    return Normalized(int(result.value), result.notes)
