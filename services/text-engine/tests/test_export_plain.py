"""TXT / SRT export tests. Fixture Quran/hadith texts are SYNTHETIC (invented words)."""

from __future__ import annotations

import json
import re
from pathlib import Path

import pytest

from mofaregh_text.arabic.diacritics import strip_tashkeel
from mofaregh_text.export import ExportOptions, export_srt, export_txt
from mofaregh_text.export.plain import format_srt_time

FIXTURE = Path(__file__).parent / "fixtures" / "sample_document.json"
TIMING = re.compile(r"^\d{2}:\d{2}:\d{2},\d{3} --> \d{2}:\d{2}:\d{2},\d{3}$")


@pytest.fixture
def document() -> dict:
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def blocks(document, kind):
    return [b for s in document["segments"] for b in s["blocks"] if b["type"] == kind]


@pytest.mark.parametrize("ms,expected", [
    (0, "00:00:00,000"),
    (62345, "00:01:02,345"),
    (3723456, "01:02:03,456"),
    (-5, "00:00:00,000"),
])
def test_srt_time_format(ms, expected):
    assert format_srt_time(ms) == expected


def test_srt_structure(document):
    srt = export_srt(document)
    cues = srt.strip("\n").split("\n\n")
    assert len(cues) == len(document["segments"])
    for n, cue in enumerate(cues, 1):
        lines = cue.split("\n")
        assert lines[0] == str(n)
        assert TIMING.match(lines[1])
        assert lines[2]
    assert "00:01:02,345 --> 00:01:05,000" in srt
    assert "00:01:05,100 --> 01:02:03,456" in srt


def test_srt_locked_text_verbatim(document):
    srt = export_srt(document, tashkeel="strip")
    for q in blocks(document, "quran"):
        assert q["text"] in srt
    exact = [h for h in blocks(document, "hadith") if h["match"] == "exact"]
    for h in exact:
        assert h["text"] in srt
    for t in blocks(document, "text"):
        assert strip_tashkeel(t["text"]) in srt and t["text"] not in srt
    assert "[غير واضح]" in srt


def test_srt_rtl_mark(document):
    srt = export_srt(document, rtl_mark=True)
    text_lines = [c.split("\n")[2] for c in srt.strip("\n").split("\n\n")]
    assert all(line.startswith("‏") for line in text_lines)


def test_txt_lecture(document):
    txt = export_txt(document, ExportOptions(tashkeel="strip"))
    assert txt.startswith(strip_tashkeel(document["title"]) + "\n")
    for q in blocks(document, "quran"):
        assert "﴿" + q["text"] + "﴾" in txt
    assert "(١) أخرجه المصنف التجريبي" in txt
    assert "لم يُعثر على مصدره" in txt
    meaning = [h for h in blocks(document, "hadith") if h["match"] == "meaning"][0]
    assert meaning["source_text"] in txt


def test_txt_transcript_timestamps(document):
    opts = ExportOptions(template="transcript", include_timestamps=True,
                         timestamp_digits="western", hadith_takhrij="inline")
    txt = export_txt(document, opts)
    assert "[00:01:02] الشَّيْخُ:" in txt
    assert "(أخرجه المصنف التجريبي (١٢))" in txt
    no_ts = export_txt(document, ExportOptions(template="transcript"))
    assert "[00:" not in no_ts and "[٠٠:" not in no_ts
