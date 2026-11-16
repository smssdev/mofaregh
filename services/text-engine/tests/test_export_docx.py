"""Word export tests. Fixture Quran/hadith texts are SYNTHETIC (invented words)."""

from __future__ import annotations

import copy
import io
import json
import shutil
import subprocess
import zipfile
from pathlib import Path

import docx
import pytest
from lxml import etree

from mofaregh_text.arabic.diacritics import strip_tashkeel
from mofaregh_text.export import ExportOptions, export_docx
from mofaregh_text.export.docx_export import STYLE_IDS

FIXTURE = Path(__file__).parent / "fixtures" / "sample_document.json"
W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NS = {"w": W}
FOOTNOTES_CT = "application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"
FOOTNOTES_RT = "http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes"

RPR_ORDER = ["rStyle", "rFonts", "b", "bCs", "i", "iCs", "color", "sz", "szCs", "vertAlign",
             "rtl", "cs", "lang"]
PPR_ORDER = ["pStyle", "keepNext", "keepLines", "bidi", "spacing", "ind", "jc", "rPr"]


def w(tag: str) -> str:
    return f"{{{W}}}{tag}"


@pytest.fixture
def document() -> dict:
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def blocks(document, kind, **match):
    for seg in document["segments"]:
        for b in seg["blocks"]:
            if b["type"] == kind and all(b.get(k) == v for k, v in match.items()):
                yield b


class Docx:
    def __init__(self, data: bytes):
        self.data = data
        self.zip = zipfile.ZipFile(io.BytesIO(data))

    def raw(self, name: str) -> bytes:
        return self.zip.read(name)

    def xml(self, name: str):
        return etree.fromstring(self.raw(name))

    def has(self, name: str) -> bool:
        return name in self.zip.namelist()

    def text(self, name: str = "word/document.xml") -> str:
        return "".join(self.xml(name).itertext())


def render(document, **kw) -> Docx:
    return Docx(export_docx(document, ExportOptions(**kw)))


# --------------------------------------------------------------------------- package validity


@pytest.mark.parametrize("template", ["lecture", "transcript"])
@pytest.mark.parametrize("takhrij", ["footnote", "inline"])
def test_valid_docx_reopens(document, template, takhrij):
    out = render(document, template=template, hadith_takhrij=takhrij, include_timestamps=True)
    assert out.data[:2] == b"PK"
    assert out.zip.testzip() is None
    for name in out.zip.namelist():
        if name.endswith((".xml", ".rels")):
            etree.fromstring(out.raw(name))  # every part is well-formed XML
    reopened = docx.Document(io.BytesIO(out.data))
    assert reopened.paragraphs[0].text == document["title"]
    assert reopened.paragraphs[0].style.name == "Mofaregh Title"
    # round trip through python-docx keeps the footnotes part
    buf = io.BytesIO()
    reopened.save(buf)
    assert Docx(buf.getvalue()).has("word/footnotes.xml")


def test_every_paragraph_and_run_is_rtl(document):
    out = render(document, template="transcript", include_timestamps=True)
    parts = ["word/document.xml", "word/footnotes.xml", "word/header1.xml", "word/footer1.xml"]
    n_paras = n_runs = 0
    for part in parts:
        root = out.xml(part)
        for p in root.iter(w("p")):
            n_paras += 1
            ppr = p.find(w("pPr"))
            assert ppr is not None and ppr.find(w("bidi")) is not None, part
            assert [c.tag for c in ppr] == sorted((c.tag for c in ppr),
                                                  key=lambda t: PPR_ORDER.index(t.split("}")[1]))
            jc = ppr.find(w("jc"))
            assert jc is None or jc.get(w("val")) in ("both", "center")
        for r in root.iter(w("r")):
            if r.find(w("separator")) is not None or r.find(w("continuationSeparator")) is not None:
                continue
            n_runs += 1
            rpr = r.find(w("rPr"))
            assert rpr is not None and rpr.find(w("rtl")) is not None, part
            assert rpr.find(w("lang")).get(w("bidi")) == "ar-SA"
            tags = [c.tag.split("}")[1] for c in rpr]
            assert tags == sorted(tags, key=RPR_ORDER.index)
    assert n_paras > 8 and n_runs > 30


def test_section_settings(document):
    out = render(document)
    sect = out.xml("word/document.xml").find(".//w:body/w:sectPr", NS)
    pgsz = sect.find(w("pgSz"))
    assert (pgsz.get(w("w")), pgsz.get(w("h"))) == ("11906", "16838")  # A4
    mar = sect.find(w("pgMar"))
    for side in ("top", "bottom", "left", "right"):
        assert mar.get(w(side)) == "1417"  # 2.5 cm
    tags = [c.tag.split("}")[1] for c in sect]
    assert "bidi" in tags and "rtlGutter" in tags
    assert tags.index("bidi") < tags.index("rtlGutter") < tags.index("docGrid")
    assert document["title"] in out.text("word/header1.xml")
    instr = "".join(out.xml("word/footer1.xml").xpath("//w:instrText/text()", namespaces=NS))
    assert instr.strip() == "PAGE"


