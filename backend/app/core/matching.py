"""Entity matching primitives (docs/architecture.md §6.1 stage 6, §6.5).

Resolution order: exact code -> exact name/alias -> fuzzy (pg_trgm in SQL for large master data,
rapidfuzz in Python for small reference sets). Resolvers only *propose* candidates; whether a
candidate may be selected automatically is decided by `choose()`, which never picks between
ambiguous candidates.
"""

from __future__ import annotations

import uuid
from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass, field
from typing import Any, Protocol

from rapidfuzz import fuzz
from sqlalchemy import func, select, text
from sqlalchemy.orm import Session

from app.core.text import normalize_key

EXACT, ALIAS, FUZZY = "exact", "alias", "fuzzy"
AUTO_SELECT_FUZZY = 0.90
AMBIGUITY_MARGIN = 0.08
MIN_CANDIDATE_SCORE = 0.35


@dataclass(frozen=True)
class Candidate:
    entity_type: str
    entity_id: uuid.UUID
    label: str
    score: float
    kind: str
    data: dict[str, Any] = field(default_factory=dict)

    def as_reference(self) -> dict[str, Any]:
        return {"id": str(self.entity_id), "label": self.label, "match_kind": self.kind,
                "score": round(self.score, 3), **({"data": self.data} if self.data else {})}


class EntityResolver(Protocol):
    entity_type: str

    def resolve(self, session: Session, company_id: uuid.UUID, value: str, **context: Any) -> list[Candidate]: ...

    def get(self, session: Session, company_id: uuid.UUID, entity_id: uuid.UUID) -> Candidate | None: ...


@dataclass(frozen=True)
class Choice:
    selected: Candidate | None
    candidates: list[Candidate]
    reason: str  # matched|fuzzy_selected|ambiguous|not_found


def choose(candidates: Sequence[Candidate]) -> Choice:
    ranked = sorted(candidates, key=lambda c: (-c.score, c.label))
    if not ranked:
        return Choice(None, [], "not_found")
    top = ranked[0]
    runner_up = ranked[1] if len(ranked) > 1 else None
    distinct = runner_up is None or runner_up.entity_id == top.entity_id or top.score - runner_up.score >= AMBIGUITY_MARGIN
    if top.kind in (EXACT, ALIAS) and distinct:
        return Choice(top, ranked, "matched")
    if top.kind == FUZZY and top.score >= AUTO_SELECT_FUZZY and distinct:
        return Choice(top, ranked, "fuzzy_selected")
    return Choice(None, ranked, "ambiguous")


def _dedupe(candidates: Iterable[Candidate]) -> list[Candidate]:
    best: dict[uuid.UUID, Candidate] = {}
    for c in candidates:
        if c.score < MIN_CANDIDATE_SCORE:
            continue
        current = best.get(c.entity_id)
        if current is None or c.score > current.score:
            best[c.entity_id] = c
    return sorted(best.values(), key=lambda c: -c.score)[:8]


def _identifier_tokens(key: str) -> list[str]:
    """Short or numeric tokens ("1", "a", "b2") that distinguish otherwise similar names."""
    return sorted(t for t in key.split() if t.isdigit() or len(t) <= 2)


def fuzzy_score(a: str, b: str) -> float:
    na, nb = normalize_key(a), normalize_key(b)
    if not na or not nb:
        return 0.0
    score = max(fuzz.token_sort_ratio(na, nb), fuzz.ratio(na, nb)) / 100.0
    # "1-sex" vs "2-sex" or "A mahsulot" vs "B mahsulot" look alike but are different entities.
    if _identifier_tokens(na) != _identifier_tokens(nb):
        score *= 0.5
    return score


