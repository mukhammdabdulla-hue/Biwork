"""Mock AI adapter — deterministic, rule-based, for development and tests (docs/architecture.md §7.2).

It implements the same interface as real providers so the whole pipeline can be exercised without
credentials, but it is NOT AI: it recognizes common phrasings (labelled fields, item tables, and
narrative production reports in Uzbek/Russian/English) with regular expressions. Every job
processed with it is flagged `ai_is_mock` and the UI shows a "Mock AI — not real analysis" banner.
"""

from __future__ import annotations

import re
import time
from typing import Any

from pydantic import BaseModel

from app.ai.policy import detect_injection
from app.ai.providers.base import ContentBlock, LLMResult, PromptTask, TextBlock
from app.core.locale_data import DATE_SPAN
from app.core.text import normalize_key

UNIT_WORDS = (
    "dona", "ta", "шт", "штук", "pcs", "pc", "pieces", "kg", "кг", "kilogramm", "tonna", "тонн", "t", "litr", "литр", "l", "л",
    "metr", "метр", "m", "м", "qop", "мешков", "мешка", "мешок", "bags", "quti", "коробок", "коробки", "boxes", "komplekt",
    "комплект", "rulon", "рулон", "m2", "м2", "m3", "м3", "pachka", "упаковок",
)
_UNIT = r"(?:" + "|".join(sorted(map(re.escape, UNIT_WORDS), key=len, reverse=True)) + r")\.?"
_QTY = r"\d[\d\s.,]*\d|\d"

PRODUCED = r"ishlab\s+chiqarildi|ishlab\s+chiqarilgan|tayyorlandi|produced|manufactured|выпущено|произведено|изготовлено"
ACCEPTED = r"sifat\s+nazoratidan\s+o['ʻ’`]?tdi|sifatdan\s+o['ʻ’`]?tdi|qabul\s+qilindi|accepted|passed(?:\s+qc)?|принято|годн\w*|прошл\w*\s+отк"
DEFECTIVE = r"brak|yaroqsiz|nuqsonli|defective|rejected|scrap|брак\w*|дефект\w*"
WORKSHOP = r"sex|цех|workshop|uchastka|участок"

_DOC_KEYWORDS = {
    "production_report": ("ishlab chiqarildi", "produced", "выпущено", "произведено", "brak", "брак", "sifat", "отк", "accepted",
                          "defective", "смена", "smena"),
    "purchase_request": ("заявка", "zayavka", "ariza", "purchase request", "закуп", "xarid", "sotib olish", "поставщик",
                         "yetkazib", "supplier", "needed by", "срок поставки"),
    "production_order": ("buyurtma", "заказ на производство", "production order", "наряд", "план выпуска", "ishlab chiqarish rejasi"),
    "stock_movement": ("kirim", "chiqim", "приход", "расход", "omborga", "ombordan", "склад", "warehouse", "receipt", "issued"),
    "task": ("vazifa", "topshiriq", "задача", "поручение", "task", "please", "iltimos", "прошу", "kerak", "необходимо"),
    "product": ("yangi mahsulot", "new product", "новый товар", "артикул", "artikul", "номенклатура"),
}


def _fv(value: str | None, confidence: float = 0.9, quote: str | None = None) -> dict[str, Any]:
    if value is None or str(value).strip() == "":
        return {"value": None, "confidence": 0.0, "source_quote": None, "not_found": True, "alternatives": []}
    value = str(value).strip().strip(" .,;:—–-")
    return {"value": value, "confidence": confidence, "source_quote": (quote or value).strip(), "not_found": False,
            "alternatives": []}


def _label_value(text: str, aliases: list[str]) -> tuple[str, str] | None:
    """Find 'Alias: value' / 'Alias — value' on a line."""
    for alias in sorted(aliases, key=len, reverse=True):
        if len(alias) < 2:
            continue
        m = re.search(rf"(?im)^[ \t*\-•]*{re.escape(alias)}\s*(?:№\s*)?[:—–=\-]\s*(.+?)\s*$", text)
        if m and m.group(1).strip():
            return m.group(1).strip(), m.group(0).strip()
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


