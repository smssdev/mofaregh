# mofaregh_text.export

Exporters for the shared document model (`shared/schemas/document.schema.json`).

```python
from mofaregh_text.export import ExportOptions, export_docx, export_txt, export_srt

data: bytes = export_docx(document, ExportOptions(template="transcript", include_timestamps=True))
text: str   = export_txt(document, ExportOptions(tashkeel="strip"))
srt: str    = export_srt(document, tashkeel="as_is", rtl_mark=False)
```

## Modules

| file | role |
|---|---|
| `options.py` | `ExportOptions` (frozen dataclass, validated) |
| `layout.py` | format-independent layout: paragraphing, tashkeel policy, labels, Quran references, footnote contents. Shared by Word and TXT. |
| `docx_export.py` | `export_docx()`: styles, RTL, section, header/footer, footnotes part |
| `plain.py` | `export_txt()`, `export_srt()`, `format_srt_time()` |

## Options (`ExportOptions`)

| option | default | notes |
|---|---|---|
| `template` | `"lecture"` | `lecture`: title + flowing paragraphs; new paragraph on speaker change or a pause > `lecture_paragraph_gap_ms` (3000; `None` = never). Quran and exact hadith are set off as their own paragraphs. `transcript`: one paragraph per segment, `[timestamp] speaker:` prefix, Quran/hadith inline. |
| `include_timestamps` | `False` | `[hh:mm:ss]` before each lecture paragraph / transcript segment |
| `timestamp_digits` | `"arabic_indic"` | or `"western"` |
| `include_speakers` | `None` | `None` = template default (lecture off, transcript on). In lecture the name is printed only when the speaker changes. |
| `tashkeel` | `"as_is"` | `"strip"` strips ordinary text only (text blocks, meaning/not_found hadith wording, title, speaker names, tags, takhrij). Quran text, exact-hadith text and hadith `source_text` are copied byte-for-byte, always. `document.settings.tashkeel` is not consulted; the caller decides. |
| `hadith_takhrij` | `"footnote"` | or `"inline"` (in parentheses, style *Mofaregh Takhrij*). Notes for `meaning` / `not_found` are always footnotes. If `takhrij` is null it is assembled from collection / number / grade. |
| `surah_names` | `None` | `{surah_number: name}`; fallback `سورة ٣`. No dependency on `quran/`. |
| `reference_digits` | `"arabic_indic"` | digits of `{surah}` / `{ayah_range}` (`٥`, `٥-٧`) and TXT note numbers |
| `quran_font` | `KFGQPC Uthmanic Script HAFS` | fallback chain `quran_font_fallbacks`: Amiri Quran → Scheherazade New → Traditional Arabic |
| `body_font` | `Traditional Arabic` | fallback chain `body_font_fallbacks`: Sakkal Majalla → Arial |
| `body_size_pt` / `quran_size_pt` / `hadith_size_pt` | 14 / 16 / 14 | plus `title_size_pt` 20, `footnote_size_pt` 11, `header_footer_size_pt` 10 |
| `margins_cm` | 2.5 | A4 page |

Quran reference format comes from `document.settings.quran_reference_format`
(default `[{surah_name}: {ayah_range}]`, placeholders `{surah_name} {surah} {ayah_range}`;
unknown placeholders are left as written).

Rendering: Quran `﴿text﴾ [ref]` (U+FD3F … U+FD3E in logical order); exact hadith
`«text»` in *Mofaregh Hadith*; meaning hadith = speaker's words without quotes +
footnote `رواية بالمعنى، ولفظه في المصدر: «source_text»`; not_found = words + footnote
`لم يُعثر على مصدره`. Tags: `[غير واضح]` `[ضحك]` `[تداخل أصوات]` `[صمت]` `[انقطاع]`,
`other` → `[note]` (or `[ملاحظة]` when the note is empty).

## Word implementation notes

**Fonts are not embedded.** Word has no font fallback list: `w:rFonts` holds one
name per slot. We set `w:ascii`, `w:hAnsi`, `w:eastAsia` **and `w:cs`** (Arabic is
shaped from the complex-script slot), and declare the fonts in `fontTable.xml` with
`w:charset="B2"` (Arabic) and `w:altName` = first fallback as a substitution hint
(how much a viewer honours it varies). Users without the fonts get the system substitute.

**Sizes.** python-docx's `font.size` only writes `w:sz`; Arabic runs use `w:szCs`,
so every style writes both.

