"""Client template writer: fills ``templates/client.docx``.

The template is the client's reference document with every paragraph and all
personal metadata removed (see scripts/build_client_template.py), so styles,
settings (compatibility mode 15, OpenType features), theme, font table and
page setup are *the reference's own*. Paragraphs are written with the same
markup the reference uses: no paragraph or character styles at all, only

    <w:p><w:pPr><w:rPr><w:rtl/></w:rPr></w:pPr>
         <w:r><w:rPr><w:rtl/></w:rPr><w:t>...</w:t></w:r></w:p>

Everything visible (Sakkal Majalla 18 pt, kashida justification, first-line
indent, spacing) therefore comes from the template's Normal style, exactly as
in the reference. The title is the same paragraph with ``jc=center``.
"""

from __future__ import annotations

import io
from importlib import resources

from docx import Document
from docx.oxml.ns import qn

from .docx_export import _Footnotes, _w
from .layout import Footnote, Layout, Paragraph
from .options import ExportOptions

TEMPLATE = "templates/client.docx"
_XML_SPACE = "{http://www.w3.org/XML/1998/namespace}space"
FOOTNOTE_SIZE_HALF_PT = 24  # 12 pt


def _rpr(*, bold: bool = False, superscript: bool = False, size: int | None = None):
    # schema order: b, bCs, sz, szCs, vertAlign, rtl
    rpr = _w("rPr")
    if bold:
        rpr.append(_w("b"))
        rpr.append(_w("bCs"))
    if size is not None:
        rpr.append(_w("sz", val=size))
        rpr.append(_w("szCs", val=size))
    if superscript:
        rpr.append(_w("vertAlign", val="superscript"))
    rpr.append(_w("rtl"))
    return rpr


def _run(text: str, **fmt):
    r = _w("r")
    r.append(_rpr(**fmt))
    for i, chunk in enumerate(text.split("\t")):
        if i:
            r.append(_w("tab"))
        if chunk:
            t = _w("t")
            t.text = chunk
            t.set(_XML_SPACE, "preserve")
            r.append(t)
    return r


def _ppr(*, center: bool = False, footnote: bool = False):
    # schema order: spacing, ind, jc, rPr
    ppr = _w("pPr")
    if footnote:
        ppr.append(_w("spacing", before=0, after=0))
        ppr.append(_w("ind", firstLine=0))
    if center:
        ppr.append(_w("jc", val="center"))
    mark = _w("rPr")
    mark.append(_w("rtl"))
    ppr.append(mark)
    return ppr


class _ClientFootnotes(_Footnotes):
    """Footnotes with direct formatting (the reference has none; its own
    'footnote text' style is red superscript, which we do not want)."""

    def add(self, spans) -> int:
        root = self._ensure_part()
        fid = self.next_id
        self.next_id += 1
        fn = _w("footnote", id=fid)
        p = _w("p")
        p.append(_ppr(footnote=True))
        ref = _w("r")
        ref.append(_rpr(superscript=True, size=FOOTNOTE_SIZE_HALF_PT))
        ref.append(_w("footnoteRef"))
        p.append(ref)
        p.append(_run(" ", size=FOOTNOTE_SIZE_HALF_PT))
        for r in self.render_spans(spans, in_footnote=True):
            p.append(r)
        fn.append(p)
        root.append(fn)
        return fid

    @staticmethod
    def reference_run(fid: int):
        r = _w("r")
        r.append(_rpr(superscript=True))
        r.append(_w("footnoteReference", id=fid))
        return r


class ClientWriter:
    def __init__(self, options: ExportOptions):
        self.o = options
        with resources.files(__package__).joinpath(TEMPLATE).open("rb") as fh:
            self.doc = Document(io.BytesIO(fh.read()))
        self.body = self.doc.element.body
        for p in list(self.body.iterchildren(qn("w:p"))):
            self.body.remove(p)
        self.footnotes = _ClientFootnotes(self.doc, self.render_spans)

    def render_spans(self, items, *, in_footnote: bool = False):
        size = FOOTNOTE_SIZE_HALF_PT if in_footnote else None
        runs = []
        for item in items:
            if isinstance(item, Footnote):
                runs.append(self.footnotes.reference_run(self.footnotes.add(item.spans)))
            elif item.text:
                runs.append(_run(item.text, bold=item.role == "speaker", size=size))
        return runs

    def add_paragraph(self, runs, *, center: bool = False) -> None:
        p = _w("p")
        p.append(_ppr(center=center))
        for r in runs:
            p.append(r)
        self.body.sectPr.addprevious(p)

    def write(self, layout: Layout) -> bytes:
        self.doc.core_properties.title = layout.title
        if layout.title:
            self.add_paragraph([_run(layout.title)], center=True)
        para: Paragraph
        for para in layout.paragraphs:
            self.add_paragraph(self.render_spans(para.items))
        buf = io.BytesIO()
        self.doc.save(buf)
        return buf.getvalue()