# --------------------------------------------------------------------------- styles and fonts


def style(out: Docx, style_id: str):
    return out.xml("word/styles.xml").find(f"w:style[@w:styleId='{style_id}']", NS)


def test_named_styles_fonts_and_cs_sizes(document):
    out = render(document, quran_font="Test Quran Font", body_font="Test Body Font",
                 body_size_pt=14, quran_size_pt=16)
    for sid, name in STYLE_IDS.items():
        st = style(out, sid)
        assert st is not None, sid
        assert st.find(w("name")).get(w("val")) == name
    for name in ("Mofaregh Body", "Mofaregh Quran", "Mofaregh Hadith",
                 "Mofaregh Timestamp", "Mofaregh Speaker"):
        assert name in STYLE_IDS.values()

    for sid, font, half_points in [("MofareghQuran", "Test Quran Font", "32"),
                                   ("MofareghQuranChar", "Test Quran Font", "32"),
                                   ("MofareghBody", "Test Body Font", "28"),
                                   ("MofareghHadith", "Test Body Font", "28")]:
        rpr = style(out, sid).find(w("rPr"))
        fonts = rpr.find(w("rFonts"))
        for slot in ("ascii", "hAnsi", "cs"):
            assert fonts.get(w(slot)) == font, (sid, slot)
        assert rpr.find(w("sz")).get(w("val")) == half_points
        assert rpr.find(w("szCs")).get(w("val")) == half_points

    assert style(out, "MofareghBody").find("w:pPr/w:jc", NS).get(w("val")) == "both"
    assert style(out, "MofareghBody").find("w:pPr/w:bidi", NS) is not None

    # docDefaults must not carry theme font attributes (they would override ours)
    rfonts = out.xml("word/styles.xml").find(".//w:docDefaults//w:rFonts", NS)
    assert rfonts.get(w("cs")) == "Test Body Font"
    assert not any(k.endswith("Theme") or k.endswith("theme") for k in rfonts.attrib)

    font_names = out.xml("word/fontTable.xml").xpath("//w:font/@w:name", namespaces=NS)
    assert "Test Quran Font" in font_names and "Test Body Font" in font_names


def test_default_fonts(document):
    out = render(document)
    rpr = style(out, "MofareghQuran").find(w("rPr"))
    assert rpr.find(w("rFonts")).get(w("cs")) == "KFGQPC Uthmanic Script HAFS"
    body = style(out, "MofareghBody").find(w("rPr"))
    assert body.find(w("rFonts")).get(w("cs")) == "Traditional Arabic"


def test_paragraph_styles_used(document):
    out = render(document)
    used = out.xml("word/document.xml").xpath("//w:pStyle/@w:val", namespaces=NS)
    assert {"MofareghTitle", "MofareghBody", "MofareghQuran", "MofareghHadith"} <= set(used)


# --------------------------------------------------------------------------- locked text and tashkeel


@pytest.mark.parametrize("template", ["lecture", "transcript"])
def test_locked_text_byte_identical_when_stripping(document, template):
    out = render(document, template=template, tashkeel="strip")
    doc_xml = out.raw("word/document.xml")
    for q in blocks(document, "quran"):
        assert ("﴿" + q["text"] + "﴾").encode("utf-8") in doc_xml
    for h in blocks(document, "hadith", match="exact"):
        assert ("«" + h["text"] + "»").encode("utf-8") in doc_xml
    for h in blocks(document, "hadith", match="meaning"):
        assert h["source_text"].encode("utf-8") in out.raw("word/footnotes.xml")

    for t in blocks(document, "text"):
        assert t["text"] != strip_tashkeel(t["text"])  # fixture really is vowelled
        assert t["text"].encode("utf-8") not in doc_xml
        assert strip_tashkeel(t["text"]).encode("utf-8") in doc_xml
    for h in blocks(document, "hadith", match="meaning"):  # speaker's own words: ordinary
        assert strip_tashkeel(h["text"]).encode("utf-8") in doc_xml


def test_as_is_keeps_ordinary_tashkeel(document):
    doc_xml = render(document, tashkeel="as_is").raw("word/document.xml")
    for t in blocks(document, "text"):
        assert t["text"].encode("utf-8") in doc_xml


def test_locked_text_is_one_run(document):
    out = render(document, template="transcript")
    texts = out.xml("word/document.xml").xpath("//w:t/text()", namespaces=NS)
    for q in blocks(document, "quran"):
        assert any(q["text"] in t for t in texts)


