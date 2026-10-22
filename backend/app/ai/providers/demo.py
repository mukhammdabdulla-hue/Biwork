"""Demo mode — deterministic, rule-based stand-in for an AI provider (docs/ai-engine.md §Demo mode).

Used only when no AI provider credentials are configured (or AI_PROVIDER=demo), and never as a
fallback for a failing real provider. It implements the same interface as real providers so the
whole pipeline can be exercised without credentials, but it is NOT AI: it recognizes common
phrasings (labelled fields, item tables, and narrative production reports in Uzbek/Russian/English)
with regular expressions. Every job processed with it is flagged `ai_is_mock` / `ai_mode="demo"`,
carries a "Demo mode" warning, and is never auto-approved.
"""

from __future__ import annotations

import re
import time
from typing import Any

from pydantic import BaseModel

from app.ai.policy import detect_injection
from app.ai.providers.base import ContentBlock, LLMResult, PromptTask, TextBlock
from app.core.locale_data import DATE_SPAN
from app.core.text import normalize_key, transliterate


def _lat(pattern: str) -> str:
    """The rules read a Latin view of the input (see `_Source`), so Cyrillic words in a pattern are
    written naturally and transliterated once here ("выпущено" -> "vipusheno")."""
    return transliterate(pattern)


UNIT_WORDS = (
    "dona", "ta", "шт", "штук", "pcs", "pc", "pieces", "kg", "кг", "kilogramm", "tonna", "тонн", "t", "litr", "литр", "l", "л",
    "metr", "метр", "m", "м", "qop", "мешков", "мешка", "мешок", "bags", "quti", "коробок", "коробки", "boxes", "komplekt",
    "комплект", "rulon", "рулон", "m2", "м2", "m3", "м3", "pachka", "упаковок",
)
_UNIT = r"(?:" + "|".join(sorted({re.escape(_lat(u)) for u in UNIT_WORDS}, key=len, reverse=True)) + r")\.?"
_QTY = r"\d[\d\s.,]*\d|\d"

# OCR often confuses q/g in Uzbek Latin text ("chigarildi"), so the verbs tolerate it.
PRODUCED = _lat(r"ishlab\s+chi[qg]arildi|ishlab\s+chi[qg]arilgan|tayyorlandi|produced|manufactured|выпущено|произведено|изготовлено")
ACCEPTED = _lat(r"sifat\s+nazoratidan\s+o['ʻ’`]?tdi|sifatdan\s+o['ʻ’`]?tdi|[qg]abul\s+[qg]ilindi|accepted|passed(?:\s+qc)?|"
                r"принят\w*|годн\w*|прошл\w*\s+отк")
DEFECTIVE = _lat(r"brak|yaroqsiz|nuqsonli|defective|rejected|scrap|брак\w*|дефект\w*")
WORKSHOP = _lat(r"sex|цех|workshop|uchastka|участок")
WAREHOUSE = _lat(r"ombor|склад|warehouse")
# Words that end a product name in a stock movement sentence ("120 qop Sement M400 kirim qilindi").
_PRODUCT_END = _lat(r"kirim|chiqim|omborga|ombordan|qabul|received|issued|на|в|со|из|from|to|into|uchun|"
                    r"выдан\w*|получен\w*|поступил\w*|принят\w*")

_DEP_LABEL = re.compile(rf"(?:mas['ʻ’]?ul\s+)?(?<![\w-])(?:{WORKSHOP})\s*(?:№\s*)?[—–:\-]\s*([^\n.;,]+)", re.IGNORECASE)
_DEP_NUMBERED = re.compile(rf"(\d+\s*-?\s*(?:{WORKSHOP}))(?:dagi|da|dan|ning|ga|i)?(?!\w)", re.IGNORECASE)  # "3-sexda"
_DEP_NAMED = re.compile(rf"((?:{WORKSHOP})\s*№?\s*\d+)", re.IGNORECASE)  # "Цех №2", "workshop 3"
_SHIFT = re.compile(r"(\d+)\s*-?\s*(?:smena|shift)|(?:smena|shift)\s*№?\s*(\d+)", re.IGNORECASE)
_ORDER = re.compile(_lat(r"(?:buyurtma|заказ\w*|order)\s*(?:№|no\.?|#)?\s*([A-Z]{1,4}-?\d[\w\-/]*)"), re.IGNORECASE)
_ACCEPTED_QTY = re.compile(rf"({_QTY})\s*(?:{_UNIT})?\s*(?:\w+\s+){{0,3}}?(?:{ACCEPTED})"
                           rf"|(?:{ACCEPTED})(?:\s+otk)?\s*[:—–-]?\s*({_QTY})", re.IGNORECASE)
