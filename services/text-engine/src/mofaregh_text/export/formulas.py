"""Fixed religious formulas that keep their tashkeel when the rest of the
ordinary text is exported without it (the client's convention: plain speech,
fully vowelled formulas, Quran and hadith).

Applied only to ordinary text, after ``strip_tashkeel``. Quran and exact-hadith
text never pass through here. Extend ``FORMULAS`` with the client's approved list.
"""

from __future__ import annotations

import re

from mofaregh_text.arabic.diacritics import strip_tashkeel

FORMULAS: tuple[str, ...] = (
    "تَبَارَكَ وَتَعَالَى",
    "صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ",
    "جَلَّ فِي عُلَاهُ",
)


def _pattern(plain: str) -> str:
    # words separated by any whitespace; a prefixed و is allowed ("وصلى الله عليه وسلم")
    return r"\s+".join(re.escape(w) for w in plain.split()) + r"(?![ء-ي])"


_COMPILED = tuple((re.compile(_pattern(strip_tashkeel(f))), f) for f in FORMULAS)


def restore_formulas(text: str) -> str:
    for rx, vowelled in _COMPILED:
        text = rx.sub(vowelled, text)
    return text
