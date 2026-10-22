"""Stage 6 — Match (docs/architecture.md §6.1, §6.5): resolve references against master data,
apply module business rules, detect existing/duplicate records, and assign field states."""

from __future__ import annotations

import hashlib
import json
import uuid
from decimal import Decimal
from typing import Any

from sqlalchemy import select

from app.agent.models import ExtractedRecord, IngestionJob, MatchCandidate
from app.agent.pipeline.normalize import Env, normalize_value, spec_for
from app.core.manifest import Issue, RecordTypeSpec, RuleInput
from app.core.matching import AMBIGUITY_MARGIN, Candidate, choose, get_resolver
from app.core.text import normalize_key

# Record types whose fingerprint is a natural key: a repeat inside one file is an error, not a warning.
UNIQUE_FINGERPRINT = {"production_report", "product"}
# Below this score a candidate is only "the closest", not a plausible reading of the text.
PLAUSIBLE_CANDIDATE = 0.6


def close_call(candidates: list[Candidate]) -> bool:
    """Two or more plausible candidates within the ambiguity margin: the text could mean either."""
    plausible = [c for c in candidates if c.score >= PLAUSIBLE_CANDIDATE]
    return len({c.entity_id for c in plausible}) > 1 and plausible[0].score - plausible[1].score < AMBIGUITY_MARGIN


def _resolve(env: Env, entity_type: str, value: str):
    key = (entity_type, normalize_key(value))
    if key not in env.resolve_cache:
        env.resolve_cache[key] = choose(get_resolver(entity_type).resolve(env.session, env.company_id, value))
    return env.resolve_cache[key]


def resolve_references(record: ExtractedRecord, rt: RecordTypeSpec, env: Env) -> list[Issue]:
    issues: list[Issue] = []
    previous = dict(record.references or {})
    refs: dict[str, Any] = {}
    for f in record.fields:
        spec = spec_for(rt, f.field)
        if spec is None or spec.type != "reference" or not f.normalized_value:
            continue
        text = str(f.normalized_value)
        manual = previous.get(f.field)
        if manual and manual.get("manual") and manual.get("text") == text and manual.get("id"):
            cand = get_resolver(spec.reference).get(env.session, env.company_id, uuid.UUID(manual["id"]))
            if cand is not None:
                refs[f.field] = {**cand.as_reference(), "match_kind": "manual", "manual": True, "text": text}
                record.match_candidates.append(MatchCandidate(
                    company_id=record.company_id, field=f.field, entity_type=spec.reference, entity_id=cand.entity_id,
                    label=cand.label, score=Decimal("1"), match_kind="manual", selected=True))
                continue
        choice = _resolve(env, spec.reference, text)
        for c in choice.candidates[:5]:
            record.match_candidates.append(MatchCandidate(
                company_id=record.company_id, field=f.field, entity_type=c.entity_type, entity_id=c.entity_id, label=c.label,
                score=Decimal(str(round(c.score, 3))), match_kind=c.kind,
                selected=choice.selected is not None and c.entity_id == choice.selected.entity_id,
            ))
        if choice.selected is not None:
            refs[f.field] = {**choice.selected.as_reference(), "text": text}
            if choice.reason == "fuzzy_selected":
                issues.append(Issue("reference_fuzzy", "warning",
                                    f"'{text}' was matched to {choice.selected.label} by similarity "
                                    f"({round(choice.selected.score * 100)}%); confirm it is correct", f.field))
            continue
        refs[f.field] = {"id": None, "text": text, "reason": choice.reason}
        if not spec.match_required:
            continue
        if choice.reason == "ambiguous" and close_call(choice.candidates):
            options = "; ".join(c.label for c in choice.candidates[:3] if c.score >= PLAUSIBLE_CANDIDATE)
            issues.append(Issue("reference_ambiguous", "error",
                                f"'{text}' could match several {spec.label.lower()} records ({options}); choose one", f.field))
        else:
            closest = f" Closest: {'; '.join(c.label for c in choice.candidates[:3])}." if choice.candidates else ""
            issues.append(Issue("reference_not_found", "error",
                                f"No {spec.label.lower()} matches '{text}'.{closest} Choose an existing one or add it to master data first",
                                f.field))
    record.references = refs
    return issues


