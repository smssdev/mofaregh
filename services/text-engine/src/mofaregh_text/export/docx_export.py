"""Word (.docx) export of the Mofaregh document model.

    export_docx(document: dict, options: ExportOptions) -> bytes

python-docx is used for the package (zip, parts, relationships, content
types), sections and header/footer parts. Everything that python-docx has no
API for (bidi, complex-script sizes and fonts, footnotes) is written as raw
WordprocessingML, always in the child order required by the ECMA-376 schema
so that Word does not report the file as corrupt.

See README.md in this package for the design notes.
"""

from __future__ import annotations

import io
from collections.abc import Mapping, Sequence

from docx import Document
from docx.opc.constants import CONTENT_TYPE as CT
from docx.opc.constants import RELATIONSHIP_TYPE as RT
from docx.opc.packuri import PackURI
from docx.opc.part import XmlPart
from docx.oxml import parse_xml
from docx.oxml.ns import nsdecls, qn
from docx.oxml.parser import OxmlElement
from docx.shared import Cm, Mm
from lxml import etree

from .layout import Footnote, Layout, Paragraph, Span, build_layout
from .options import ExportOptions

__all__ = ["ExportOptions", "export_docx", "STYLE_IDS"]

LANG_BIDI = "ar-SA"
FOOTNOTES_PARTNAME = "/word/footnotes.xml"

# style id -> display name (the name is what users see in Word's style gallery)
STYLE_IDS: Mapping[str, str] = {
    "MofareghTitle": "Mofaregh Title",
    "MofareghBody": "Mofaregh Body",
    "MofareghQuran": "Mofaregh Quran",
    "MofareghQuranChar": "Mofaregh Quran Char",
    "MofareghHadith": "Mofaregh Hadith",
    "MofareghHadithChar": "Mofaregh Hadith Char",
    "MofareghTimestamp": "Mofaregh Timestamp",
    "MofareghSpeaker": "Mofaregh Speaker",
    "MofareghReference": "Mofaregh Reference",
    "MofareghTag": "Mofaregh Tag",
    "MofareghTakhrij": "Mofaregh Takhrij",
    "FootnoteText": "footnote text",          # Word built-in names (lower case)
    "FootnoteReference": "footnote reference",
}

# role of a Span -> character style when it sits inside a body paragraph
_BODY_RSTYLE = {
    "quran": "MofareghQuranChar",
    "hadith": "MofareghHadithChar",
    "reference": "MofareghReference",
    "timestamp": "MofareghTimestamp",
    "speaker": "MofareghSpeaker",
    "tag": "MofareghTag",
    "takhrij": "MofareghTakhrij",
    "source": "MofareghHadithChar",
}
_PARA_STYLE = {"body": "MofareghBody", "quran": "MofareghQuran", "hadith": "MofareghHadith"}

# Child order of w:rPr and w:pPr per ECMA-376 (subset we emit).
_RPR_ORDER = ["rStyle", "rFonts", "b", "bCs", "color", "sz", "szCs", "vertAlign", "rtl", "lang"]
_PPR_ORDER = ["pStyle", "keepNext", "keepLines", "bidi", "spacing", "ind", "jc", "rPr"]


# --------------------------------------------------------------------------- XML helpers


def _w(tag: str, **attrs: str | int) -> etree._Element:
    el = OxmlElement(f"w:{tag}")
    for k, v in attrs.items():
        el.set(qn(f"w:{k}"), str(v))
    return el


def _ordered(parent_tag: str, order: Sequence[str], children: Mapping[str, etree._Element]):
    parent = _w(parent_tag)
    for name in order:
        if name in children:
            parent.append(children[name])
    return parent


def _half_points(pt: float) -> int:
    return int(round(pt * 2))


