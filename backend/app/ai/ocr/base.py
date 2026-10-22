"""OCR provider interface (docs/architecture.md §6.3, D8)."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Protocol

from PIL import Image


@dataclass
class OCRLine:
    text: str
    bbox: tuple[float, float, float, float]  # x0, top, x1, bottom in image pixels
    confidence: float  # 0-100


@dataclass
class OCRResult:
    text: str
    lines: list[OCRLine] = field(default_factory=list)
    mean_confidence: float | None = None
    width: int = 0
    height: int = 0
    rotation: int = 0
    engine: str = ""


class OCRUnavailable(Exception):
    pass


class OCRProvider(Protocol):
    name: str

    def recognize(self, image: Image.Image) -> OCRResult: ...
