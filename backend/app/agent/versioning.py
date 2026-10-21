"""`pipeline_version` = code version + git SHA + hash of the processing configuration (§6.2)."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from functools import lru_cache

from app.ai.prompts import pipeline_prompt_version
from app.config import get_settings

CODE_VERSION = "0.1.0"


@lru_cache
def git_sha() -> str:
    sha = os.environ.get("BIWORK_GIT_SHA")
    if sha:
        return sha[:12]
    try:
        return subprocess.run(["git", "rev-parse", "--short=12", "HEAD"], capture_output=True, text=True, timeout=2,
                              cwd=os.path.dirname(__file__)).stdout.strip() or "unknown"
    except Exception:
        return "unknown"


@lru_cache
def pipeline_version() -> str:
    s = get_settings()
    config = {
        "ai_provider": s.ai_provider, "model_extract": s.ai_model_extract, "model_classify": s.ai_model_classify,
        "effort": s.ai_effort, "threshold": s.agent_confidence_threshold, "ocr": s.ocr_langs,
        "prompts": pipeline_prompt_version(),
    }
    digest = hashlib.sha256(json.dumps(config, sort_keys=True).encode()).hexdigest()[:8]
    return f"{CODE_VERSION}+{git_sha()}.cfg-{digest}"