_DEFECTIVE_QTY = re.compile(rf"({_QTY})\s*(?:{_UNIT})?\s*(?:\w+\s+){{0,3}}?(?:{DEFECTIVE})|(?:{DEFECTIVE})\w*\s*[:—–-]?\s*({_QTY})",
                            re.IGNORECASE)
_PRODUCT_PREFIX = re.compile(_lat(r"(?:(?:kuni|da|day|of|продукц\w*|издели\w*)\s+)*"), re.IGNORECASE)

_DOC_KEYWORDS = {
    "production_report": ("ishlab chi", "produced", "выпущено", "произведено", "brak", "брак", "sifat", "отк", "accepted",
                          "defective", "смена", "smena"),
    "purchase_request": ("заявка", "zayavka", "ariza", "purchase request", "закуп", "xarid", "sotib olish", "поставщик",
                         "yetkazib", "supplier", "needed by", "срок поставки"),
    "production_order": ("buyurtma", "заказ на производство", "production order", "наряд", "план выпуска", "ishlab chiqarish rejasi"),
    "stock_movement": ("kirim", "chiqim", "приход", "расход", "omborga", "ombordan", "склад", "warehouse", "receipt", "issued"),
    "task": ("vazifa", "topshiriq", "задача", "поручение", "task", "please", "iltimos", "прошу", "kerak", "необходимо"),
    "product": ("yangi mahsulot", "new product", "новый товар", "артикул", "artikul", "номенклатура"),
    "employee": ("xodim", "ishchi", "сотрудник", "работник", "employee", "табельный", "tabel raqami", "lavozim", "должность",
                 "ishga qabul", "приём на работу", "hired"),
    "supplier": ("yetkazib beruvchi", "поставщик", "supplier", "vendor", "inn", "инн", "stir", "контрагент", "kontragent"),
    "business_document": ("hisob-faktura", "счёт-фактура", "счет-фактура", "invoice", "nakladnoy", "накладная", "waybill",
                          "shartnoma", "договор", "contract", "dalolatnoma", "акт", "итого", "jami", "total"),
}


# "ЗАЯВКА № ZK-2026-015", "Ariza No 15", "Request #A-17"
_DOC_NUMBER = re.compile(r"(?:№|\bNo\.?|#)\s*([A-Za-zА-Яа-я0-9][\w\-/.]*\d[\w\-/]*)", re.UNICODE)


class _Source:
    """The input as the rules read it: dates blanked out (they look like quantities) and Cyrillic
    (Uzbek or Russian) transliterated to Latin, so one set of Latin rules reads both scripts
    ("ишлаб чиқарилди" -> "ishlab chiqarildi"). Values and quotes are always cut from the original."""

    def __init__(self, text: str):
        self.text = text
        masked = DATE_SPAN.sub(lambda d: "#" * len(d.group(0)), text)
        out: list[str] = []
        self._at: list[int] = []  # original offset of each character of `view`
        for i, ch in enumerate(masked):
            lat = transliterate(ch.lower())
            if lat == ch.lower():
                lat = ch
            elif ch.isupper():
                lat = lat[:1].upper() + lat[1:]
            out.append(lat)
            self._at += [i] * len(lat)
        self.view = "".join(out)

    def offset(self, pos: int) -> int:
        """Original offset of a view position."""
        return self._at[pos] if pos < len(self._at) else len(self.text)

    def cut(self, start: int, end: int) -> str:
        """Original text behind view[start:end]."""
        return self.text[self._at[start]:self._at[end - 1] + 1] if end > start else ""

    def group(self, m: re.Match, n: int = 0) -> str | None:
        return self.cut(m.start(n), m.end(n)) if m.start(n) >= 0 else None


