"""Command line entry used by the API (no HTTP service needed on the server).

    python -m mofaregh_text.cli export-docx < request.json > out.docx

Request (UTF-8 JSON on stdin):

    {"title": "…", "paragraphs": ["first paragraph", "second\\nline", "• item"]}

Each string is one paragraph of the editor; a line break inside it starts a
new paragraph and a leading bullet becomes ``•<tab>text`` (client template
rules). The .docx is written to stdout as bytes.
"""

from __future__ import annotations

import json
import sys
from collections.abc import Sequence

from .export import export_docx

PARAGRAPH_GAP_MS = 60_000  # > lecture_paragraph_gap_ms, so every entry is its own paragraph


def document_from_paragraphs(title: str, paragraphs: Sequence[str]) -> dict:
    """The shared document model for plain editor paragraphs (one segment each)."""
    segments = []
    for text in paragraphs:
        if not text or not text.strip():
            continue
        start = len(segments) * PARAGRAPH_GAP_MS
        segments.append({
            "id": f"s{len(segments) + 1}",
            "start_ms": start,
            "end_ms": start + 1000,
            "speaker_id": "sp1",
            "blocks": [{"type": "text", "text": text}],
        })
    return {
        "version": 1,
        "title": title or "",
        "settings": {},
        "speakers": [{"id": "sp1", "name": ""}],
        "segments": segments,
    }


def main(argv: Sequence[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if args != ["export-docx"]:
        sys.stderr.write("usage: python -m mofaregh_text.cli export-docx < request.json > out.docx\n")
        return 2
    request = json.loads(sys.stdin.buffer.read().decode("utf-8"))
    paragraphs = request.get("paragraphs") or []
    if not isinstance(paragraphs, list) or not all(isinstance(p, str) for p in paragraphs):
        sys.stderr.write("paragraphs must be a list of strings\n")
        return 2
    data = export_docx(document_from_paragraphs(str(request.get("title") or ""), paragraphs))
    sys.stdout.buffer.write(data)
    sys.stdout.buffer.flush()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