def make_rpr(
    *,
    style: str | None = None,
    font: str | None = None,
    size_pt: float | None = None,
    bold: bool = False,
    color: str | None = None,
    superscript: bool = False,
    rtl: bool = True,
) -> etree._Element:
    """w:rPr in schema order. Fonts are set on ascii/hAnsi/eastAsia/cs and the
    size on both w:sz and w:szCs (Arabic is rendered from the cs slots)."""
    c: dict[str, etree._Element] = {}
    if style:
        c["rStyle"] = _w("rStyle", val=style)
    if font:
        c["rFonts"] = _w("rFonts", ascii=font, hAnsi=font, eastAsia=font, cs=font)
    if bold:
        c["b"], c["bCs"] = _w("b"), _w("bCs")
    if color:
        c["color"] = _w("color", val=color)
    if size_pt is not None:
        c["sz"] = _w("sz", val=_half_points(size_pt))
        c["szCs"] = _w("szCs", val=_half_points(size_pt))
    if superscript:
        c["vertAlign"] = _w("vertAlign", val="superscript")
    if rtl:
        c["rtl"] = _w("rtl")
        c["lang"] = _w("lang", bidi=LANG_BIDI)
    return _ordered("rPr", _RPR_ORDER, c)


def make_ppr(
    *,
    style: str | None = None,
    jc: str | None = None,
    before: int | None = None,
    after: int | None = None,
    line: int | None = None,
    ind_twips: int | None = None,
    keep_next: bool = False,
    keep_lines: bool = False,
) -> etree._Element:
    """w:pPr in schema order, always with w:bidi (right-to-left paragraph)."""
    c: dict[str, etree._Element] = {}
    if style:
        c["pStyle"] = _w("pStyle", val=style)
    if keep_next:
        c["keepNext"] = _w("keepNext")
    if keep_lines:
        c["keepLines"] = _w("keepLines")
    c["bidi"] = _w("bidi")
    if before is not None or after is not None or line is not None:
        sp = _w("spacing")
        if before is not None:
            sp.set(qn("w:before"), str(before))
        if after is not None:
            sp.set(qn("w:after"), str(after))
        if line is not None:
            sp.set(qn("w:line"), str(line))
            sp.set(qn("w:lineRule"), "auto")
        c["spacing"] = sp
    if ind_twips is not None:
        # symmetric indentation: identical whatever Word decides left/right means
        c["ind"] = _w("ind", left=ind_twips, right=ind_twips)
    if jc:
        c["jc"] = _w("jc", val=jc)
    return _ordered("pPr", _PPR_ORDER, c)


def make_run(text: str, style: str | None = None, rpr: etree._Element | None = None):
    r = _w("r")
    r.append(rpr if rpr is not None else make_rpr(style=style))
    t = _w("t")
    t.text = text
    t.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    r.append(t)
    return r


def _field_runs(instr: str, size_pt: float | None = None) -> list[etree._Element]:
    def run_with(child: etree._Element) -> etree._Element:
        r = _w("r")
        r.append(make_rpr(size_pt=size_pt))
        r.append(child)
        return r

    instr_el = _w("instrText")
    instr_el.text = f" {instr} "
    instr_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    result = _w("t")
    result.text = "1"
    return [
        run_with(_w("fldChar", fldCharType="begin")),
        run_with(instr_el),
        run_with(_w("fldChar", fldCharType="separate")),
        run_with(result),
        run_with(_w("fldChar", fldCharType="end")),
    ]


# --------------------------------------------------------------------------- styles


def _make_style(
    style_id: str,
    stype: str,
    *,
    based_on: str | None,
    next_style: str | None = None,
    link: str | None = None,
    ppr: etree._Element | None = None,
    rpr: etree._Element | None = None,
    ui_priority: int | None = None,
    unhide: bool = False,
) -> etree._Element:
    st = _w("style", type=stype, styleId=style_id)
    if style_id.startswith("Mofaregh"):
        st.set(qn("w:customStyle"), "1")
    # child order: name, basedOn, next, link, uiPriority, unhideWhenUsed, qFormat, pPr, rPr
    st.append(_w("name", val=STYLE_IDS[style_id]))
    if based_on:
        st.append(_w("basedOn", val=based_on))
    if next_style:
        st.append(_w("next", val=next_style))
    if link:
        st.append(_w("link", val=link))
    if ui_priority is not None:
        st.append(_w("uiPriority", val=ui_priority))
    if unhide:
        st.append(_w("unhideWhenUsed"))
    if style_id.startswith("Mofaregh"):
        st.append(_w("qFormat"))
    if ppr is not None:
        st.append(ppr)
    if rpr is not None:
        st.append(rpr)
    return st