def _fv(value: str | None, confidence: float = 0.9, quote: str | None = None) -> dict[str, Any]:
    if value is None or str(value).strip() == "":
        return {"value": None, "confidence": 0.0, "source_quote": None, "not_found": True, "alternatives": []}
    value = str(value).strip().strip(" .,;:—–-")
    return {"value": value, "confidence": confidence, "source_quote": (quote or value).strip(), "not_found": False,
            "alternatives": []}


def _alias_re(alias: str) -> str:
    """An alias as it reads in the Latin view: apostrophes optional ("o'tdi" / "ўтди"), any spacing."""
    pattern = re.sub(r"['ʻ’`ʼ]", "['ʻ’`ʼ]?", re.escape(_lat(alias.lower())))
    return pattern.replace(r"\ ", r"\s+")


# A labelled value ends at the line end, or where the next "Label:" starts after a sentence break
# ("Muddat: 25.10.2026 gacha. Mas'ul: ..."); a mid-sentence label's value ends with its sentence.
_LINE_VALUE_END = r"(?=\s*[.;!]\s+[^\W\d_][\w'ʻ’`ʼ \-]{0,30}?\s*(?:№\s*)?[:—–]|\s*$)"
_INLINE_VALUE_END = r"(?=\.(?:\s|$)|[;!\n]|$)"


def _label_value(src: _Source, aliases: list[str], start: int = 0, end: int | None = None) -> tuple[str, str] | None:
    """Find 'Alias: value' / 'Alias — value' at the start of a line, or after a sentence break
    ("... Nakladnoy: NK-5521", "Накладная № RS-3301")."""
    end = len(src.view) if end is None else end
    usable = [a for a in sorted(aliases, key=len, reverse=True) if len(a) >= 2]
    for alias in usable:
        m = re.compile(rf"(?im)^[ \t*\-•]*{_alias_re(alias)}\s*(?:№\s*)?[:—–=\-]\s*(.+?){_LINE_VALUE_END}").search(src.view, start, end)
        if m and m.group(1).strip():
            return src.group(m, 1).strip(), src.group(m).strip()
    for alias in usable:
        if len(alias) < 3:
            continue
        m = re.compile(rf"(?i)(?<=[.;!,]\s){_alias_re(alias)}\s*(?:№|[:—–])\s*(.+?){_INLINE_VALUE_END}").search(src.view, start, end)
        if m and m.group(1).strip():
            return src.group(m, 1).strip(), src.group(m).strip()
    return None


def _markdown_tables(text: str) -> list[tuple[list[str], list[list[str]]]]:
    tables, current = [], []
    for line in text.splitlines() + [""]:
        if line.strip().startswith("|"):
            cells = [c.strip() for c in line.strip().strip("|").split("|")]
            if not all(re.fullmatch(r":?-{2,}:?", c) for c in cells if c):
                current.append(cells)
        elif current:
            if len(current) >= 2:
                tables.append((current[0], current[1:]))
            current = []
    return tables


def _match_header(header: str, fields: list[dict[str, Any]]) -> str | None:
    key = normalize_key(header)
    for f in fields:
        if key in {normalize_key(a) for a in [f["name"], f["label"], *f.get("aliases", [])]}:
            return f["name"]
    for f in fields:
        for a in [f["label"], *f.get("aliases", [])]:
            ak = normalize_key(a)
            if len(ak) >= 4 and (ak in key or key in ak):
                return f["name"]
    return None