class MockProvider:
    name = "mock"
    is_mock = True
    supports_vision = False
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
        return LLMResult(parsed=parsed, model="mock-rules-v1", input_tokens=len(text) // 4, output_tokens=0,
                         latency_ms=int((time.monotonic() - started) * 1000), stop_reason="end_turn")

    # ------------------------------------------------------------------ classify

    def _classify(self, text: str, params: dict[str, Any]) -> dict[str, Any]:
        lowered = text.lower()
        results = []
        for rt in params["record_types"]:
            hits = [k for k in _DOC_KEYWORDS.get(rt["code"], ()) if k in lowered]
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
        if rt["code"] == "production_report":
            records = self._production_reports(text, rt)
            if records:
                return {"records": records}
        record = self._labelled_record(text, rt)
        if rt.get("line_fields"):
            record["lines"] = self._lines(text, rt["line_fields"])
        if rt["code"] in ("production_order", "stock_movement", "task"):
            self._narrative_fill(text, rt, record)
        found = any(not v["not_found"] for k, v in record.items() if k != "lines") or record.get("lines")
        return {"records": [record] if found else []}

    def _labelled_record(self, text: str, rt: dict[str, Any]) -> dict[str, Any]:
        record: dict[str, Any] = {}
        for f in rt["fields"]:
            hit = _label_value(text, [f["label"], *f.get("aliases", [])])
            if hit and f["type"] == "date":
                span = DATE_SPAN.search(hit[0])
                record[f["name"]] = _fv(span.group(0) if span else hit[0], 0.9 if span else 0.5, hit[1])
            elif hit:
                record[f["name"]] = _fv(hit[0], 0.88, hit[1])
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

    def _production_reports(self, text: str, rt: dict[str, Any]) -> list[dict[str, Any]]:
        pattern = re.compile(rf"({_QTY})\s*({_UNIT})?\s+(.{{1,80}}?)\s+(?:{PRODUCED})", re.IGNORECASE | re.UNICODE)
        matches = list(pattern.finditer(text))
        if not matches:  # "Выпущено 1200 шт изделия Б-12" (verb first)
            pattern = re.compile(rf"(?:{PRODUCED})\s*[:—–-]?\s*({_QTY})\s*({_UNIT})?\s+([^,.;\n]{{1,80}})", re.IGNORECASE)
            matches = list(pattern.finditer(text))
        if not matches:
            return []
        records = []
        dates_all = DATE_SPAN.findall(text)
        for idx, m in enumerate(matches):
            start = m.start()
            end = matches[idx + 1].start() if idx + 1 < len(matches) else len(text)
            seg_start = text.rfind("\n", 0, start) + 1 if idx == 0 else matches[idx - 1].end()
            segment = text[seg_start:end]
            product = re.sub(r"^(?:kuni|da|day|of|продукции|изделий)\s+", "", m.group(3).strip(), flags=re.IGNORECASE)
            rec = {f["name"]: _fv(None) for f in rt["fields"]}
            rec["qty_produced"] = _fv(m.group(1).strip(), 0.92, m.group(0))
            if m.group(2):
                rec["unit"] = _fv(m.group(2).strip("."), 0.9, m.group(0))
            rec["product"] = _fv(product, 0.85, m.group(0))
            acc = re.search(rf"({_QTY})\s*(?:{_UNIT})?\s*(?:\w+\s+){{0,3}}?(?:{ACCEPTED})|(?:{ACCEPTED})\s*[:—–-]?\s*({_QTY})",
                            segment, re.IGNORECASE)
            if acc:
                rec["qty_accepted"] = _fv((acc.group(1) or acc.group(2)).strip(), 0.9, acc.group(0))
            dfc = re.search(rf"({_QTY})\s*(?:{_UNIT})?\s*(?:\w+\s+){{0,3}}?(?:{DEFECTIVE})|(?:{DEFECTIVE})\w*\s*[:—–-]?\s*({_QTY})",
                            segment, re.IGNORECASE)
            if dfc:
                rec["qty_defective"] = _fv((dfc.group(1) or dfc.group(2)).strip(), 0.88, dfc.group(0))
            local_dates = DATE_SPAN.findall(segment)
            date_source = local_dates or (dates_all if len(set(dates_all)) == 1 else [])
            if date_source:
                rec["production_date"] = _fv(date_source[0], 0.9 if local_dates else 0.7)
                distinct = sorted(set(local_dates or dates_all) - {date_source[0]})
                if distinct:
                    rec["production_date"]["alternatives"] = distinct
                    rec["production_date"]["confidence"] = 0.5
            dep = re.search(rf"(?:mas['ʻ’]?ul\s+)?(?:{WORKSHOP})\s*(?:№\s*)?[—–:\-]\s*([^\n.;,]+)", text, re.IGNORECASE) or \
                re.search(rf"(\d+\s*-?\s*(?:{WORKSHOP}))\b|((?:{WORKSHOP})\s*№?\s*\d+)", text, re.IGNORECASE)
            if dep:
                value = next(g for g in dep.groups() if g)
                rec["department"] = _fv(value.strip(), 0.88, dep.group(0))
            shift = re.search(r"(\d+)\s*-?\s*(?:smena|смена|shift)|(?:smena|смена|shift)\s*№?\s*(\d+)", segment, re.IGNORECASE)
            if shift:
                rec["shift"] = _fv(shift.group(1) or shift.group(2), 0.85, shift.group(0))
            order = re.search(r"(?:buyurtma|заказ\w*|order)\s*(?:№|no\.?|#)?\s*([A-Z]{1,4}-?\d[\w\-/]*)", segment, re.IGNORECASE)
            if order:
                rec["order"] = _fv(order.group(1), 0.85, order.group(0))
            records.append(rec)
        return records

    def _narrative_fill(self, text: str, rt: dict[str, Any], record: dict[str, Any]) -> None:
        def empty(name: str) -> bool:
            return name in record and record[name]["not_found"]

        qty = re.search(rf"({_QTY})\s*({_UNIT})\s+([^\n,.;]{{2,60}})", text, re.IGNORECASE)
        if rt["code"] == "task":
            first = re.split(r"(?<=[.!?])\s+|\n", text.strip(), maxsplit=1)[0]
            if empty("title") and first:
                record["title"] = _fv(first[:200], 0.75, first[:200])
            due = re.search(rf"({DATE_SPAN.pattern})\s*(?:gacha|kunigacha|до)?|(?:до|by|until|muddat\w*:?)\s*({DATE_SPAN.pattern})",
                            text, re.IGNORECASE)
            if empty("due_date") and due:
                span = DATE_SPAN.search(due.group(0))
                record["due_date"] = _fv(span.group(0), 0.85, due.group(0))
            who = re.search(r"(?:mas['ʻ’]?ul|ответственн\w*|responsible|assignee|ijrochi)\s*[:—–-]\s*([^\n,.;]+)", text, re.IGNORECASE)
            if empty("assignee") and who:
                record["assignee"] = _fv(who.group(1), 0.8, who.group(0))
            if empty("priority") and re.search(r"shoshilinch|срочно|urgent", text, re.IGNORECASE):
                record["priority"] = _fv(re.search(r"shoshilinch|срочно|urgent", text, re.IGNORECASE).group(0), 0.85)
            return
        if qty:
            q_field = "planned_qty" if rt["code"] == "production_order" else "quantity"
            if empty(q_field):
                record[q_field] = _fv(qty.group(1), 0.88, qty.group(0))
            if empty("unit"):
                record["unit"] = _fv(qty.group(2).strip("."), 0.88, qty.group(0))
            if empty("product"):
                record["product"] = _fv(re.split(r"\s+(?:kirim|chiqim|omborga|ombordan|на|в|со|from|to|uchun)\b",
                                                 qty.group(3), maxsplit=1, flags=re.IGNORECASE)[0], 0.8, qty.group(0))
        dates = DATE_SPAN.findall(text)
        if rt["code"] == "stock_movement":
            if empty("movement_type"):
                kind = re.search(r"kirim|chiqim|приход|расход|receipt|issue[sd]?", text, re.IGNORECASE)
                if kind:
                    record["movement_type"] = _fv(kind.group(0), 0.85)
            if empty("warehouse"):
                wh = re.search(r"((?:\d+\s*-?\s*)?(?:ombor|склад|warehouse)\w*(?:\s*№?\s*\d+)?)", text, re.IGNORECASE)
                if wh:
                    record["warehouse"] = _fv(wh.group(1), 0.75)
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
