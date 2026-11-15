"""Plain-text (.txt) and SubRip (.srt) export.

    export_txt(document, options=None) -> str
    export_srt(document, *, tashkeel="as_is", rtl_mark=False) -> str

Quran and exact-hadith text is copied verbatim (never stripped or normalized).
"""

from __future__ import annotations

from collections.abc import Mapping

from mofaregh_text.arabic.diacritics import strip_tashkeel

from .layout import (
    ORNATE_CLOSE,
    ORNATE_OPEN,
    QUOTE_CLOSE,
    QUOTE_OPEN,
    Footnote,
    build_layout,
    tag_label,
    to_digits,
)
from .options import ExportOptions

__all__ = ["export_txt", "export_srt", "format_srt_time"]

RLM = "‏"


def export_txt(document: Mapping, options: ExportOptions | None = None) -> str:
    """Same layout rules as the Word export. Footnotes become ``(n)`` markers
    with the notes listed after a separator line at the end."""
    options = options or ExportOptions()
    layout = build_layout(document, options)
    notes: list[str] = []
    lines: list[str] = []
    if layout.title:
        lines += [layout.title, ""]
    for para in layout.paragraphs:
        out = []
        for item in para.items:
            if isinstance(item, Footnote):
                notes.append("".join(s.text for s in item.spans))
                out.append(f"({to_digits(len(notes), options.reference_digits)})")
            else:
                out.append(item.text)
        lines += ["".join(out).strip(), ""]
    if notes:
        lines.append("-" * 20)
        for i, note in enumerate(notes, 1):
            lines.append(f"({to_digits(i, options.reference_digits)}) {note}")
        lines.append("")
    return "\n".join(lines).rstrip("\n") + "\n"


def format_srt_time(ms: int) -> str:
    """``hh:mm:ss,mmm`` e.g. 62345 -> ``00:01:02,345``."""
    ms = max(0, int(ms))
    h, rem = divmod(ms, 3_600_000)
    m, rem = divmod(rem, 60_000)
    s, milli = divmod(rem, 1000)
    return f"{h:02d}:{m:02d}:{s:02d},{milli:03d}"


def _flatten_segment(seg: Mapping, tashkeel: str) -> str:
    def plain(t: str | None) -> str:
        t = t or ""
        return strip_tashkeel(t) if tashkeel == "strip" else t

    parts: list[str] = []
    for block in seg.get("blocks") or []:
        kind = block.get("type")
        if kind == "text":
            parts.append(plain(block.get("text")))
        elif kind == "tag":
            parts.append(plain(tag_label(block)))
        elif kind == "quran":
            parts.append(ORNATE_OPEN + block["text"] + ORNATE_CLOSE)
        elif kind == "hadith":
            if block.get("match") == "exact":
                parts.append(QUOTE_OPEN + block["text"] + QUOTE_CLOSE)
            else:
                parts.append(plain(block.get("text")))
    return " ".join(p.strip() for p in parts if p and p.strip())


def export_srt(document: Mapping, *, tashkeel: str = "as_is", rtl_mark: bool = False) -> str:
    """One cue per non-empty segment, numbered from 1.

    ``rtl_mark`` prefixes each cue line with U+200F so players that guess the
    paragraph direction from the first strong character still render Arabic
    lines that start with digits or punctuation right-to-left.
    """
    if tashkeel not in ("as_is", "strip"):
        raise ValueError(f"tashkeel must be 'as_is' or 'strip', got {tashkeel!r}")
    cues: list[str] = []
    for seg in document.get("segments") or []:
        text = _flatten_segment(seg, tashkeel)
        if not text:
            continue
        start = int(seg.get("start_ms", 0))
        end = max(start, int(seg.get("end_ms", start)))
        if rtl_mark:
            text = "\n".join(RLM + line for line in text.split("\n"))
        cues.append(f"{len(cues) + 1}\n{format_srt_time(start)} --> {format_srt_time(end)}\n{text}\n")
    return "\n".join(cues)