def _install_styles(doc, o: ExportOptions) -> None:
    styles = doc.styles.element
    # docDefaults: explicit fonts (theme attributes would override explicit ones),
    # Arabic as the complex-script language, RTL paragraphs by default.
    defaults = styles.find(qn("w:docDefaults"))
    rpr_default = defaults.find(qn("w:rPrDefault"))
    for child in list(rpr_default):
        rpr_default.remove(child)
    rpr_default.append(make_rpr(font=o.body_font, size_pt=o.body_size_pt))
    ppr_default = defaults.find(qn("w:pPrDefault"))
    for child in list(ppr_default):
        ppr_default.remove(child)
    ppr_default.append(make_ppr(after=120, line=276))

    body, quran, hadith = o.body_font, o.quran_font, o.body_font
    small = max(1.0, o.body_size_pt - 2)
    hadith_color = "1F4E3D"
    defs = [
        _make_style("MofareghTitle", "paragraph", based_on="Normal", next_style="MofareghBody",
                    ppr=make_ppr(jc="center", before=0, after=360, keep_next=True),
                    rpr=make_rpr(font=body, size_pt=o.title_size_pt, bold=True)),
        _make_style("MofareghBody", "paragraph", based_on="Normal", next_style="MofareghBody",
                    ppr=make_ppr(jc="both", after=160, line=360),
                    rpr=make_rpr(font=body, size_pt=o.body_size_pt)),
        _make_style("MofareghQuran", "paragraph", based_on="MofareghBody",
                    next_style="MofareghBody", link="MofareghQuranChar",
                    ppr=make_ppr(jc="center", before=120, after=200, line=360, keep_lines=True),
                    rpr=make_rpr(font=quran, size_pt=o.quran_size_pt)),
        _make_style("MofareghQuranChar", "character", based_on="DefaultParagraphFont",
                    link="MofareghQuran",
                    rpr=make_rpr(font=quran, size_pt=o.quran_size_pt)),
        _make_style("MofareghHadith", "paragraph", based_on="MofareghBody",
                    next_style="MofareghBody", link="MofareghHadithChar",
                    ppr=make_ppr(jc="both", before=120, after=200, line=360, ind_twips=567),
                    rpr=make_rpr(font=hadith, size_pt=o.hadith_size_pt, bold=True,
                                 color=hadith_color)),
        _make_style("MofareghHadithChar", "character", based_on="DefaultParagraphFont",
                    link="MofareghHadith",
                    rpr=make_rpr(font=hadith, size_pt=o.hadith_size_pt, bold=True,
                                 color=hadith_color)),
        _make_style("MofareghTimestamp", "character", based_on="DefaultParagraphFont",
                    rpr=make_rpr(font=body, size_pt=small, color="7F7F7F")),
        _make_style("MofareghSpeaker", "character", based_on="DefaultParagraphFont",
                    rpr=make_rpr(font=body, bold=True, color="1F3864")),
        _make_style("MofareghReference", "character", based_on="DefaultParagraphFont",
                    rpr=make_rpr(font=body, size_pt=small, color="595959")),
        _make_style("MofareghTag", "character", based_on="DefaultParagraphFont",
                    rpr=make_rpr(font=body, color="7F7F7F")),
        _make_style("MofareghTakhrij", "character", based_on="DefaultParagraphFont",
                    rpr=make_rpr(font=body, size_pt=o.footnote_size_pt, color="595959")),
        _make_style("FootnoteText", "paragraph", based_on="Normal", ui_priority=99, unhide=True,
                    ppr=make_ppr(after=0, line=240),
                    rpr=make_rpr(font=body, size_pt=o.footnote_size_pt)),
        _make_style("FootnoteReference", "character", based_on="DefaultParagraphFont",
                    ui_priority=99, unhide=True,
                    rpr=make_rpr(superscript=True)),
    ]
    for st in defs:
        sid = st.get(qn("w:styleId"))
        for old in styles.findall(qn("w:style")):
            if old.get(qn("w:styleId")) == sid:
                styles.remove(old)
        styles.append(st)


