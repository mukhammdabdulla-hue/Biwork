"""Analytics API (/api/v1/analytics): executive dashboard, agent statistics, quality.

The executive overview is the home dashboard and always available; the analytics pages follow the
company's `analytics` module switch."""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Query

from app.analytics import service
from app.core.api import DB, Ctx, require_module

router = APIRouter(prefix="/analytics", tags=["analytics"])


@router.get("/overview")
def overview(ctx: Ctx, db: DB, days: int = Query(30, ge=7, le=365)) -> dict[str, Any]:
    ctx.require("analytics.read")
    return service.overview(db, ctx, days)


@router.get("/agent/stats", dependencies=[require_module("analytics")])
def agent(ctx: Ctx, db: DB, days: int = Query(30, ge=1, le=365)) -> dict[str, Any]:
    ctx.require("analytics.read")
    return service.agent_stats(db, ctx, days)


@router.get("/quality", dependencies=[require_module("analytics")])
def quality(ctx: Ctx, db: DB, days: int = Query(30, ge=7, le=365)) -> dict[str, Any]:
    ctx.require("analytics.read", "production.read")
    return service.quality(db, ctx, days)
