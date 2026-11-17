from mofaregh_asr.paragraphs import group_paragraphs


def seg(start, end, text):
    return {"start": start, "end": end, "text": text}


def test_pause_starts_a_new_paragraph():
    out = group_paragraphs([seg(0, 2, " أول "), seg(2.2, 4, "ثان"), seg(7, 9, "ثالث")])
    assert out == [
        {"start_ms": 0, "end_ms": 4000, "text": "أول ثان", "unclear": []},
        {"start_ms": 7000, "end_ms": 9000, "text": "ثالث", "unclear": []},
    ]


def test_long_text_breaks_at_sentence_end_then_hard_limit():
    sentence = "كلمة " * 40 + "نهاية."            # > soft limit, ends a sentence
    out = group_paragraphs([seg(0, 5, sentence), seg(5.1, 6, "بداية جديدة")], soft_chars=100)
    assert [p["text"][-6:] for p in out][0] == "نهاية."
    assert len(out) == 2

    run_on = [seg(i, i + 1, "كلام بلا وقف " * 5) for i in range(20)]
    assert len(group_paragraphs(run_on, hard_chars=200)) > 3


def test_empty_segments_are_skipped():
    assert group_paragraphs([seg(0, 1, "   "), seg(1, 2, "")]) == []


def test_unsure_words_are_reported_with_exact_times_and_offsets():
    def word(start, end, text, p):
        return {"start": start, "end": end, "word": " " + text, "probability": p}

    first = {"start": 0, "end": 2, "text": " قال الشيخ قال", "words": [
        word(0.0, 0.5, "قال", 0.9), word(0.5, 1.2, "الشيخ", 0.95), word(1.2, 1.9, "قال", 0.2)]}
    second = {"start": 2.1, "end": 3, "text": "كلمة غامضة", "words": [
        word(2.1, 2.5, "كلمة", 0.8), word(2.5, 3.0, "غامضة", 0.31)]}
    (para,) = group_paragraphs([first, second])
    assert para["text"] == "قال الشيخ قال كلمة غامضة"
    assert para["unclear"] == [
        {"start_ms": 1200, "end_ms": 1900, "text": "قال", "offset": 10},     # the second "قال", not the first
        {"start_ms": 2500, "end_ms": 3000, "text": "غامضة", "offset": 19},
    ]
    for u in para["unclear"]:
        assert para["text"][u["offset"]:u["offset"] + len(u["text"])] == u["text"]


def test_known_outro_lines_and_loops_are_dropped():
    from mofaregh_asr.paragraphs import drop_hallucinations

    segs = [
        {"start": 0, "end": 2, "text": "الحمد لله رب العالمين"},
        {"start": 2, "end": 3, "text": " ترجمة نانسي قنقر"},
        {"start": 3, "end": 4, "text": "سبحان الله"},
        {"start": 4, "end": 5, "text": "سُبحانَ اللهِ."},
        {"start": 5, "end": 6, "text": "سبحان الله"},
        {"start": 6, "end": 7, "text": "سبحان الله"},
        {"start": 7, "end": 8, "text": "اشتركوا في القناة!"},
        {"start": 8, "end": 9, "text": "والصلاة والسلام على رسول الله"},
    ]
    kept = [s["text"] for s in drop_hallucinations(segs)]
    assert kept == ["الحمد لله رب العالمين", "سبحان الله", "سُبحانَ اللهِ.", "والصلاة والسلام على رسول الله"]


def test_clip_drops_the_run_on_and_moves_times_into_the_file():
    from mofaregh_asr.paragraphs import clip_segments

    words = [
        {"start": 58.0, "end": 59.0, "word": " قال", "probability": 0.9},
        {"start": 59.2, "end": 59.8, "word": " الله", "probability": 0.9},
        {"start": 60.4, "end": 61.0, "word": " تعالى", "probability": 0.9},
    ]
    segments = [
        {"start": 0.0, "end": 2.0, "text": " بسم الله", "words": []},
        {"start": 58.0, "end": 61.0, "text": " قال الله تعالى", "words": words},
        {"start": 61.5, "end": 62.0, "text": " بعد الحد", "words": []},
    ]
    out = clip_segments(segments, keep_before=60.0, offset=120.0)
    assert [s["text"] for s in out] == [" بسم الله", " قال الله"]
    assert out[0]["start"] == 120.0
    assert out[1]["end"] == 179.8
    assert out[1]["words"][0]["start"] == 178.0


def test_clip_without_limits_changes_nothing_but_the_offset():
    from mofaregh_asr.paragraphs import clip_segments

    out = clip_segments([seg(1, 2, "نص")], offset=0.0)
    assert out == [{"start": 1.0, "end": 2.0, "text": "نص", "words": []}]
