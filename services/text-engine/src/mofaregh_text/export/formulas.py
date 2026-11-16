"""Fixed religious formulas that are always written fully vowelled.

Learned from the client's reference document: ordinary speech is vowelled only
where the editor chose to (tanween, a few disambiguating marks), but these
formulas (120 occurrences of تَبَارَكَ وَتَعَالَى alone) always carry full tashkeel.
``restore_formulas`` rewrites each formula to its canonical vowelled form whether
the input has no marks, partial marks or different marks.

Applied to ordinary text only. Quran and exact-hadith text never pass through here.
Extend ``FORMULAS`` with the client's approved list; entries marked "inferred" were
not seen in the reference but follow the same pattern as a seen one.
"""

from __future__ import annotations

import re

from mofaregh_text.arabic.diacritics import HARAKAT, QURANIC_MARKS, strip_tashkeel

FORMULAS: tuple[str, ...] = (
    "تَبَارَكَ وَتَعَالَى",
    "صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ",
    "جَلَّ فِي عُلَاهُ",
    "جَلَّ وَعَلَا",
    "رَضِيَ اللهُ عَنْهَا",
    "رَضِيَ اللهُ عَنْهُ",  # inferred from the feminine form
    "رَحِمَهُ اللهُ تَعَالَى",
    "رَحِمَهُ اللهُ",
    "عَلَيْهِ السَّلَامُ",
)

_MARK_CHARS = "".join(sorted(HARAKAT | QURANIC_MARKS))
_MARKS = "[" + _MARK_CHARS + "]*"


def _pattern(vowelled: str) -> str:
    words = strip_tashkeel(vowelled).split()
    # every letter may already carry any marks; words are separated by any whitespace;
    # a prefixed و/ف is fine ("وصلى الله عليه وسلم"); must not run into a following letter.
    body = r"\s+".join("".join(re.escape(ch) + _MARKS for ch in w) for w in words)
    # the lookahead also rejects marks, so a shorter formula cannot stop inside a longer
    # word by backtracking over its vowels ("عنه" inside "عَنْهَا")
    return body + "(?![ء-ي" + _MARK_CHARS + "])"


# longest first so "رحمه الله تعالى" wins over "رحمه الله"
_COMPILED = tuple(
    (re.compile(_pattern(f)), f)
    for f in sorted(FORMULAS, key=lambda f: len(strip_tashkeel(f)), reverse=True)
)


def restore_formulas(text: str) -> str:
    for rx, vowelled in _COMPILED:
        text = rx.sub(lambda _m, v=vowelled: v, text)
    return text
