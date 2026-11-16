"""Format-independent layout of a Mofaregh document.

The Word and TXT exporters both render the output of :func:`build_layout`, so
the rules (paragraphing, tashkeel policy, labels, footnote contents) live in
one place and the writers only deal with their own syntax.

Locked text (Quran ``text``, exact-hadith ``text``, hadith ``source_text``)
is copied into exactly one :class:`Span` without any transformation: no
tashkeel stripping, no normalization, no sanitizing.
"""

from __future__ import annotations

import re
from collections.abc import Mapping
from dataclasses import dataclass, field
from typing import Literal, Union

from mofaregh_text.arabic.diacritics import strip_tashkeel

from .formulas import restore_formulas
from .options import ExportOptions

Role = Literal[
    "text",       # ordinary speech (also hadith narrated by meaning / not found)
    "quran",      # locked Quran text with ornate brackets
    "hadith",     # locked exact hadith text with «»
    "reference",  # Quran reference, e.g. [سورة ٢: ٥]
    "timestamp",
    "speaker",
    "tag",
    "takhrij",    # inline takhrij
    "source",     # locked hadith source text quoted inside a footnote
]
ParaKind = Literal["body", "quran", "hadith"]

ORNATE_OPEN = "﴿"   # ﴿  opening bracket in Arabic (right side in RTL)
ORNATE_CLOSE = "﴾"  # ﴾
QUOTE_OPEN = "«"    # «
QUOTE_CLOSE = "»"   # »

TAG_LABELS: Mapping[str, str] = {
    "inaudible": "غير واضح",
    "laughter": "ضحك",
    "crosstalk": "تداخل أصوات",
    "pause": "صمت",
    "interruption": "انقطاع",
    "other": "ملاحظة",
}
MEANING_NOTE = "رواية بالمعنى، ولفظه في المصدر: "
MEANING_NOTE_NO_SOURCE = "رواية بالمعنى"
NOT_FOUND_NOTE = "لم يُعثر على مصدره"
DEFAULT_REFERENCE_FORMAT = "[{surah_name}: {ayah_range}]"

_ARABIC_INDIC = str.maketrans("0123456789", "٠١٢٣٤٥٦٧٨٩")
# Characters not allowed in XML 1.0 (ordinary text only; locked text is never touched).
_XML_INVALID = re.compile("[\x00-\x08\x0b\x0c\x0e-\x1f￾￿]")


@dataclass
class Span:
    text: str
    role: Role = "text"


@dataclass
class Footnote:
    spans: list[Span]


Item = Union[Span, Footnote]


@dataclass
class Paragraph:
    kind: ParaKind
    items: list[Item] = field(default_factory=list)

    def add(self, item: Item) -> None:
        self.items.append(item)

    @property
    def is_empty(self) -> bool:
        return not any(isinstance(i, Footnote) or i.text.strip() for i in self.items)


@dataclass
class Layout:
    title: str
    paragraphs: list[Paragraph]

    @property
    def footnotes(self) -> list[Footnote]:
        return [i for p in self.paragraphs for i in p.items if isinstance(i, Footnote)]


# --------------------------------------------------------------------------- helpers


def to_digits(value: int | str, digits: str) -> str:
    s = str(value)
    return s.translate(_ARABIC_INDIC) if digits == "arabic_indic" else s


def format_timestamp(ms: int, digits: str = "western") -> str:
    """``[hh:mm:ss]`` (truncated to the second)."""
    total = max(0, int(ms)) // 1000
    h, rem = divmod(total, 3600)
    m, s = divmod(rem, 60)
    return "[" + to_digits(f"{h:02d}:{m:02d}:{s:02d}", digits) + "]"


def surah_label(surah: int, surah_names: Mapping[int, str] | None, digits: str = "arabic_indic") -> str:
    if surah_names and surah in surah_names:
        return surah_names[surah]
    return f"سورة {to_digits(surah, digits)}"