class DemoProvider:
    name = "demo"
    model = "demo-rules-v1"
    is_mock = True
    supports_vision = False
    supports_pdf = False
    external = False

    def generate(self, *, task: PromptTask, content: list[ContentBlock], output_schema: type[BaseModel],
                 timeout_s: float) -> LLMResult:
        started = time.monotonic()
        text = "\n".join(b.text for b in content if isinstance(b, TextBlock))
        if task.prompt_id == "classify":
            data = self._classify(text, task.params)
        elif task.prompt_id == "extract":
            data = self._extract(text, task.params)
        elif task.prompt_id == "map_columns":
            data = self._map_columns(task.params)
        else:  # pragma: no cover
            raise ValueError(task.prompt_id)
        parsed = output_schema.model_validate(data)
        return LLMResult(parsed=parsed, model=self.model, input_tokens=len(text) // 4, output_tokens=0,
                         latency_ms=int((time.monotonic() - started) * 1000), stop_reason="end_turn")

    # ------------------------------------------------------------------ classify

    def _classify(self, text: str, params: dict[str, Any]) -> dict[str, Any]:
        lowered = _Source(text).view.lower()
        results = []
        for rt in params["record_types"]:
            hits = list(dict.fromkeys(k for k in map(_lat, _DOC_KEYWORDS.get(rt["code"], ())) if k in lowered))
            if hits:
                count = 1
                if rt["code"] == "production_report":
                    count = max(1, len(re.findall(PRODUCED, lowered)))
                results.append({"record_type": rt["code"], "count_estimate": count,
                                "confidence": min(0.95, 0.55 + 0.1 * len(hits)), "reason": f"keywords: {', '.join(hits[:4])}"})
        results.sort(key=lambda r: -r["confidence"])
        if results:
            # A document is normally one kind of record; keep the dominant type.
            results = [r for r in results if r["confidence"] >= results[0]["confidence"] - 0.05][:1]
        return {"record_types": results, "language": "mixed", "contains_instructions": bool(detect_injection(text))}

    # ------------------------------------------------------------------ extract

    def _extract(self, text: str, params: dict[str, Any]) -> dict[str, Any]:
        rt = params["record_type"]
        src = _Source(text)
        record = self._labelled_record(src, rt)
        labelled_form = not record.get("product", {}).get("not_found", True) and not record["qty_produced"]["not_found"] \
            if rt["code"] == "production_report" else False
        if rt["code"] == "production_report" and not labelled_form:  # a "Mahsulot: ... / Ishlab chiqarildi: ..." form reads as labels
            records = self._production_reports(src, rt)
            if records:
                return {"records": records}
        if rt.get("line_fields"):
            record["lines"] = self._lines(text, rt["line_fields"])
        if rt["code"] in ("production_order", "stock_movement", "task"):
            self._narrative_fill(src, rt, record)
        found = any(not v["not_found"] for k, v in record.items() if k != "lines") or record.get("lines")
        return {"records": [record] if found else []}

    def _labelled_record(self, src: _Source, rt: dict[str, Any]) -> dict[str, Any]:
        record: dict[str, Any] = {}
        for f in rt["fields"]:
            hit = _label_value(src, [f["label"], *f.get("aliases", [])])
            if hit and f["type"] == "date":
                span = DATE_SPAN.search(hit[0])
                record[f["name"]] = _fv(span.group(0) if span else hit[0], 0.9 if span else 0.5, hit[1])
            elif hit:
                record[f["name"]] = _fv(hit[0], 0.88, hit[1])
            elif "№" in f.get("aliases", []) and (m := _DOC_NUMBER.search(src.text)):
                record[f["name"]] = _fv(m.group(1), 0.85, m.group(0))
            else:
                record[f["name"]] = _fv(None)
        return record

    def _lines(self, text: str, line_fields: list[dict[str, Any]]) -> list[dict[str, Any]]:
        lines = []
        for headers, rows in _markdown_tables(text):
            mapping = {i: _match_header(h, line_fields) for i, h in enumerate(headers)}
            if not any(mapping.values()):
                continue
            for row in rows:
                line = {f["name"]: _fv(None) for f in line_fields}
                for i, cell in enumerate(row):
                    name = mapping.get(i)
                    if name and cell:
                        line[name] = _fv(cell, 0.9)
                if any(not v["not_found"] for v in line.values()):
                    lines.append(line)
        return lines

    def _production_reports(self, src: _Source, rt: dict[str, Any]) -> list[dict[str, Any]]:
        view, text = src.view, src.text
        # The product sits between the quantity and the verb, within one statement ("480 qabul qilindi;
        # 2-sex: 300 dona B ..." is not a product).
        pattern = re.compile(rf"({_QTY})(?!\s*(?:kuni|yil|g\.|goda|year))\s*({_UNIT})?\s+((?:[^\n;:.]|\.(?!\s)){{1,80}}?)\s+(?:{PRODUCED})",
                             re.IGNORECASE | re.UNICODE)
        matches = list(pattern.finditer(view))
        if not matches:  # "Выпущено 1200 шт изделия Б-12" (verb first)
            pattern = re.compile(rf"(?:{PRODUCED})\s*[:—–-]?\s*({_QTY})\s*({_UNIT})?\s+([^,.;\n]{{1,80}})", re.IGNORECASE)
            matches = list(pattern.finditer(view))
        if not matches:
            return []
        # Each report reads only its own text: from the start of its line up to where the next report
        # begins (the next report's line, or else its sentence). Lines above the first report are a
        # header shared by all of them ("Sex: 2-sex", "Цех №1, смена 1.").
        bounds = [view.rfind("\n", 0, matches[0].start()) + 1]
        for prev, nxt in zip(matches, matches[1:], strict=False):
            gap = view[prev.end():nxt.start()]
            breaks = [b.end() for b in re.finditer(r"\n|[.;!]\s", gap)]
            newline = gap.rfind("\n")
            bounds.append(prev.end() + (newline + 1 if newline >= 0 else breaks[-1] if breaks else len(gap)))
        bounds.append(len(view))
        header = (0, bounds[0])
        records = []
        dates_all = DATE_SPAN.findall(text)
        for idx, m in enumerate(matches):
            start, end = bounds[idx], bounds[idx + 1]

            def find(*regexes: re.Pattern, start: int = start, end: int = end) -> re.Match | None:
                for lo, hi in ((start, end), header):  # the report's own text wins over the header
                    for regex in regexes:
                        if found := regex.search(view, lo, hi):
                            return found
                return None

            quote = src.group(m)
            prefix = _PRODUCT_PREFIX.match(m.group(3))
            rec = {f["name"]: _fv(None) for f in rt["fields"]}
            rec["qty_produced"] = _fv(src.group(m, 1).strip(), 0.92, quote)
            if m.group(2):
                rec["unit"] = _fv(src.group(m, 2).strip("."), 0.9, quote)
            rec["product"] = _fv(src.cut(m.start(3) + prefix.end(), m.end(3)).strip(), 0.85, quote)
            acc = _ACCEPTED_QTY.search(view, start, end)
            if acc:
                rec["qty_accepted"] = _fv(src.group(acc, 1 if acc.group(1) else 2).strip(), 0.9, src.group(acc))
            dfc = _DEFECTIVE_QTY.search(view, start, end)
            if dfc:
                rec["qty_defective"] = _fv(src.group(dfc, 1 if dfc.group(1) else 2).strip(), 0.88, src.group(dfc))
            local_dates = DATE_SPAN.findall(text[src.offset(start):src.offset(end)])
            date_source = local_dates or (dates_all if len(set(dates_all)) == 1 else [])
            if date_source:
                rec["production_date"] = _fv(date_source[0], 0.9 if local_dates else 0.7)
                distinct = sorted(set(local_dates or dates_all) - {date_source[0]})
                if distinct:
                    rec["production_date"]["alternatives"] = distinct
                    rec["production_date"]["confidence"] = 0.5
            dep = find(_DEP_LABEL, _DEP_NUMBERED, _DEP_NAMED)
            if dep:
                rec["department"] = _fv(src.group(dep, 1).strip(), 0.88, src.group(dep))
            shift = find(_SHIFT)
            if shift:
                rec["shift"] = _fv(src.group(shift, 1 if shift.group(1) else 2), 0.85, src.group(shift))
            order = find(_ORDER)
            if order:
                rec["order"] = _fv(src.group(order, 1), 0.85, src.group(order))
            notes = next((f for f in rt["fields"] if f["name"] == "notes"), None)
            if notes and (hit := _label_value(src, [notes["label"], *notes.get("aliases", [])], start, end)):
                rec["notes"] = _fv(hit[0], 0.85, hit[1])
            records.append(rec)
        return records

    def _narrative_fill(self, src: _Source, rt: dict[str, Any], record: dict[str, Any]) -> None:
        def empty(name: str) -> bool:
            return name in record and record[name]["not_found"]

        text, view = src.text, src.view
        qty = re.search(rf"({_QTY})\s*({_UNIT})\s+([^\n,.;]{{2,60}})", view, re.IGNORECASE)
        if rt["code"] == "task":
            first = re.split(r"(?<=[.!?])\s+|\n", text.strip(), maxsplit=1)[0]
            if empty("title") and first:
                record["title"] = _fv(first[:200], 0.75, first[:200])
            due = re.search(rf"({DATE_SPAN.pattern})\s*(?:gacha|kunigacha|до)?|(?:до|by|until|muddat\w*:?)\s*({DATE_SPAN.pattern})",
                            text, re.IGNORECASE)
            if empty("due_date") and due:
                span = DATE_SPAN.search(due.group(0))
                record["due_date"] = _fv(span.group(0), 0.85, due.group(0))
            who = re.search(_lat(r"(?:mas['ʻ’]?ul|ответственн\w*|responsible|assignee|ijrochi)\s*[:—–-]\s*([^\n,.;]+)"), view,
                            re.IGNORECASE)
            if empty("assignee") and who:
                record["assignee"] = _fv(src.group(who, 1), 0.8, src.group(who))
            urgent = re.search(_lat(r"shoshilinch|срочно|urgent"), view, re.IGNORECASE)
            if empty("priority") and urgent:
                record["priority"] = _fv(src.group(urgent), 0.85)
            return
        if qty:
            q_field = "planned_qty" if rt["code"] == "production_order" else "quantity"
            if empty(q_field):
                record[q_field] = _fv(src.group(qty, 1), 0.88, src.group(qty))
            if empty("unit"):
                record["unit"] = _fv(src.group(qty, 2).strip("."), 0.88, src.group(qty))
            if empty("product"):
                stop = re.search(rf"\s+(?:{_PRODUCT_END})\b", qty.group(3), re.IGNORECASE)
                record["product"] = _fv(src.cut(qty.start(3), qty.start(3) + (stop.start() if stop else len(qty.group(3)))), 0.8,
                                        src.group(qty))
        dates = DATE_SPAN.findall(text)
        if rt["code"] == "stock_movement":
            if empty("movement_type"):
                kind = re.search(_lat(r"kirim|chiqim|приход|расход|receipt|issue[sd]?"), view, re.IGNORECASE)
                if kind:
                    record["movement_type"] = _fv(src.group(kind), 0.85)
            if empty("warehouse"):
                # A warehouse code next to the word: "RM omboriga", "со склада RM", "warehouse FG", else "2-ombor", "склад №1".
                wh = re.search(rf"\b([A-Z][A-Z0-9-]{{0,9}})\s+(?i:{WAREHOUSE})\w*", view) or \
                    re.search(rf"(?i:{WAREHOUSE})\w*\s*(?:№\s*)?([A-Z][A-Z0-9-]{{0,9}}|\d+)\b", view) or \
                    re.search(rf"((?:\d+\s*-?\s*)?(?i:{WAREHOUSE})\w*(?:\s*№?\s*\d+)?)", view)
                if wh:
                    record["warehouse"] = _fv(src.group(wh, 1), 0.75, src.group(wh))
            if empty("movement_date") and dates:
                record["movement_date"] = _fv(dates[0], 0.85)
        if rt["code"] == "production_order":
            if empty("planned_start") and dates:
                record["planned_start"] = _fv(dates[0], 0.8)
            if empty("planned_end") and len(dates) > 1:
                record["planned_end"] = _fv(dates[1], 0.8)

    # ------------------------------------------------------------------ map columns

    def _map_columns(self, params: dict[str, Any]) -> dict[str, Any]:
        best = None
        for rt in params["record_types"]:
            fields = rt["fields"] + rt.get("line_fields", [])
            used: set[str] = set()
            cols = []
            for col in params["columns"]:
                name = _match_header(col["header"], fields)
                if name in used:
                    name = None
                if name:
                    used.add(name)
                cols.append({"column_index": col["index"], "field": name, "confidence": 0.8 if name else 0.2})
            score = len(used)
            if best is None or score > best[0]:
                best = (score, rt["code"], cols)
        return {"record_type": best[1], "columns": best[2]}


MockProvider = DemoProvider  # former name, kept for callers that inject it explicitly