@dataclass
class AliasTableResolver:
    """Resolver for master data with an alias table and a trigram index (products, departments,
    suppliers). Each entity's own name and code are kept as aliases by the owning service."""

    entity_type: str
    model: Any
    alias_model: Any
    alias_fk: str
    label: Callable[[Any], str]
    code_attr: str | None = "code"
    data: Callable[[Any], dict[str, Any]] = lambda _e: {}
    filters: Callable[[Any], list[Any]] = lambda _m: []

    def _base(self, company_id: uuid.UUID):
        m = self.model
        stmt = select(m).where(m.company_id == company_id)
        if hasattr(m, "is_active"):
            stmt = stmt.where(m.is_active.is_(True))
        if hasattr(m, "deleted_at"):
            stmt = stmt.where(m.deleted_at.is_(None))
        for f in self.filters(m):
            stmt = stmt.where(f)
        return stmt

    def _cand(self, entity: Any, score: float, kind: str) -> Candidate:
        return Candidate(self.entity_type, entity.id, self.label(entity), score, kind, self.data(entity))

    def get(self, session: Session, company_id: uuid.UUID, entity_id: uuid.UUID) -> Candidate | None:
        entity = session.execute(self._base(company_id).where(self.model.id == entity_id)).scalar_one_or_none()
        return self._cand(entity, 1.0, EXACT) if entity else None

    def resolve(self, session: Session, company_id: uuid.UUID, value: str, **_: Any) -> list[Candidate]:
        value = (value or "").strip()
        key = normalize_key(value)
        if not key:
            return []
        found: list[Candidate] = []
        if self.code_attr:
            code_col = getattr(self.model, self.code_attr)
            for e in session.execute(self._base(company_id).where(func.lower(code_col) == value.lower())).scalars():
                found.append(self._cand(e, 1.0, EXACT))
        alias = self.alias_model
        fk = getattr(alias, self.alias_fk)
        rows = session.execute(
            select(fk, alias.normalized_alias, func.similarity(alias.normalized_alias, key).label("sim"))
            .where(alias.company_id == company_id)
            .where(alias.normalized_alias.op("%")(key) | (alias.normalized_alias == key))
            .order_by(text("sim DESC"))
            .limit(20)
        ).all()
        if rows:
            ids = {r[0] for r in rows}
            entities = {e.id: e for e in session.execute(self._base(company_id).where(self.model.id.in_(ids))).scalars()}
            sorted_key = " ".join(sorted(key.split()))
            for entity_id, norm_alias, sim in rows:
                entity = entities.get(entity_id)
                if entity is None:
                    continue
                if norm_alias == key or " ".join(sorted(norm_alias.split())) == sorted_key:
                    found.append(self._cand(entity, 0.97, ALIAS))
                else:
                    score = fuzzy_score(norm_alias, key) * 0.95
                    found.append(self._cand(entity, score, FUZZY))
        return _dedupe(found)


@dataclass
class ListResolver:
    """Resolver for small reference sets (warehouses, shifts, lines, users): scored in Python."""

    entity_type: str
    load: Callable[[Session, uuid.UUID], Iterable[Any]]
    label: Callable[[Any], str]
    keys: Callable[[Any], Iterable[str]]  # strings that identify the entity exactly (code, name, ...)
    data: Callable[[Any], dict[str, Any]] = lambda _e: {}
    fuzzy: bool = True  # False for identifiers (order numbers) where near-misses are different entities

    def get(self, session: Session, company_id: uuid.UUID, entity_id: uuid.UUID) -> Candidate | None:
        for e in self.load(session, company_id):
            if e.id == entity_id:
                return Candidate(self.entity_type, e.id, self.label(e), 1.0, EXACT, self.data(e))
        return None

    def resolve(self, session: Session, company_id: uuid.UUID, value: str, **_: Any) -> list[Candidate]:
        key = normalize_key(value)
        if not key:
            return []
        sorted_key = " ".join(sorted(key.split()))
        found = []
        for e in self.load(session, company_id):
            keys = [k for k in self.keys(e) if k]
            norm = [normalize_key(k) for k in keys]
            if key in norm or sorted_key in {" ".join(sorted(n.split())) for n in norm}:
                found.append(Candidate(self.entity_type, e.id, self.label(e), 1.0 if key == norm[0] else 0.97,
                                       EXACT if key == norm[0] else ALIAS, self.data(e)))
                continue
            if not self.fuzzy:
                continue
            score = max((fuzzy_score(k, value) for k in keys), default=0.0) * 0.95
            found.append(Candidate(self.entity_type, e.id, self.label(e), score, FUZZY, self.data(e)))
        return _dedupe(found)


_RESOLVERS: dict[str, EntityResolver] = {}


def register_resolver(resolver: EntityResolver) -> None:
    _RESOLVERS[resolver.entity_type] = resolver


def get_resolver(entity_type: str) -> EntityResolver:
    return _RESOLVERS[entity_type]


def registered_entity_types() -> list[str]:
    return sorted(_RESOLVERS)