def default_units(record: ExtractedRecord, rt: RecordTypeSpec, env: Env) -> list[Issue]:
    """An unstated unit is taken from the matched product's base unit (master data), with a visible note."""
    issues = []
    product_fields = [f for f in (*rt.fields, *rt.line_fields) if f.type == "reference" and f.reference == "product"]
    if not product_fields:
        return issues
    by_name = {f.field: f for f in record.fields}
    for f in record.fields:
        spec = spec_for(rt, f.field)
        if spec is None or spec.type != "unit" or f.raw_value:
            continue
        prefix = f.field.rsplit(".", 1)[0] + "." if "." in f.field else ""
        ref = (record.references or {}).get(prefix + product_fields[0].name) or {}
        base = (ref.get("data") or {}).get("base_unit")
        if not base or prefix + product_fields[0].name not in by_name:
            continue
        f.raw_value = base
        f.extracted_by = "derived"
        f.confidence = Decimal("1")
        f.model_value = {"value": base, "derived_from": "product base unit"}
        f.normalized_value = normalize_value(spec, base, env).value
        if prefix:
            idx = int(prefix.split(".")[1])
            record.payload["lines"][idx][spec.name] = f.normalized_value
        else:
            record.payload[spec.name] = f.normalized_value
        issues.append(Issue("unit_from_product", "info", f"Unit not stated; the product's base unit '{base}' is used", f.field))
    return issues


def rule_input(record: ExtractedRecord, env: Env) -> RuleInput:
    refs = {k: v for k, v in (record.references or {}).items()}
    return RuleInput(payload=record.payload, references=refs, company_id=record.company_id, session=env.session,
                     settings={**env.settings, "today": env.today})


def run_rules(record: ExtractedRecord, rt: RecordTypeSpec, env: Env) -> list[Issue]:
    inp = rule_input(record, env)
    issues: list[Issue] = []
    for validator in rt.validators:
        issues.extend(validator(inp))
    return issues


def fingerprint(record: ExtractedRecord, rt: RecordTypeSpec) -> str | None:
    if not rt.fingerprint_fields:
        return None
    values = []
    for name in rt.fingerprint_fields:
        ref = (record.references or {}).get(name)
        value = ref.get("id") if ref and ref.get("id") else record.payload.get(name)
        values.append(normalize_key(str(value)) if value is not None else None)
    if all(v is None for v in values):
        return None
    return hashlib.sha256(json.dumps([rt.code, *values]).encode()).hexdigest()


