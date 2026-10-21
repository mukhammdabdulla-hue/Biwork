"""Text normalization shared by master data (aliases) and the Agent (matching).

Handles Uzbek Latin/Cyrillic, Russian, and English spellings so that "A mahsulot", "А маҳсулот"
and "a  mahsulot" normalize to the same key.
"""

from __future__ import annotations

import re
import unicodedata

_CYR_TO_LAT = {
    "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "yo", "ж": "j", "з": "z", "и": "i",
    "й": "y", "к": "k", "л": "l", "м": "m", "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t",
    "у": "u", "ф": "f", "х": "x", "ц": "ts", "ч": "ch", "ш": "sh", "щ": "sh", "ъ": "", "ы": "i", "ь": "",
    "э": "e", "ю": "yu", "я": "ya", "ў": "o", "қ": "q", "ғ": "g", "ҳ": "h",
}
_APOSTROPHES = "'`ʻʼ‘’´ʹ′"
_APOS_RE = re.compile(f"[{re.escape(_APOSTROPHES)}]")
_NON_WORD = re.compile(r"[^\w]+", re.UNICODE)
_DIGIT_LETTER = re.compile(r"(?<=\d)(?=[^\W\d_])|(?<=[^\W\d_])(?=\d)")


def transliterate(value: str) -> str:
    return "".join(_CYR_TO_LAT.get(ch, ch) for ch in value)


def normalize_key(value: str | None) -> str:
    """Matching key: NFKC, lower-case, Cyrillic -> Latin, apostrophes dropped, punctuation -> space,
    digits split from letters ("sex1" -> "sex 1"), whitespace collapsed."""
    if not value:
        return ""
    value = unicodedata.normalize("NFKC", value.replace("№", " ")).lower()
    value = transliterate(value)
    value = _APOS_RE.sub("", value)
    value = _NON_WORD.sub(" ", value).replace("_", " ")
    value = _DIGIT_LETTER.sub(" ", value)
    return " ".join(value.split())


def sorted_tokens(value: str) -> str:
    return " ".join(sorted(normalize_key(value).split()))


def clean_text(value: str | None, max_length: int | None = None) -> str | None:
    """Trim, NFC-normalize and collapse internal whitespace; strip control characters."""
    if value is None:
        return None
    value = unicodedata.normalize("NFC", str(value))
    value = "".join(ch for ch in value if ch in "\n\t" or unicodedata.category(ch)[0] != "C")
    value = re.sub(r"[ \t]+", " ", value).strip()
    if max_length is not None:
        value = value[:max_length]
    return value or None


def fmt_qty(value) -> str:
    """Human-readable decimal without exponent or trailing zeros: 500, 12.5, 1200."""
    from decimal import Decimal

    if value is None:
        return "—"
    d = Decimal(str(value)).normalize()
    return format(d, "f") if d == d.to_integral_value() else format(d, "f").rstrip("0").rstrip(".")
