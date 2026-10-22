"""Server-Sent Events: job progress and notifications (polling of GET /agent/jobs/{id} is the fallback).

Uses `Authorization` like every other endpoint, so the SPA consumes it with fetch() streaming.
"""

from __future__ import annotations

import asyncio
import json

from fastapi import APIRouter
from fastapi.responses import StreamingResponse

from app.config import get_settings
from app.core.api import Ctx

router = APIRouter(tags=["events"])


@router.get("/events")
async def events(ctx: Ctx) -> StreamingResponse:
    channel = f"biwork:events:{ctx.company_id}"
    url = get_settings().redis_url

    async def stream():
        yield "retry: 5000\n\n"
        if not url:
            while True:  # no broker: heartbeats only, the client falls back to polling
                yield ": heartbeat\n\n"
                await asyncio.sleep(15)
        import redis.asyncio as aioredis

        client = aioredis.from_url(url)
        pubsub = client.pubsub()
        await pubsub.subscribe(channel)
        try:
            while True:
                message = await pubsub.get_message(ignore_subscribe_messages=True, timeout=15)
                if message is None:
                    yield ": heartbeat\n\n"
                    continue
                data = message["data"].decode() if isinstance(message["data"], bytes) else str(message["data"])
                kind = json.loads(data).get("type", "message")
                yield f"event: {kind}\ndata: {data}\n\n"
        finally:
            await pubsub.unsubscribe(channel)
            await client.aclose()

    return StreamingResponse(stream(), media_type="text/event-stream",
                             headers={"Cache-Control": "no-store", "X-Accel-Buffering": "no"})
