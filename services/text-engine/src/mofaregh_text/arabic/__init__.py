"""Arabic text utilities: diacritics handling and search normalisation."""

from .diacritics import has_tashkeel, strip_tashkeel
from .normalize import (
    Token,
    confusion_key,
    normalize_for_search,
    osa_distance_le1,
    skeleton,
    tokenize,
    tokenize_with_spans,
)

__all__ = [
    "Token",
    "confusion_key",
    "has_tashkeel",
    "normalize_for_search",
    "osa_distance_le1",
    "skeleton",
    "strip_tashkeel",
    "tokenize",
    "tokenize_with_spans",
]