# --------------------------------------------------------------------------- footnotes


def footnote_ids(out: Docx) -> list[int]:
    return [int(i) for i in out.xml("word/footnotes.xml").xpath("/w:footnotes/w:footnote/@w:id",
                                                                 namespaces=NS)]


def test_footnotes_part_wiring(document):
    out = render(document, hadith_takhrij="footnote")
    assert out.has("word/footnotes.xml")

    ct = out.xml("[Content_Types].xml")
    overrides = {o.get("PartName"): o.get("ContentType") for o in ct}
    assert overrides["/word/footnotes.xml"] == FOOTNOTES_CT

    rels = out.xml("word/_rels/document.xml.rels")
    targets = [r.get("Target") for r in rels if r.get("Type") == FOOTNOTES_RT]
    assert targets == ["footnotes.xml"]

    root = out.xml("word/footnotes.xml")
    special = {fn.get(w("id")): fn.get(w("type")) for fn in root if fn.get(w("type"))}
    assert special == {"-1": "separator", "0": "continuationSeparator"}
    assert root.find("w:footnote[@w:id='-1']//w:separator", NS) is not None
    assert root.find("w:footnote[@w:id='0']//w:continuationSeparator", NS) is not None

    # exact (takhrij) + meaning + not_found
    normal = [i for i in footnote_ids(out) if i > 0]
    assert normal == [1, 2, 3]
    for fid in normal:
        assert root.find(f"w:footnote[@w:id='{fid}']//w:footnoteRef", NS) is not None
    refs = out.xml("word/document.xml").xpath("//w:footnoteReference/@w:id", namespaces=NS)
    assert [int(r) for r in refs] == normal

    settings = out.xml("word/settings.xml")
    assert settings.xpath("w:footnotePr/w:footnote/@w:id", namespaces=NS) == ["-1", "0"]
    order = [c.tag.split("}")[1] for c in settings]
    assert order.index("footnotePr") < order.index("compat")

    notes = out.text("word/footnotes.xml")
    assert "رواية بالمعنى، ولفظه في المصدر:" in notes
    assert "لم يُعثر على مصدره" in notes
    exact = next(blocks(document, "hadith", match="exact"))
    assert exact["takhrij"] in notes


def test_inline_takhrij(document):
    out = render(document, hadith_takhrij="inline")
    exact = next(blocks(document, "hadith", match="exact"))
    assert f"({exact['takhrij']})" in out.text()
    assert exact["takhrij"] not in out.text("word/footnotes.xml")
    assert [i for i in footnote_ids(out) if i > 0] == [1, 2]  # meaning + not_found remain


def test_no_footnotes_part_when_not_needed(document):
    doc = copy.deepcopy(document)
    for seg in doc["segments"]:
        seg["blocks"] = [b for b in seg["blocks"] if b["type"] != "hadith"]
    out = render(doc)
    assert not out.has("word/footnotes.xml")
    assert b"footnotes" not in out.raw("[Content_Types].xml")
    assert b"footnotePr" not in out.raw("word/settings.xml")
    docx.Document(io.BytesIO(out.data))


# --------------------------------------------------------------------------- content


def test_timestamps_toggle_and_digits(document):
    on = render(document, template="transcript", include_timestamps=True).text()
    off = render(document, template="transcript", include_timestamps=False).text()
    west = render(document, template="transcript", include_timestamps=True,
                  timestamp_digits="western").text()
    assert "[٠٠:٠١:٠٢]" in on and "[٠١:٠٢:٠٣]" not in on
    assert "[٠٠:٠١:٠٢]" not in off and "[" + "00:01:02]" not in off
    assert "[00:01:02]" in west
    lecture = render(document, template="lecture", include_timestamps=True).text()
    assert "[٠٠:٠٠:٠٠]" in lecture


def test_templates(document):
    lecture = docx.Document(io.BytesIO(export_docx(document, ExportOptions(template="lecture"))))
    transcript = docx.Document(io.BytesIO(export_docx(
        document, ExportOptions(template="transcript"))))
    # transcript: title + one paragraph per segment
    assert len(transcript.paragraphs) == 1 + len(document["segments"])
    assert all(p.style.name == "Mofaregh Body" for p in transcript.paragraphs[1:])
    # lecture: Quran and exact hadith set off as their own paragraphs
    styles = [p.style.name for p in lecture.paragraphs]
    assert styles.count("Mofaregh Quran") == 2
    assert styles.count("Mofaregh Hadith") == 1
    # speaker names: transcript default on, lecture default off (opt-in)
    t_text = "\n".join(p.text for p in transcript.paragraphs)
    l_text = "\n".join(p.text for p in lecture.paragraphs)
    assert "الشَّيْخُ:" in t_text and "سائل:" in t_text
    assert "الشَّيْخُ:" not in l_text
    with_speakers = docx.Document(io.BytesIO(export_docx(
        document, ExportOptions(template="lecture", include_speakers=True))))
    assert "الشَّيْخُ:" in "\n".join(p.text for p in with_speakers.paragraphs)


