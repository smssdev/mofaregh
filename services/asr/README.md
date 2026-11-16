# services/asr - خدمة التفريغ الآلي (Python)

**المحرك:** Whisper عبر `faster-whisper` على معالج السيرفر نفسه (قرار العميل 2026-11-16: الصوت لا يغادر السيرفر).

```
python -m mofaregh_asr AUDIO --out result.json --model medium --threads 2
```

- يطبع `PROGRESS 0.42` أثناء العمل، ويكتب في `--out`:
  `{"language","duration","model","paragraphs":[{"start_ms","end_ms","text"}]}`.
- الفقرات تُجمَّع في `paragraphs.py`: فقرة جديدة بعد صمت > 1.5 ث، أو عند نهاية جملة بعد 350 حرفًا، أو قسرًا عند 700.
- يشغّله الـAPI من طابور `asr` (`App\Jobs\TranscribeAudio`) ببايثون البيئة الافتراضية `shared/venv` على السيرفر.
- الإعدادات في `.env` الخاص بالـAPI: `ASR_MODEL` (افتراضي `medium`؛ `small` أسرع وأقل دقة، `large-v3` أدق وأثقل)،
  `ASR_THREADS` (افتراضي 2)، `ASR_TIMEOUT` بالثواني.
- النموذج يُنزَّل عند أول تشغيل (medium ≈ 1.5 غ.ب) ويحتاج نحو 2 غ.ب ذاكرة.

## لم يُنجز بعد
- قياس WER على صوتيات العميل (السبرنت 4)، طوابع لكل كلمة، تلوين الثقة، فصل المتحدثين.