def _install_fonts(doc, o: ExportOptions) -> None:
    """Declare our fonts in fontTable.xml with the Arabic charset and the first
    fallback as w:altName (Word has no real fallback list)."""
    rel = next((r for r in doc.part.rels.values() if r.reltype == RT.FONT_TABLE), None)
    if rel is None:
        return
    part = rel.target_part
    root = etree.fromstring(part.blob)
    w = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    for name, fallbacks, family in (
        (o.quran_font, o.quran_font_fallbacks, "auto"),
        (o.body_font, o.body_font_fallbacks, "roman"),
    ):
        for old in root.findall(f"{{{w}}}font"):
            if old.get(f"{{{w}}}name") == name:
                root.remove(old)
        font = etree.SubElement(root, f"{{{w}}}font")
        font.set(f"{{{w}}}name", name)
        if fallbacks:
            etree.SubElement(font, f"{{{w}}}altName").set(f"{{{w}}}val", fallbacks[0])
        etree.SubElement(font, f"{{{w}}}charset").set(f"{{{w}}}val", "B2")  # ARABIC_CHARSET
        etree.SubElement(font, f"{{{w}}}family").set(f"{{{w}}}val", family)
        etree.SubElement(font, f"{{{w}}}pitch").set(f"{{{w}}}val", "variable")
    part._blob = etree.tostring(root, xml_declaration=True, encoding="UTF-8", standalone=True)


def _drop_styles_with_effects(doc) -> None:
    # Word 2010-only duplicate of styles.xml from python-docx's template; if kept,
    # Word 2010 could show stale styles. Our styles live in styles.xml only.
    for rid, rel in list(doc.part.rels.items()):
        if rel.reltype.endswith("/stylesWithEffects"):
            doc.part.drop_rel(rid)


def _configure_settings(doc) -> None:
    settings = doc.settings.element
    # themeFontLang: complex-script theme language = Arabic
    tfl = settings.find(qn("w:themeFontLang"))
    if tfl is not None:
        tfl.set(qn("w:bidi"), LANG_BIDI)


# --------------------------------------------------------------------------- footnotes


class _Footnotes:
    """Owns /word/footnotes.xml. Created lazily on the first footnote.

    Word requires the two special footnotes (separator id -1 and
    continuationSeparator id 0) to exist in the part and to be listed in
    settings.xml w:footnotePr; ordinary footnotes are numbered from 1.
    """

    def __init__(self, doc, render_spans):
        self.doc = doc
        self.render_spans = render_spans
        self.root: etree._Element | None = None
        self.next_id = 1

    def _ensure_part(self) -> etree._Element:
        if self.root is not None:
            return self.root
        root = parse_xml(
            f"<w:footnotes {nsdecls('w', 'r')}>"
            '<w:footnote w:type="separator" w:id="-1"><w:p><w:pPr><w:bidi/>'
            '<w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
            "<w:r><w:separator/></w:r></w:p></w:footnote>"
            '<w:footnote w:type="continuationSeparator" w:id="0"><w:p><w:pPr><w:bidi/>'
            '<w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
            "<w:r><w:continuationSeparator/></w:r></w:p></w:footnote>"
            "</w:footnotes>"
        )
        part = XmlPart(PackURI(FOOTNOTES_PARTNAME), CT.WML_FOOTNOTES, root, self.doc.part.package)
        # relate_to registers the relationship; the [Content_Types].xml Override is
        # written by python-docx from part.content_type when the package is saved.
        self.doc.part.relate_to(part, RT.FOOTNOTES)
        self._add_footnote_pr()
        self.root = root
        return root

    def _add_footnote_pr(self) -> None:
        settings = self.doc.settings.element
        if settings.find(qn("w:footnotePr")) is not None:
            return
        pr = _w("footnotePr")
        pr.append(_w("footnote", id=-1))
        pr.append(_w("footnote", id=0))
        # schema order: ... footnotePr, endnotePr, compat, docVars, rsids, m:mathPr ...
        for successor in ("w:endnotePr", "w:compat", "w:docVars", "w:rsids", "m:mathPr",
                          "w:attachedSchema", "w:themeFontLang", "w:clrSchemeMapping"):
            anchor = settings.find(qn(successor))
            if anchor is not None:
                anchor.addprevious(pr)
                return
        settings.append(pr)

    def add(self, spans: list[Span]) -> int:
        root = self._ensure_part()
        fid = self.next_id
        self.next_id += 1
        fn = _w("footnote", id=fid)
        p = _w("p")
        p.append(make_ppr(style="FootnoteText"))
        ref = _w("r")
        ref.append(make_rpr(style="FootnoteReference"))
        ref.append(_w("footnoteRef"))
        p.append(ref)
        p.append(make_run(" "))
        for r in self.render_spans(spans, in_footnote=True):
            p.append(r)
        fn.append(p)
        root.append(fn)
        return fid

    @staticmethod
    def reference_run(fid: int) -> etree._Element:
        r = _w("r")
        r.append(make_rpr(style="FootnoteReference"))
        r.append(_w("footnoteReference", id=fid))
        return r


