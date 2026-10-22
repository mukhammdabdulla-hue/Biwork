"""Versioned prompt templates (docs/architecture.md §7.4).

Prompts live in `<task>/<version>.md` with `## system` and `## instructions` sections; templates
use `$name` placeholders. The active version per task is pinned here and logged on every call.
"""

from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from string import Template

ACTIVE = {"classify": "v1", "extract": "v1", "map_columns": "v1"}
_DIR = Path(__file__).parent


@dataclass(frozen=True)
class Prompt:
    task: str
    version: str
    system: str
    instructions: Template

    @property
    def ref(self) -> str:
        return f"{self.task}@{self.version}"


@lru_cache
def load(task: str, version: str | None = None) -> Prompt:
    version = version or ACTIVE[task]
    text = (_DIR / task / f"{version}.md").read_text(encoding="utf-8")
    sections: dict[str, str] = {}
    current = None
    for line in text.splitlines():
        if line.startswith("## "):
            current = line[3:].strip()
            sections[current] = ""
        elif current:
            sections[current] += line + "\n"
    return Prompt(task, version, sections["system"].strip(), Template(sections["instructions"].strip()))


def pipeline_prompt_version() -> str:
    return ",".join(f"{task}@{version}" for task, version in sorted(ACTIVE.items()))
