"""CLI the API's queue job runs:

    python -m mofaregh_asr AUDIO --out result.json [--model small] [--threads 2] [--beam-size 5] [--language ar]
                                [--offset SECONDS] [--keep-before SECONDS]

Prints ``STAGE <loading|transcribing>`` and ``PROGRESS <0..1>`` lines on stdout while it works (the
first run downloads the model, which can take minutes before any progress), and writes
``{"language", "duration", "model", "paragraphs": [{"start_ms", "end_ms", "text", "unclear": [...]}]}``
to --out; ``unclear`` lists the words Whisper was unsure of with their exact times (see paragraphs.py).
The model is downloaded on first use (Hugging Face cache of the running user).
"""

from __future__ import annotations

import argparse
import json
import os
import sys

from .paragraphs import clip_segments, drop_hallucinations, group_paragraphs

# Nudges Whisper towards punctuated Modern Standard Arabic.
INITIAL_PROMPT = "بسم الله الرحمن الرحيم. الحمد لله رب العالمين، والصلاة والسلام على رسول الله."


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="mofaregh_asr")
    parser.add_argument("audio")
    parser.add_argument("--out", required=True)
    parser.add_argument("--model", default="small")
    parser.add_argument("--threads", type=int, default=2)
    parser.add_argument("--language", default="ar")
    parser.add_argument("--compute-type", default="int8")
    parser.add_argument("--beam-size", type=int, default=5,
                        help="5 is the most accurate; 1-2 is several times faster on a one-core server")
    parser.add_argument("--unclear-below", type=float, default=0.5,
                        help="words with a lower probability are reported as unclear, with their times")
    parser.add_argument("--offset", type=float, default=0.0,
                        help="AUDIO is a clip starting this many seconds into the file: added to every time")
    parser.add_argument("--keep-before", type=float, default=None,
                        help="drop what starts after this many seconds of the clip (it runs on a little so no word is cut)")
    args = parser.parse_args(argv)

    print("STAGE loading", flush=True)
    from faster_whisper import WhisperModel  # imported late: heavy, and absent in unit tests

    threads = max(1, min(args.threads, os.cpu_count() or 1))  # more threads than cores only adds contention
    model = WhisperModel(args.model, device="cpu", compute_type=args.compute_type, cpu_threads=threads)
    segments, info = model.transcribe(
        args.audio,
        language=args.language,
        beam_size=max(1, args.beam_size),
        vad_filter=True,
        vad_parameters={"min_silence_duration_ms": 700},
        initial_prompt=INITIAL_PROMPT,
        word_timestamps=True,  # exact times (and confidence) of every word, for the "unclear" marks
        condition_on_previous_text=False,  # stops one bad stretch from derailing the rest of a long lecture
        hallucination_silence_threshold=2.0,  # skips text Whisper invents over long silences (needs word times)
    )
    print("STAGE transcribing", flush=True)
    duration = float(info.duration or 0)
    collected = []
    last = -1.0
    for seg in segments:
        collected.append({
            "start": seg.start, "end": seg.end, "text": seg.text,
            "words": [{"start": w.start, "end": w.end, "word": w.word, "probability": w.probability}
                      for w in (seg.words or [])],
        })
        span = min(duration, args.keep_before) if args.keep_before else duration
        done = min(1.0, seg.end / span) if span else 0.0
        if done - last >= 0.01:
            last = done
            print(f"PROGRESS {done:.3f}", flush=True)

    result = {
        "language": info.language,
        "duration": duration,
        "model": args.model,
        "paragraphs": group_paragraphs(
            drop_hallucinations(clip_segments(collected, keep_before=args.keep_before, offset=args.offset)),
            unclear_below=args.unclear_below,
        ),
    }
    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(result, fh, ensure_ascii=False)
    print("PROGRESS 1.000", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
