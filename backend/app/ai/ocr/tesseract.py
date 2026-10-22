"""Tesseract 5 OCR adapter with light pre-processing (grayscale, contrast, upscaling, rotation)."""

from __future__ import annotations

import re
from functools import lru_cache

from PIL import Image, ImageOps

from app.ai.ocr.base import OCRLine, OCRResult, OCRUnavailable
from app.config import get_settings

Image.MAX_IMAGE_PIXELS = 80_000_000  # refuse decompression bombs


@lru_cache
def _languages() -> str:
    import pytesseract

    s = get_settings()
    if s.tesseract_cmd:
        pytesseract.pytesseract.tesseract_cmd = s.tesseract_cmd
    try:
        installed = set(pytesseract.get_languages(config=""))
    except Exception as exc:  # tesseract binary missing
        raise OCRUnavailable(f"Tesseract is not available: {exc}") from exc
    wanted = [lang for lang in s.ocr_langs.split("+") if lang in installed]
    if not wanted:
        raise OCRUnavailable(f"None of the OCR languages {s.ocr_langs} are installed")
    return "+".join(wanted)


def _prepare(image: Image.Image) -> tuple[Image.Image, int]:
    image = ImageOps.exif_transpose(image)
    gray = ImageOps.autocontrast(image.convert("L"))
    if gray.width < 1200:
        factor = 1200 / gray.width
        gray = gray.resize((int(gray.width * factor), int(gray.height * factor)), Image.LANCZOS)
    rotation = 0
    try:
        import pytesseract

        osd = pytesseract.image_to_osd(gray, config="--psm 0 -c min_characters_to_try=5")
        m = re.search(r"Rotate: (\d+)", osd)
        rotation = int(m.group(1)) if m else 0
        if rotation:
            gray = gray.rotate(-rotation, expand=True)
    except Exception:
        rotation = 0  # OSD fails on sparse text; recognition still proceeds
    return gray, rotation


class TesseractOCR:
    name = "tesseract"

    def recognize(self, image: Image.Image) -> OCRResult:
        import pytesseract

        if not get_settings().ocr_enabled:
            raise OCRUnavailable("OCR is disabled (OCR_ENABLED=false)")
        langs = _languages()
        prepared, rotation = _prepare(image)
        scale = image.width / prepared.width if rotation in (0, 180) else image.height / prepared.width
        data = pytesseract.image_to_data(prepared, lang=langs, config="--psm 3", output_type=pytesseract.Output.DICT)
        groups: dict[tuple[int, int, int], list[int]] = {}
        for i, word in enumerate(data["text"]):
            if word.strip() and float(data["conf"][i]) >= 0:
                groups.setdefault((data["block_num"][i], data["par_num"][i], data["line_num"][i]), []).append(i)
        lines: list[OCRLine] = []
        for idx in groups.values():
            words = [data["text"][i] for i in idx]
            confs = [float(data["conf"][i]) for i in idx]
            x0 = min(data["left"][i] for i in idx)
            top = min(data["top"][i] for i in idx)
            x1 = max(data["left"][i] + data["width"][i] for i in idx)
            bottom = max(data["top"][i] + data["height"][i] for i in idx)
            lines.append(OCRLine(" ".join(words), (x0 * scale, top * scale, x1 * scale, bottom * scale), sum(confs) / len(confs)))
        lines.sort(key=lambda ln: (round(ln.bbox[1] / 10), ln.bbox[0]))
        all_conf = [float(c) for c, t in zip(data["conf"], data["text"], strict=False) if t.strip() and float(c) >= 0]
        return OCRResult(
            text="\n".join(line.text for line in lines),
            lines=lines,
            mean_confidence=round(sum(all_conf) / len(all_conf), 1) if all_conf else None,
            width=image.width, height=image.height, rotation=rotation, engine=f"tesseract ({langs})",
        )


def get_ocr() -> TesseractOCR:
    return TesseractOCR()
