"""One-off: derive the formatting-only client template from a reference .docx.

    python scripts/build_client_template.py <reference.docx>

Keeps styles, settings, theme, font table and numbering byte-for-byte; removes
every body paragraph (only the section properties stay) and all personal
metadata (author, editor, revision ids, statistics). The reference itself is
private client data and is never committed; only this text-free skeleton is.
"""

from __future__ import annotations

import re
import sys
import zipfile
from pathlib import Path

OUT = Path(__file__).resolve().parents[1] / "src/mofaregh_text/export/templates/client.docx"

CORE = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
    '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
    'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
    'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    "<dc:title></dc:title><dc:creator>Mofaregh</dc:creator></cp:coreProperties>"
)
APP = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
    '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
    "<Application>Mofaregh</Application></Properties>"
)


def main(src: str) -> None:
    zin = zipfile.ZipFile(src)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            if item.filename == "word/document.xml":
                xml = data.decode("utf-8")
                head = xml[: xml.index("<w:body>") + len("<w:body>")]
                sect = re.search(r"<w:sectPr[ >].*?</w:sectPr>", xml[xml.rindex("<w:sectPr"):], re.S).group(0)
                sect = re.sub(r' w:rsid\w*="[^"]*"', "", sect)
                data = (head + sect + "</w:body></w:document>").encode("utf-8")
            elif item.filename == "docProps/core.xml":
                data = CORE.encode("utf-8")
            elif item.filename == "docProps/app.xml":
                data = APP.encode("utf-8")
            elif item.filename == "word/settings.xml":
                xml = data.decode("utf-8")
                xml = re.sub(r"<w:rsids>.*?</w:rsids>", "", xml, flags=re.S)       # edit-session ids
                xml = re.sub(r"<w14:docId [^>]*/>|<w15:docId [^>]*/>", "", xml)     # document identity
                data = xml.encode("utf-8")
            elif item.filename == "word/styles.xml":
                data = re.sub(r'<w:rsid w:val="[^"]*"/>', "", data.decode("utf-8")).encode("utf-8")
            zout.writestr(item.filename, data)
    print("wrote", OUT)


if __name__ == "__main__":
    main(sys.argv[1])