def test_quran_reference(document):
    text = render(document, surah_names={2: "اسم تجريبي"}).text()
    assert "[اسم تجريبي: ٥]" in text
    assert "[سورة ٣: ٧-٩]" in text  # fallback name, Arabic-Indic range

    doc = copy.deepcopy(document)
    doc["settings"]["quran_reference_format"] = "({surah}:{ayah_range})"
    assert "(٣:٧-٩)" in render(doc).text()
    doc["settings"].pop("quran_reference_format")
    assert "[سورة ٢: ٥]" in render(doc).text()


def test_tags_and_hadith_quotes(document):
    text = render(document, template="transcript").text()
    for label in ("[غير واضح]", "[ضحك]", "[صمت]", "[تصفيق]"):
        assert label in text
    exact = next(blocks(document, "hadith", match="exact"))
    assert "«" + exact["text"] + "»" in text
    meaning = next(blocks(document, "hadith", match="meaning"))
    assert "«" + meaning["text"] not in text  # narrated by meaning: not quoted


def test_option_validation():
    with pytest.raises(ValueError):
        ExportOptions(template="book")
    with pytest.raises(ValueError):
        ExportOptions(tashkeel="partial")
    with pytest.raises(ValueError):
        ExportOptions(hadith_takhrij="endnote")


# --------------------------------------------------------------------------- LibreOffice


@pytest.mark.skipif(shutil.which("soffice") is None, reason="LibreOffice not installed")
def test_libreoffice_converts_to_pdf(document, tmp_path):
    src = tmp_path / "out.docx"
    src.write_bytes(export_docx(document, ExportOptions(include_timestamps=True)))
    result = subprocess.run(
        ["soffice", f"-env:UserInstallation=file://{tmp_path}/lo-profile", "--headless",
         "--convert-to", "pdf", "--outdir", str(tmp_path), str(src)],
        capture_output=True, timeout=180,
    )
    pdf = tmp_path / "out.pdf"
    assert result.returncode == 0, result.stderr
    assert pdf.exists() and pdf.read_bytes().startswith(b"%PDF")


# --------------------------------------------------------------------------- client template


def test_client_template_matches_reference_layout(document):
    out = render_client(document)
    doc_xml = out.xml("word/document.xml")
    sect = doc_xml.find(".//w:sectPr", NS)
    mar = sect.find("w:pgMar", NS)
    assert (mar.get(w("top")), mar.get(w("left")), mar.get(w("right"))) == ("1440", "1800", "1800")
    assert sect.find("w:headerReference", NS) is None and sect.find("w:footerReference", NS) is None
    assert sect.find("w:bidi", NS) is not None
    styles = out.xml("word/styles.xml")
    body = styles.xpath("//w:style[@w:styleId='MofareghBody']", namespaces=NS)[0]
    assert body.find("w:pPr/w:jc", NS).get(w("val")) == "lowKashida"
    assert body.find("w:pPr/w:ind", NS).get(w("firstLine")) == "454"
    assert body.find("w:pPr/w:spacing", NS).get(w("before")) == "120"
    assert body.find("w:rPr/w:sz", NS).get(w("val")) == "36"  # 18 pt
    assert body.find("w:rPr/w:rFonts", NS).get(w("cs")) == "Sakkal Majalla"
    # Quran and exact hadith flow inside body paragraphs: no set-off paragraph styles used
    used = {s.get(w("val")) for s in doc_xml.iter(w("pStyle"))}
    assert used <= {"MofareghTitle", "MofareghBody"}
    text = out.text()
    for q in blocks(document, "quran"):
        assert "﴿" + q["text"] + "﴾" in text


def render_client(document, **kw) -> Docx:
    return Docx(export_docx(document, ExportOptions.client(**kw)))


def test_client_strips_ordinary_tashkeel_but_keeps_formulas_and_quran(document):
    document = copy.deepcopy(document)
    document["segments"][0]["blocks"][0] = {
        "type": "text", "text": "قَالَ الله تبارك وتعالى وصلى الله عليه وسلم"}
    text = render_client(document).text()
    assert "قال الله" in text and "قَالَ الله" not in text
    assert "تَبَارَكَ وَتَعَالَى" in text
    assert "صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ" in text
    for q in blocks(document, "quran"):
        assert q["text"] in text  # locked text untouched
