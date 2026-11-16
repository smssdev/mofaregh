"""Group recogniser segments into readable paragraphs (no model needed, so it is unit-tested)."""

from __future__ import annotations

from collections.abc import Iterable, Mapping

SENTENCE_END = (".", "؟", "!", "…", "؛")


def group_paragraphs(
    segments: Iterable[Mapping],
    *,
    gap_s: float = 1.5,
    soft_chars: int = 350,
    hard_chars: int = 700,
    unclear_below: float = 0.5,
) -> list[dict]:
    """A new paragraph starts after a pause longer than ``gap_s``, at a sentence
    end once ``soft_chars`` is reached, and unconditionally at ``hard_chars``.

    ``segments``: mappings with ``start``/``end`` in seconds and ``text``; optionally
    ``words``: ``[{"start", "end", "word", "probability"}]``. Words the recogniser
    was unsure of (probability below ``unclear_below``) are reported with their exact
    times and their position in the paragraph text, so the editor can mark them.

    Returns ``[{"start_ms", "end_ms", "text", "unclear": [{"start_ms", "end_ms", "text", "offset"}]}]``.
    """
    paragraphs: list[dict] = []
    current: dict | None = None
    for seg in segments:
        text = " ".join(str(seg["text"]).split())
        if not text:
            continue
        start, end = float(seg["start"]), float(seg["end"])
        if current is not None:
            length = len(current["text"])
            paused = start - current["end"] > gap_s
            sentence_done = current["text"].endswith(SENTENCE_END)
            if paused or length >= hard_chars or (length >= soft_chars and sentence_done):
                paragraphs.append(current)
                current = None
        if current is None:
            current = {"start": start, "end": end, "text": text, "unclear": []}
            base = 0
        else:
            base = len(current["text"]) + 1
            current["end"] = end
            current["text"] += " " + text

        cursor = 0  # words are searched in order, so a repeated word maps to its own occurrence
        for word in seg.get("words") or []:
            token = str(word.get("word", "")).strip()
            if not token:
                continue
            index = text.find(token, cursor)
            if index == -1:
                continue
            cursor = index + len(token)
            if float(word.get("probability", 1.0)) < unclear_below:
                current["unclear"].append({
                    "start_ms": int(round(float(word["start"]) * 1000)),
                    "end_ms": int(round(float(word["end"]) * 1000)),
                    "text": token,
                    "offset": base + index,
                })
    if current is not None:
        paragraphs.append(current)
    return [
        {
            "start_ms": int(round(p["start"] * 1000)),
            "end_ms": int(round(p["end"] * 1000)),
            "text": p["text"],
            "unclear": p["unclear"],
        }
        for p in paragraphs
    ]