def format_quran_reference(block: Mapping, document: Mapping, options: ExportOptions) -> str:
    fmt = (document.get("settings") or {}).get("quran_reference_format") or DEFAULT_REFERENCE_FORMAT
    d = options.reference_digits
    start, end = int(block["ayah_start"]), int(block["ayah_end"])
    ayah_range = to_digits(start, d) if end == start else f"{to_digits(start, d)}-{to_digits(end, d)}"
    values = _SafeDict(
        surah_name=surah_label(int(block["surah"]), options.surah_names, d),
        surah=to_digits(block["surah"], d),
        ayah_range=ayah_range,
    )
    return fmt.format_map(values)


class _SafeDict(dict):
    def __missing__(self, key: str) -> str:  # unknown placeholder -> left as written
        return "{" + key + "}"


def tag_label(block: Mapping) -> str:
    tag = block.get("tag", "other")
    note = (block.get("note") or "").strip()
    if tag == "other" and note:
        return f"[{note}]"
    return f"[{TAG_LABELS.get(tag, TAG_LABELS['other'])}]"


def hadith_takhrij_text(block: Mapping) -> str | None:
    """Explicit ``takhrij`` or one assembled from collection / number / grade."""
    if block.get("takhrij"):
        return block["takhrij"].strip() or None
    parts = []
    if block.get("collection"):
        parts.append(f"رواه {block['collection']}")
    if block.get("number"):
        parts.append(f"رقم {block['number']}")
    if block.get("grade"):
        parts.append(f"الحكم: {block['grade']}")
    return "، ".join(parts) or None


# --------------------------------------------------------------------------- builder


_BREAK = object()  # paragraph boundary marker produced by expand_lines


