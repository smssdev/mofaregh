<div dir="rtl">

# مُفرِّغ (Mofaregh)

تطبيق ويب لفرق تفريغ الصوتيات العربية (محاضرات تتضمن آيات قرآنية وأحاديث نبوية). يجمع بين محرر تفريغ مريح، وتفريغ آلي أوّلي (ASR)، ومسار مراجعة متعدد المراحل، وتصدير إلى Word/PDF/SRT/TXT.

## مبدأ الدقة الأساسي

1. **القرآن والحديث لا يُكتبان باليد أبدًا.** يُدرجان من قاعدة بيانات موثوقة (مصحف حفص، ومصادر الحديث المعتمدة) كـ«كتل مقفلة» لا يمكن تعديل نصها في المحرر.
2. **التحقق بـ SHA-256.** يُخزَّن مع كل كتلة مقفلة هاش نصها، ويُتحقق منه عند الحفظ والتسليم والتصدير مقابل المدوّنة المستوردة. أي اختلاف يمنع التسليم.
3. **النص العادي** يمر بالمسار: **المفرِّغ ← المراجع اللغوي ← المدقق الشرعي**، إضافةً إلى فحوص آلية قبل التسليم (تطبيع الحروف، علامات الترقيم، الكتل المقفلة، الطوابع الزمنية، المصطلحات).

التفاصيل: [`docs/adr/0002-locked-religious-text.md`](docs/adr/0002-locked-religious-text.md).

## هيكل المستودع

```
apps/api/               واجهة Laravel 11 (REST) - لم تُنشأ بعد
apps/web/               واجهة Vue 3 + TypeScript (RTL) - لم تُنشأ بعد
services/text-engine/   محرك النص (Python): التطبيع، بحث القرآن/الحديث، التحقق، التصدير
services/asr/           خدمة التفريغ الآلي (Python) - يُحدد المحرك في السبرنت 0
shared/schemas/         مخطط نموذج المستند المشترك (document.schema.json)
docs/                   القرارات المعمارية (adr/) والخطة (sprints.md) وسير العمل (workflow.md)
.github/workflows/      التكامل المستمر
docker-compose.yml      البيئة المحلية: PostgreSQL, Redis, MinIO, text-engine
```

> ملف `services/text-engine/Dockerfile` يُضاف مع المحرك ويستخدمه `docker-compose.yml`.

## تشغيل اختبارات text-engine

```bash
cd services/text-engine
pip install -e ".[dev]"
python -m pytest
```

## البيئة المحلية

```bash
cp .env.example .env
docker compose up -d
```

## حالة المشروع

**السبرنت 0 (التأسيس)** - 2026-11-29 إلى 2026-12-10: مخطط المستند، هيكل text-engine، التوثيق وCI. الخطة الكاملة في [`docs/sprints.md`](docs/sprints.md).

</div>

---

## English summary

Mofaregh is a web app for teams transcribing Arabic audio (lectures containing Quran verses and hadith). Core principle: Quran and hadith are never typed by hand; they are inserted from an authoritative database as locked blocks and verified by SHA-256 against the imported corpus. Ordinary text goes through transcriber, linguistic reviewer, then sharia checker, plus automated pre-delivery checks.

Stack: Vue 3 SPA, Laravel 11 API, PostgreSQL, Redis queues, S3-compatible object storage (MinIO), Python text-engine and ASR service. Run engine tests with `cd services/text-engine && python -m pytest`. Status: Sprint 0. `services/text-engine/Dockerfile` is added alongside the engine.
