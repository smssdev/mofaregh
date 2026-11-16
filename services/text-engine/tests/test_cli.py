"""CLI used by the API for Word export, and the scripture guard of the formulas."""

from __future__ import annotations

import io
import json
import subprocess
import sys
import zipfile
from pathlib import Path

from lxml import etree

from mofaregh_text.cli import document_from_paragraphs
from mofaregh_text.export import export_docx
from mofaregh_text.export.formulas import restore_formulas

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
SRC = Path(__file__).parents[1] / "src"


def paragraphs_of(data: bytes) -> list[str]:
    xml = etree.fromstring(zipfile.ZipFile(io.BytesIO(data)).read("word/document.xml"))
    return ["".join(p.itertext()) for p in xml.iter(f"{W}p")]


def test_document_from_paragraphs_exports_one_paragraph_each():
    doc = document_from_paragraphs("عنوان", ["الفقرة الأولى", "", "قال الله تبارك وتعالى", "• بند\nسطر ثان"])
    paras = paragraphs_of(export_docx(doc))
    assert paras[0] == "عنوان"
    assert paras[1] == "الفقرة الأولى"
    assert paras[2] == "قال الله تَبَارَكَ وَتَعَالَى"   # formulas vowelled on export
    assert paras[3].startswith("•") and "بند" in paras[3]
    assert paras[4] == "سطر ثان"
    assert len(paras) == 5


def test_a_single_line_bullet_gets_the_reference_tab():
    data = export_docx(document_from_paragraphs("", ["• بند وحيد", "كلام - فيه شرطة - في وسطه"]))
    xml = etree.fromstring(zipfile.ZipFile(io.BytesIO(data)).read("word/document.xml"))
    first, second = list(xml.iter(f"{W}p"))
    assert first.find(f".//{W}tab") is not None and "".join(first.itertext()) == "•بند وحيد"
    assert second.find(f".//{W}tab") is None


def test_formulas_leave_quoted_scripture_untouched():
    text = "قال صلى الله عليه وسلم: «من صلى الله عليه وسلم» و﴿تبارك وتعالى﴾"
    out = restore_formulas(text)
    assert out.startswith("قال صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ:")
    assert "«من صلى الله عليه وسلم»" in out and "﴿تبارك وتعالى﴾" in out


def test_cli_writes_docx_to_stdout():
    request = json.dumps({"title": "تجربة", "paragraphs": ["نص أول", "نص ثان"]}).encode("utf-8")
    run = subprocess.run(
        [sys.executable, "-m", "mofaregh_text.cli", "export-docx"],
        input=request, capture_output=True, env={"PYTHONPATH": str(SRC), "SYSTEMROOT": "C:\\Windows"},
        check=False,
    )
    assert run.returncode == 0, run.stderr.decode("utf-8", "replace")
    assert paragraphs_of(run.stdout) == ["تجربة", "نص أول", "نص ثان"]


def test_cli_rejects_bad_input():
    run = subprocess.run(
        [sys.executable, "-m", "mofaregh_text.cli", "export-docx"],
        input=b'{"paragraphs": [1]}', capture_output=True,
        env={"PYTHONPATH": str(SRC), "SYSTEMROOT": "C:\\Windows"}, check=False,
    )
    assert run.returncode == 2