# --------------------------------------------------------------------------- writer


class _Writer:
    def __init__(self, options: ExportOptions):
        self.o = options
        self.doc = Document()
        self.footnotes = _Footnotes(self.doc, self.render_spans)
        self.body = self.doc.element.body
        for p in list(self.body.iterchildren(qn("w:p"))):  # template's empty paragraph(s)
            self.body.remove(p)

    def setup(self, title: str) -> None:
        _drop_styles_with_effects(self.doc)
        _install_styles(self.doc, self.o)
        _install_fonts(self.doc, self.o)
        _configure_settings(self.doc)
        self._setup_section(title)
        cp = self.doc.core_properties
        cp.title = title
        cp.language = LANG_BIDI

    def _setup_section(self, title: str) -> None:
        sec = self.doc.sections[0]
        sec.page_width, sec.page_height = Mm(210), Mm(297)
        m = Cm(self.o.margins_cm)
        sec.top_margin = sec.bottom_margin = sec.left_margin = sec.right_margin = m
        sec.gutter = 0
        sec.header_distance = sec.footer_distance = Cm(1.25)

        hf_size = self.o.header_footer_size_pt
        header = sec.header
        header.is_linked_to_previous = False
        hp = header.paragraphs[0]._p
        for child in list(hp):
            hp.remove(child)
        hp.append(make_ppr(style="Header", jc="center"))
        hp.append(make_run(title, rpr=make_rpr(size_pt=hf_size, color="595959")))

        footer = sec.footer
        footer.is_linked_to_previous = False
        fp = footer.paragraphs[0]._p
        for child in list(fp):
            fp.remove(child)
        fp.append(make_ppr(style="Footer", jc="center"))
        for run in _field_runs("PAGE", hf_size):
            fp.append(run)

        sect = sec._sectPr
        # schema order: ... textDirection, bidi, rtlGutter, docGrid, printerSettings
        anchor = sect.find(qn("w:docGrid"))
        for tag in ("bidi", "rtlGutter"):
            if sect.find(qn(f"w:{tag}")) is None:
                el = _w(tag)
                if anchor is not None:
                    anchor.addprevious(el)
                else:
                    sect.append(el)

    def render_spans(self, items, *, para_kind: str = "body", in_footnote: bool = False):
        runs = []
        for item in items:
            if isinstance(item, Footnote):
                runs.append(_Footnotes.reference_run(self.footnotes.add(item.spans)))
                continue
            if not item.text:
                continue
            if in_footnote:
                style = None if item.role in ("text", "source") else _BODY_RSTYLE.get(item.role)
            elif para_kind == "quran" and item.role == "quran":
                style = None  # paragraph style already carries the Quran font
            elif para_kind == "hadith" and item.role == "hadith":
                style = None
            else:
                style = _BODY_RSTYLE.get(item.role)
            runs.append(make_run(item.text, style))
        return runs

    def add_paragraph(self, style: str, runs) -> None:
        p = _w("p")
        p.append(make_ppr(style=style))
        for r in runs:
            p.append(r)
        self.body.sectPr.addprevious(p)

    def write(self, layout: Layout) -> bytes:
        self.setup(layout.title)
        if layout.title:
            self.add_paragraph("MofareghTitle", [make_run(layout.title)])
        for para in layout.paragraphs:
            self.write_paragraph(para)
        buf = io.BytesIO()
        self.doc.save(buf)
        return buf.getvalue()

    def write_paragraph(self, para: Paragraph) -> None:
        runs = self.render_spans(para.items, para_kind=para.kind)
        self.add_paragraph(_PARA_STYLE[para.kind], runs)


def export_docx(document: dict, options: ExportOptions | None = None) -> bytes:
    """Render ``document`` (shared/schemas/document.schema.json) to .docx bytes."""
    options = options or ExportOptions()
    layout = build_layout(document, options)
    return _Writer(options).write(layout)
