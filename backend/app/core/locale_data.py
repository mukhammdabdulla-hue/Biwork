"""Month names and relative-day words for Uzbek (Latin/Cyrillic), Russian and English."""

from __future__ import annotations

import re

# stem -> month number. Stems are matched against normalized (lower-case, apostrophe-free) words.
MONTH_STEMS: dict[str, int] = {
    # Uzbek (Latin)
    "yanvar": 1, "fevral": 2, "mart": 3, "aprel": 4, "may": 5, "iyun": 6, "iyul": 7, "avgust": 8,
    "sentyabr": 9, "sentabr": 9, "oktyabr": 10, "oktabr": 10, "noyabr": 11, "noyabr'": 11, "dekabr": 12,
    # Uzbek (Cyrillic) / Russian
    "январ": 1, "феврал": 2, "март": 3, "апрел": 4, "май": 5, "мая": 5, "июн": 6, "июл": 7, "август": 8,
    "сентябр": 9, "октябр": 10, "ноябр": 11, "декабр": 12,
    # English
    "january": 1, "jan": 1, "february": 2, "feb": 2, "march": 3, "mar": 3, "april": 4, "apr": 4,
    "june": 6, "jun": 6, "july": 7, "jul": 7, "august": 8, "aug": 8, "september": 9, "sep": 9, "sept": 9,
    "october": 10, "oct": 10, "november": 11, "nov": 11, "december": 12, "dec": 12,
}

RELATIVE_DAYS: dict[str, int] = {
    "bugun": 0, "today": 0, "сегодня": 0, "бугун": 0,
    "kecha": -1, "yesterday": -1, "вчера": -1, "кеча": -1,
    "ertaga": 1, "tomorrow": 1, "завтра": 1, "эртага": 1,
}

MONTH_WORD_PATTERN = "|".join(sorted((s for s in MONTH_STEMS if s.isalpha()), key=len, reverse=True))


def month_from_word(word: str) -> int | None:
    w = word.lower().replace("'", "").replace("ʻ", "").replace("’", "").strip(".,")
    if w in MONTH_STEMS:
        return MONTH_STEMS[w]
    for stem in sorted(MONTH_STEMS, key=len, reverse=True):
        if len(stem) >= 3 and w.startswith(stem):
            return MONTH_STEMS[stem]
    return None


# Date expressions as they are written (used to locate dates in text, not to parse them).
_MONTH = rf"(?:{MONTH_WORD_PATTERN})[^\W\d_]*"
DATE_SPAN = re.compile(
    rf"\b\d{{4}}[-./]\d{{1,2}}[-./]\d{{1,2}}\b"
    rf"|\b\d{{1,2}}[./]\d{{1,2}}[./]\d{{2,4}}\b"
    rf"|\b\d{{4}}\s*-?\s*(?:yil|йил|год|г\.?)\s*,?\s*\d{{1,2}}\s*[-–]?\s*{_MONTH}"
    rf"|\b\d{{1,2}}\s*[-–]?\s*{_MONTH}(?:\s*,?\s*\d{{4}}(?:\s*(?:г\.|yil|год))?)?"
    rf"|\b{_MONTH}\s+\d{{1,2}}(?:,?\s*\d{{4}})?"
    rf"|\b(?:{'|'.join(RELATIVE_DAYS)})\b",
    re.IGNORECASE | re.UNICODE,
)