def detect_duplicates(record: ExtractedRecord, rt: RecordTypeSpec, env: Env) -> list[Issue]:
    issues: list[Issue] = []
    if rt.matcher is not None:
        for dup in rt.matcher(rule_input(record, env)):
            record.match_candidates.append(MatchCandidate(
                company_id=record.company_id, field=None, entity_type=dup.entity_type, entity_id=dup.entity_id, label=dup.label,
                score=Decimal(str(round(dup.score, 3))), match_kind=dup.kind, selected=False, details=dup.details,
            ))
            if dup.blocking:
                issues.append(Issue("duplicate_record", "error", f"Already recorded: {dup.label}. Saving it again would create a "
                                    "duplicate; reject this record or correct it", None, {"entity_id": str(dup.entity_id)}))
            else:
                issues.append(Issue("possible_duplicate", "warning", f"Similar record exists: {dup.label}", None,
                                    {"entity_id": str(dup.entity_id)}))
    record.fingerprint = fingerprint(record, rt)
    if record.fingerprint is None:
        return issues
    siblings = env.session.execute(select(ExtractedRecord.id, ExtractedRecord.seq, ExtractedRecord.job_id, ExtractedRecord.status).where(
        ExtractedRecord.company_id == record.company_id, ExtractedRecord.record_type == rt.code,
        ExtractedRecord.fingerprint == record.fingerprint, ExtractedRecord.id != record.id,
        ExtractedRecord.status.in_(("draft", "needs_review", "approved", "failed")),
    )).all()
    for sib_id, seq, job_id, status in siblings:
        if job_id == record.job_id and seq < record.seq:
            unique = rt.code in UNIQUE_FINGERPRINT
            issues.append(Issue("duplicate_in_file", "error" if unique else "warning",
                                f"Same {rt.label.lower()} as record #{seq} in this submission", None, {"record_id": str(sib_id)}))
        elif job_id != record.job_id:
            job = env.session.get(IngestionJob, job_id)
            record.match_candidates.append(MatchCandidate(
                company_id=record.company_id, field=None, entity_type="extracted_record", entity_id=sib_id,
                label=f"Pending in job '{(job.title or '')[:60]}' ({status})", score=Decimal("1"), match_kind="pending_record",
            ))
            issues.append(Issue("pending_elsewhere", "warning",
                                f"The same {rt.label.lower()} is also pending review in another submission "
                                f"('{(job.title or '')[:60]}'); only one of them can be saved", None, {"record_id": str(sib_id)}))
    return issues


# --------------------------------------------------------------------------- states & groups


def assign_states(record: ExtractedRecord, rt: RecordTypeSpec, issues: list[Issue], env: Env) -> None:
    by_field: dict[str, list[Issue]] = {}
    for issue in issues:
        if issue.field:
            by_field.setdefault(issue.field, []).append(issue)
    confidences = []
    for f in record.fields:
        spec = spec_for(rt, f.field)
        own = by_field.get(f.field, [])
        errors = [i for i in own if i.severity == "error"]
        conflict = bool((f.model_value or {}).get("alternatives")) and f.edited_by is None
        ref = (record.references or {}).get(f.field) or {}
        reason = None
        if errors:
            state = "missing" if all(i.rule_code == "required_missing" for i in errors) else "invalid"
            reason = errors[0].message
        elif f.raw_value in (None, ""):
            state = "missing"
        elif conflict:
            state = "conflicting"
            reason = "The source states different values: " + ", ".join(f.model_value["alternatives"][:3])
        elif any(i.severity == "warning" for i in own) or ref.get("match_kind") == "fuzzy":
            state = "low_confidence"
            reason = next((i.message for i in own if i.severity == "warning"), "Matched by similarity")
        elif f.edited_by is None and f.extracted_by not in ("form", "derived") and (f.confidence is None or float(f.confidence) < env.threshold):
            state = "low_confidence"
            reason = f"Extraction confidence {round(float(f.confidence or 0) * 100)}% is below the {round(env.threshold * 100)}% threshold"
        else:
            state = "high_confidence"
        f.state = state
        f.state_reason = (reason or "")[:300] or None
        if f.raw_value not in (None, "") and spec is not None:
            confidences.append(1.0 if f.edited_by else float(f.confidence if f.confidence is not None else 1))
    record.overall_confidence = Decimal(str(round(min(confidences), 3))) if confidences else None


def conflict_issues(record: ExtractedRecord) -> list[Issue]:
    out = []
    for f in record.fields:
        alts = (f.model_value or {}).get("alternatives") or []
        if alts and f.edited_by is None:
            out.append(Issue("conflicting_values", "error",
                             f"The source contains different values ({', '.join([str(f.raw_value), *alts][:4])}); "
                             "confirm the correct one", f.field))
    return out


def review_group(record: ExtractedRecord, issues: list[Issue]) -> str:
    if any(i.severity == "error" for i in issues):
        return "needs_correction"
    if any(i.severity == "warning" for i in issues) or any(
        f.state in ("low_confidence", "conflicting", "needs_approval") for f in record.fields
    ):
        return "needs_approval"
    return "ready"
