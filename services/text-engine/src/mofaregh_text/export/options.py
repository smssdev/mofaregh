"""Export options shared by the Word, TXT and SRT exporters."""

from __future__ import annotations

from collections.abc import Mapping
from dataclasses import dataclass
from typing import Literal

Template = Literal["lecture", "transcript"]
Tashkeel = Literal["as_is", "strip"]
Takhrij = Literal["footnote", "inline"]
Digits = Literal["arabic_indic", "western"]

DEFAULT_QURAN_FONT = "KFGQPC Uthmanic Script HAFS"
# Word stores one font name per slot and has no CSS-like fallback list. These
# chains are written to fontTable.xml (w:altName = first fallback) and are
# documented for users who do not have the primary font installed.
DEFAULT_QURAN_FONT_FALLBACKS: tuple[str, ...] = (
    "Amiri Quran",
    "Scheherazade New",
    "Traditional Arabic",
)
DEFAULT_BODY_FONT = "Traditional Arabic"
DEFAULT_BODY_FONT_FALLBACKS: tuple[str, ...] = ("Sakkal Majalla", "Arial")

_TEMPLATES = ("lecture", "transcript")
_TASHKEEL = ("as_is", "strip")
_TAKHRIJ = ("footnote", "inline")
_DIGITS = ("arabic_indic", "western")


@dataclass(frozen=True)
class ExportOptions:
    """How a document is rendered.

    template           "lecture": title + flowing paragraphs (a new paragraph on
                       speaker change, on a pause longer than
                       ``lecture_paragraph_gap_ms``, and around Quran / exact
                       hadith which are set off as their own paragraphs).
                       "transcript": one paragraph per segment, prefixed by
                       timestamp and speaker; Quran/hadith stay inline.
    include_timestamps Prefix paragraphs (lecture) / segments (transcript) with
                       ``[hh:mm:ss]``.
    timestamp_digits   "arabic_indic" (٠١:٠٢:٠٣) or "western" (01:02:03).
    include_speakers   None = template default (lecture: False, transcript: True).
    tashkeel           "as_is" or "strip". "strip" touches ordinary text only;
                       Quran blocks, exact-hadith blocks and hadith source texts
                       quoted in footnotes are never modified.
    hadith_takhrij     "footnote" (real Word footnotes) or "inline" (in
                       parentheses after the hadith). Notes for meaning /
                       not_found hadith are always footnotes.
    surah_names        Optional {surah number: name}; falls back to "سورة {n}".
    """

    template: Template = "lecture"
    include_timestamps: bool = False
    timestamp_digits: Digits = "arabic_indic"
    include_speakers: bool | None = None
    tashkeel: Tashkeel = "as_is"
    hadith_takhrij: Takhrij = "footnote"
    surah_names: Mapping[int, str] | None = None
    reference_digits: Digits = "arabic_indic"
    lecture_paragraph_gap_ms: int | None = 3000

    quran_font: str = DEFAULT_QURAN_FONT
    quran_font_fallbacks: tuple[str, ...] = DEFAULT_QURAN_FONT_FALLBACKS
    body_font: str = DEFAULT_BODY_FONT
    body_font_fallbacks: tuple[str, ...] = DEFAULT_BODY_FONT_FALLBACKS
    body_size_pt: float = 14
    quran_size_pt: float = 16
    hadith_size_pt: float = 14
    title_size_pt: float = 20
    footnote_size_pt: float = 11
    header_footer_size_pt: float = 10
    margins_cm: float = 2.5

    def __post_init__(self) -> None:
        _check("template", self.template, _TEMPLATES)
        _check("tashkeel", self.tashkeel, _TASHKEEL)
        _check("hadith_takhrij", self.hadith_takhrij, _TAKHRIJ)
        _check("timestamp_digits", self.timestamp_digits, _DIGITS)
        _check("reference_digits", self.reference_digits, _DIGITS)
        for name in ("body_size_pt", "quran_size_pt", "hadith_size_pt", "title_size_pt",
                     "footnote_size_pt", "header_footer_size_pt"):
            if not 1 <= getattr(self, name) <= 1638:  # Word's max half-point size
                raise ValueError(f"{name} out of range: {getattr(self, name)!r}")

    @property
    def speakers_enabled(self) -> bool:
        if self.include_speakers is None:
            return self.template == "transcript"
        return self.include_speakers


def _check(name: str, value: object, allowed: tuple[str, ...]) -> None:
    if value not in allowed:
        raise ValueError(f"{name} must be one of {allowed}, got {value!r}")
