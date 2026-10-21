from fastapi import FastAPI

from app.api.v1 import agent


def create_app() -> FastAPI:
    app = FastAPI(title="BIWORK API", version="0.1.0")
    app.include_router(agent.router, prefix="/api/v1")

    @app.get("/healthz", include_in_schema=False)
    async def healthz() -> dict[str, str]:
        return {"status": "ok"}

    return app


app = create_app()
