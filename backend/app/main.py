"""FastAPI application factory (docs/architecture.md §5.1).

Routers of product modules are mounted from their manifests; Core and Agent routers are mounted
explicitly. Everything lives under /api/v1 and is described by the generated OpenAPI schema.
"""

from __future__ import annotations

import logging

from fastapi import APIRouter, FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.agent.api import router as agent_router
from app.analytics.router import router as analytics_router
from app.api import admin, audit, events
from app.config import get_settings
from app.core.api import RateLimitMiddleware, RequestIdMiddleware, SecurityHeadersMiddleware, install_error_handlers
from app.core.routers import auth, files, health, master, tasks
from app.logging import configure_logging
from app.modules.registry import manifests


def create_app() -> FastAPI:
    settings = get_settings()
    configure_logging(settings.log_level, settings.log_json)
    app = FastAPI(
        title="BIWORK API",
        version="0.1.0",
        description="BIWORK — AI-powered manufacturing management platform. Company scope: `X-Company-Id` header.",
        openapi_url="/api/v1/openapi.json",
        docs_url="/api/docs",
        redoc_url=None,
    )
    install_error_handlers(app)
    app.add_middleware(health.MetricsMiddleware)
    app.add_middleware(RateLimitMiddleware)
    app.add_middleware(SecurityHeadersMiddleware)
    app.add_middleware(RequestIdMiddleware)
    app.add_middleware(
        CORSMiddleware, allow_origins=settings.cors_origins, allow_credentials=True,
        allow_methods=["GET", "POST", "PUT", "PATCH", "DELETE"],
        allow_headers=["Authorization", "Content-Type", "X-Company-Id", "Idempotency-Key", "If-Match", "X-Requested-With", "X-Request-ID"],
        expose_headers=["ETag", "X-Request-ID", "Idempotent-Replay"],
    )
    api = APIRouter(prefix="/api/v1")
    for router in (health.router, auth.router, admin.router, master.router, files.router, tasks.router, audit.router,
                   events.router, agent_router, analytics_router):
        api.include_router(router)
    for manifest in manifests():
        if manifest.available and manifest.router_factory is not None:
            api.include_router(manifest.router_factory())
    app.include_router(api)
    logging.getLogger("biwork").info("BIWORK API ready (env=%s, ai=%s)", settings.app_env, settings.ai_provider)
    return app


app = create_app()
