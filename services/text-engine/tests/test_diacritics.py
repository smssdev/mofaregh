from mofaregh_text.arabic.diacritics import has_tashkeel, strip_tashkeel


def test_strip_removes_harakat_and_tatweel():
    assert strip_tashkeel("كَتَبَ الطّـالِبُ") == "كتب الطالب"


def test_strip_keeps_shadda_when_asked():
    assert strip_tashkeel("مُدَرِّس", keep_shadda=True) == "مدرّس"


def test_strip_leaves_plain_text_and_punctuation():
    text = "نص عادي، بلا تشكيل! 123"
    assert strip_tashkeel(text) == text


def test_has_tashkeel():
    assert has_tashkeel("عِلْم")
    assert not has_tashkeel("علم")
