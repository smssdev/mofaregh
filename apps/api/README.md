<div dir="rtl">

# apps/api - واجهة Laravel 13 (REST)

واجهة برمجية بجلسة Sanctum (ملف تعريف ارتباط) لتطبيق Vue في `apps/web`، وتخدم التطبيق المبني نفسه من `public/spa` على النطاق نفسه.

> وثيقة البداية ذكرت Laravel 11؛ انتهى دعمه الأمني، فاعتُمد الإصدار الحالي (13) على PHP 8.4.

## ما أُنجز (السبرنت 1)

- **المصادقة**: دخول بجلسة + CSRF، تحديد محاولات الدخول (5/دقيقة)، تعطيل الحساب يُنهي جلسته فورًا، تغيير كلمة المرور.
- **الأدوار الخمسة** (`App\Enums\Role`): مدير النظام، مدير مشروع، مفرِّغ، مراجع لغوي، مدقق شرعي، مع سياسات (`app/Policies`):
  - مدير النظام يرى كل شيء ويدير المستخدمين.
  - مدير المشروع يرى المشاريع التي يديرها فقط، ويدير فريقها وملفاتها.
  - بقية الأدوار ترى المشاريع التي أُضيفت إليها فقط، قراءةً واستماعًا.
- **المشاريع والمستخدمون**: CRUD، وفريق المشروع (`PUT /api/projects/{id}/members`).
- **رفع قابل للاستئناف** (`AudioUploadController`): بروتوكول مبسّط على نمط tus؛ الخادم هو مصدر الإزاحة (offset)، وأي إزاحة مختلفة تُرد بـ 409 مع الإزاحة الصحيحة.
- **التطبيع** (`App\Jobs\NormalizeAudio`): بعد آخر بايت يُطبَّع الملف عبر الطابور بـ ffmpeg إلى أحادي 16kHz Opus (حاوية WebM تعمل في كل المتصفحات)، وتُحسب المدة. التشغيل عبر `GET /api/audio-files/{id}/stream` مع دعم Range.

## التشغيل محليًا

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed     # حسابات تجريبية لكل دور، كلمة المرور: password
php artisan serve              # http://127.0.0.1:8000
php artisan queue:work         # أو QUEUE_CONNECTION=sync في .env
```

ثم ابنِ الواجهة (`npm run build` في `apps/web`) أو شغّل `npm run dev` هناك (يمرّر `/api` إلى المنفذ 8000).
يلزم `ffmpeg` و`ffprobe` على PATH (أو `FFMPEG_BINARY`/`FFPROBE_BINARY`).

## الاختبارات

```bash
php artisan test
vendor/bin/pint --test
```

## الإعدادات الخاصة (`config/mofaregh.php`)

| المتغير | الافتراضي | المعنى |
|---|---|---|
| `AUDIO_DISK` | `audio` (`storage/app/audio`) | قرص الملفات الأصلية والمطبَّعة؛ يمكن جعله `s3` (MinIO) |
| `AUDIO_MAX_BYTES` | 2 ج.ب | أكبر حجم ملف |
| `AUDIO_MAX_CHUNK_BYTES` | 16 م.ب | أكبر جزء في طلب PATCH واحد (الواجهة ترسل 8 م.ب) |
| `AUDIO_NORMALIZE_TIMEOUT` | 1800 ث | أقصى زمن لـ ffmpeg على ملف واحد |

## أوامر

- `php artisan mofaregh:create-admin <email>` ينشئ أول مدير نظام بكلمة مرور مولَّدة تُطبع مرة واحدة (`--reset` لإعادة تعيينها).
- `php artisan mofaregh:prune-uploads --days=7` يحذف الرفع المتروك (مجدول يوميًا).

## التالي (السبرنت 2 فما بعده)

المقاطع (segments) وأزمنتها، التكليفات، حالات سير المراجعة، واستدعاء `services/text-engine` للتصدير.

</div>
