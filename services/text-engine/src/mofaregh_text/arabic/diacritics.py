"""Arabic diacritics (tashkeel) primitives shared across the engine.

Public API (stable, other modules depend on it):
    HARAKAT            - set of ordinary diacritic code points
    QURANIC_MARKS      - set of Quranic annotation code points (small high letters, waqf signs...)
    TATWEEL            - the kashida character
    strip_tashkeel(text, *, keep_shadda=False) -> str
    has_tashkeel(text) -> bool
"""

from __future__ import annotations

# Fathatan .. Sukun, plus the extended combining marks U+0656..U+065F.
HARAKAT: frozenset[str] = frozenset(chr(c) for c in range(0x064B, 0x0660))
SHADDA = "ّ"
SUPERSCRIPT_ALEF = "ٰ"

# Honorific/annotation marks above letters (U+0610..U+061A) and Quranic
# annotation signs (U+06D6..U+06ED, excluding U+06DD end-of-ayah and U+06DE
# rub el hizb which are symbols rather than marks on letters).
QURANIC_MARKS: frozenset[str] = frozenset(
    [chr(c) for c in range(0x0610, 0x061B)]
    + [chr(c) for c in range(0x06D6, 0x06EE) if c not in (0x06DD, 0x06DE, 0x06E9)]
    + [SUPERSCRIPT_ALEF]
)

TATWEEL = "ـ"

_ALL_MARKS = HARAKAT | QURANIC_MARKS | {TATWEEL}


def strip_tashkeel(text: str, *, keep_shadda: bool = False) -> str:
    """Remove diacritics, Quranic annotation marks and tatweel.

    Base letters, spaces and punctuation are never altered.
    """
    if keep_shadda:
        return "".join(ch for ch in text if ch not in _ALL_MARKS or ch == SHADDA)
    return "".join(ch for ch in text if ch not in _ALL_MARKS)


def has_tashkeel(text: str) -> bool:
    return any(ch in HARAKAT or ch == SUPERSCRIPT_ALEF for ch in text)