class _Builder:
    def __init__(self, document: Mapping, options: ExportOptions):
        self.doc = document
        self.opt = options
        self.speakers = {s["id"]: s["name"] for s in document.get("speakers") or []}
        self.paragraphs: list[Paragraph] = []

    # ordinary text: policy-dependent
    def plain(self, text: str | None) -> str:
        text = _XML_INVALID.sub("", text or "")
        if self.opt.tashkeel == "strip":
            text = strip_tashkeel(text)
        return restore_formulas(text) if self.opt.keep_formulas else text

    def speaker_name(self, speaker_id: str | None) -> str | None:
        if speaker_id is None:
            return None
        return self.plain(self.speakers.get(speaker_id, speaker_id))

    # -- inline renderings ------------------------------------------------------

    def inline_items(self, block: Mapping) -> list[Item]:
        kind = block.get("type")
        if kind == "text":
            return [Span(self.plain(block.get("text")), "text")]
        if kind == "tag":
            return [Span(self.plain(tag_label(block)), "tag")]
        if kind == "quran":
            return self.quran_items(block)
        if kind == "hadith":
            return self.hadith_items(block)
        raise ValueError(f"unknown block type: {kind!r}")

    def quran_items(self, block: Mapping) -> list[Item]:
        return [
            Span(ORNATE_OPEN + block["text"] + ORNATE_CLOSE, "quran"),
            Span(" ", "text"),
            Span(self.plain(format_quran_reference(block, self.doc, self.opt)), "reference"),
        ]

    def hadith_items(self, block: Mapping) -> list[Item]:
        match = block.get("match")
        takhrij = hadith_takhrij_text(block)
        takhrij = self.plain(takhrij) if takhrij else None
        items: list[Item] = []
        note: list[Span] = []

        if match == "exact":
            items.append(Span(QUOTE_OPEN + block["text"] + QUOTE_CLOSE, "hadith"))
        elif match == "meaning":
            items.append(Span(self.plain(block.get("text")), "text"))
            source = block.get("source_text")
            if source:
                note += [Span(MEANING_NOTE, "text"),
                         Span(QUOTE_OPEN + source + QUOTE_CLOSE, "source")]
            else:
                note.append(Span(MEANING_NOTE_NO_SOURCE, "text"))
        elif match == "not_found":
            items.append(Span(self.plain(block.get("text")), "text"))
            note.append(Span(NOT_FOUND_NOTE, "text"))
        else:
            raise ValueError(f"unknown hadith match: {match!r}")

        if takhrij:
            if self.opt.hadith_takhrij == "inline":
                items.append(Span(f" ({takhrij})", "takhrij"))
            else:
                if note:
                    note.append(Span(". ", "text"))
                note.append(Span(takhrij, "text"))
        if note:
            items.append(Footnote(note))
        return items

    # -- templates ----------------------------------------------------------------

    def prefix(self, para: Paragraph, start_ms: int | None, speaker: str | None) -> None:
        if start_ms is not None and self.opt.include_timestamps:
            para.add(Span(format_timestamp(start_ms, self.opt.timestamp_digits), "timestamp"))
            para.add(Span(" ", "text"))
        if speaker:
            para.add(Span(speaker + ":", "speaker"))
            para.add(Span(" ", "text"))

    @staticmethod
    def append_inline(para: Paragraph, items: list[Item]) -> None:
        if not items:
            return
        has_content = any(isinstance(i, Footnote) or (i.role not in ("timestamp", "speaker")
                                                     and i.text.strip()) for i in para.items)
        if has_content:
            para.add(Span(" ", "text"))
        para.items.extend(items)

    def build_transcript(self) -> None:
        for seg in self.doc.get("segments") or []:
            para = Paragraph("body")
            speaker = self.speaker_name(seg.get("speaker_id")) if self.opt.speakers_enabled else None
            self.prefix(para, seg.get("start_ms", 0), speaker)
            for block in seg.get("blocks") or []:
                self.append_inline(para, self.inline_items(block))
            if not para.is_empty:
                self.paragraphs.append(para)

    def expand_lines(self, blocks):
        """Client template: a text block with line breaks becomes one paragraph per
        line (poetry, lists); a line starting with a bullet becomes ``•<tab>text``
        exactly as in the reference. Other templates are untouched."""
        if self.opt.template != "client":
            yield from blocks
            return
        for block in blocks:
            text = block.get("text") if block.get("type") == "text" else None
            if not text or "\n" not in text:
                yield block
                continue
            for i, line in enumerate(text.split("\n")):
                if i:
                    yield _BREAK
                if not line.strip():
                    continue
                m = re.match(r"\s*[•\-\*]\s*(.*)$", line)
                yield {**block, "text": f"•	{m.group(1)}" if m else line}

    def build_lecture(self) -> None:
        current: Paragraph | None = None
        last_speaker_printed: object = object()  # sentinel: nothing printed yet
        prev_speaker: object = object()
        prev_end: int | None = None
        gap = self.opt.lecture_paragraph_gap_ms

        def flush() -> None:
            nonlocal current
            if current is not None and not current.is_empty:
                self.paragraphs.append(current)
            current = None

        for seg in self.doc.get("segments") or []:
            speaker_id = seg.get("speaker_id")
            start = seg.get("start_ms", 0)
            boundary = (
                speaker_id != prev_speaker
                or (gap is not None and prev_end is not None and start - prev_end > gap)
            )
            if boundary:
                flush()
            pending_ts: int | None = start if boundary or current is None else None

            for block in self.expand_lines(seg.get("blocks") or []):
                if block is _BREAK:
                    flush()
                    continue
                set_off = self.opt.template != "client" and (
                    block.get("type") == "quran"
                    or (block.get("type") == "hadith" and block.get("match") == "exact")
                )
                if set_off:
                    flush()
                    para = Paragraph("quran" if block["type"] == "quran" else "hadith")
                    para.items.extend(self.inline_items(block))
                    self.paragraphs.append(para)
                    continue
                if current is None:
                    current = Paragraph("body")
                    speaker = None
                    if self.opt.speakers_enabled and speaker_id != last_speaker_printed:
                        speaker = self.speaker_name(speaker_id)
                        last_speaker_printed = speaker_id
                    self.prefix(current, pending_ts, speaker)
                    pending_ts = None
                self.append_inline(current, self.inline_items(block))

            prev_speaker = speaker_id
            prev_end = seg.get("end_ms", start)
        flush()


def build_layout(document: Mapping, options: ExportOptions) -> Layout:
    b = _Builder(document, options)
    if options.template == "transcript":
        b.build_transcript()
    else:
        b.build_lecture()
    return Layout(title=b.plain(document.get("title")), paragraphs=b.paragraphs)
