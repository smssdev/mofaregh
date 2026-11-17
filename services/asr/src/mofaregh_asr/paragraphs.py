"""Group recogniser segments into readable paragraphs (no model needed, so it is unit-tested)."""

from __future__ import annotations

import re
from collections.abc import Iterable, Mapping

SENTENCE_END = (".", "؟", "!", "…", "؛")

# Lines Whisper is known to invent for Arabic audio over silence, music or noise (credits and channel outros from
# its training subtitles). A segment that is nothing but one of these is dropped.
KNOWN_HALLUCINATIONS = (
    "ترجمة نانسي قنقر",
    "اشتركوا في القناة",
    "اشترك في القناة",
    "لا تنسوا الاشتراك في القناة",
    "شكرا للمشاهدة",
    "شكرا على المشاهدة",
    "شكراً للمشاهدة",
    "شكراً على المشاهدة",
    "المترجم للقناة",
    "نانسي قنقر",
)

_PUNCT = re.compile(r"[\s.,،؛;:!?؟…\-ـ\"'«»()]+")
_TASHKEEL = re.compile(r"[ً-ْٰ]")  # harakat, tanween, shadda, sukun, dagger alif


def _key(text: str) -> str:
    """Text without diacritics, punctuation and spacing: what two segments are compared on."""
    return _PUNCT.sub("", _TASHKEEL.sub("", str(text)))


_HALLUCINATION_KEYS = {_key(h) for h in KNOWN_HALLUCINATIONS}


def drop_hallucinations(segments: Iterable[Mapping], *, max_repeats: int = 2) -> list[Mapping]:
    """Removes what Whisper invents rather than hears: segments that are only a known outro line, and a loop
    where the same segment comes back again and again (more than ``max_repeats`` in a row is a loop, a speaker
    repeating a phrase twice is not)."""
    kept: list[Mapping] = []
    previous, run = None, 0
    for seg in segments:
        key = _key(seg.get("text", ""))
        if not key or key in _HALLUCINATION_KEYS:
            continue
        run = run + 1 if key == previous else 1
        previous = key
        if run > max_repeats:
            continue
        kept.append(seg)
    return kept


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


def clip_segments(segments: Iterable[Mapping], *, keep_before: float | None = None, offset: float = 0.0) -> list[dict]:
    """For a part of a file transcribed on its own: drops what starts at or after ``keep_before`` (seconds in the
    clip; the clip runs a little past it so the last word is not cut in half), cuts a segment that straddles it
    word by word, then moves every time by ``offset`` so it is a time in the whole file."""
    out: list[dict] = []
    for seg in segments:
        start, end = float(seg["start"]), float(seg["end"])
        words = list(seg.get("words") or [])
        text = str(seg["text"])
        if keep_before is not None:
            if start >= keep_before:
                continue
            if end > keep_before and words:
                words = [w for w in words if float(w["start"]) < keep_before]
                if not words:
                    continue
                text = "".join(str(w["word"]) for w in words)
                end = min(end, float(words[-1]["end"]))
        out.append({
            "start": start + offset,
            "end": end + offset,
            "text": text,
            "words": [{**w, "start": float(w["start"]) + offset, "end": float(w["end"]) + offset} for w in words],
        })
    return out