**Styles, not direct formatting.** Fonts/sizes/colours live in named styles so a
user can restyle the whole document from Word's style gallery:
paragraph *Mofaregh Title / Body / Quran / Hadith*, character *Mofaregh Quran Char /
Hadith Char* (linked to the paragraph styles via `w:link`), *Mofaregh Timestamp /
Speaker / Reference / Tag / Takhrij*, and the built-ins *footnote text* /
*footnote reference* (ids `FootnoteText` / `FootnoteReference`). Runs carry only
`w:rStyle`, `w:rtl` and `w:lang w:bidi="ar-SA"`. `docDefaults` gets explicit fonts
with the theme attributes removed (theme attributes would override explicit names).
python-docx's `stylesWithEffects.xml` (Word 2010 duplicate) is dropped.

**RTL.** Every paragraph (body, header, footer, footnotes, separators) has `w:bidi`;
every run has `w:rtl` + `w:lang w:bidi="ar-SA"`; `sectPr` has `w:bidi` and
`w:rtlGutter`; `settings/themeFontLang` gets `w:bidi="ar-SA"`.

**Alignment choice.** We never emit `jc="left"`/`"right"`: in a `w:bidi` paragraph
Word interprets them logically (start/end) while the spec and some consumers treat
them physically, so the result differs between Word versions and LibreOffice. We use
`jc="both"` (justified; the last line falls to the start = right edge) for body and
hadith, `jc="center"` for title, Quran, header and footer, and *no* `jc` for footnotes
(default = start = right in a bidi paragraph). The hadith indent is symmetric
(`w:left` = `w:right`) for the same reason. `start`/`end` are avoided because they are only
part of later editions of the transitional schema and older Word versions may not
accept them.

**Schema order.** `w:pPr`, `w:rPr`, `w:style`, `w:sectPr` and `w:settings` children
are emitted in ECMA-376 sequence order (Word reports files with out-of-order
children as corrupt even when LibreOffice opens them). The tests assert the order.

**Footnotes** (python-docx has no API). On the first footnote, `_Footnotes`:
1. builds `/word/footnotes.xml` as a `docx.opc.part.XmlPart` with content type
   `application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml`
   containing the two special footnotes Word requires: `w:id="-1"
   w:type="separator"` (`<w:separator/>`) and `w:id="0"
   w:type="continuationSeparator"` (`<w:continuationSeparator/>`);
2. relates it from the main document part with relationship type
   `.../relationships/footnotes` (`document.part.relate_to`); python-docx's
   package writer emits the `[Content_Types].xml` `<Override>` from the part's
   content type;
3. adds `<w:footnotePr><w:footnote w:id="-1"/><w:footnote w:id="0"/></w:footnotePr>`
   to `settings.xml` before `w:compat` (as Word writes it).

Each note is `<w:footnote w:id="n">` (n = 1, 2, …) holding a `FootnoteText`
paragraph that starts with a `FootnoteReference` run containing `<w:footnoteRef/>`;
the body references it with a `FootnoteReference` run containing
`<w:footnoteReference w:id="n"/>`. Documents without notes get no footnotes part.
The output reopens and re-saves with python-docx (the part survives as an opaque
part) and converts with LibreOffice (footnotes rendered at the page bottom,
separator on the right).

## TXT / SRT

TXT uses the same layout as Word; footnotes become `(١)` markers with the notes
listed after a `--------------------` line. SRT: one cue per non-empty segment,
`hh:mm:ss,mmm --> hh:mm:ss,mmm`, blocks flattened with spaces, Quran as `﴿text﴾`,
exact hadith as `«text»` (verbatim), tags as labels; `rtl_mark=True` prefixes lines
with U+200F for players that guess direction from the first character.

## Limitations

- No XSD validation in the test suite (no schemas offline); validity is checked by
  well-formedness, explicit child-order assertions, python-docx reopen and the
  optional LibreOffice→PDF test. Not yet opened in real Microsoft Word.
- Fonts are not embedded; fallback is up to the viewer.
- Footnote numbers use Word's default decimal format (Word may display them with
  Arabic-Indic digits depending on its "Numeral" setting).
- Partial-verse quotes (`word_start`/`word_end`) are not reflected in the reference.

## Client template (`ExportOptions.client()`)

Modelled on the client's reference file (private client data, deliberately not in the repo; only its measured layout is reproduced):
A4, margins 2.54 cm top/bottom and 3.175 cm left/right, Sakkal Majalla 18 pt for
everything (title, body, Quran, hadith), `jc="lowKashida"`, first-line indent 454
twips (0.8 cm), 6 pt before/after, single line spacing, centred un-bolded title,
no header/footer, no colours. Quran is `﴿text﴾ [يونس: 45]` and exact hadith `«text»`
inside the running paragraph (not set off). Pass `surah_names` for the reference
names; extra overrides go through `ExportOptions.client(tashkeel="strip", ...)`.

```python
export_docx(document, ExportOptions.client(surah_names=SURAH_NAMES))
```
