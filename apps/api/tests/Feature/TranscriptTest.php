<?php

namespace Tests\Feature;

use App\Enums\AsrStatus;
use App\Enums\AudioStatus;
use App\Enums\Role;
use App\Jobs\TranscribeAudio;
use App\Models\AudioFile;
use App\Models\Project;
use App\Models\User;
use App\Services\Asr\AsrCancelled;
use App\Services\Asr\AsrFailed;
use App\Services\Asr\Transcriber;
use App\Services\Audio\AudioClipper;
use App\Services\Export\DocxExporter;
use App\Services\Export\EditorText;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The editor's backend: save with a version check, automatic draft, Word export, and who may do what.
 */
class TranscriptTest extends TestCase
{
    use RefreshDatabase;

    private User $transcriber;

    private AudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('audio');
        config(['filesystems.disks.audio.driver' => 'local']);

        $project = Project::factory()->create();
        $this->transcriber = User::factory()->role(Role::Transcriber)->create();
        $project->members()->attach($this->transcriber);

        $this->audio = AudioFile::factory()->create([
            'project_id' => $project->id,
            'original_name' => 'درس الفقه.mp3',
            'status' => AudioStatus::Ready,
            'normalized_path' => 'normalized/1.webm',
        ]);
        Storage::disk('audio')->put('normalized/1.webm', 'audio-bytes');

        $this->fakeEngines();
        $engine = get_class(app(Transcriber::class));
        $engine::$calls = [];
        $engine::$meanwhile = null;

        // Cutting a part needs ffmpeg; here it only hands back a named stand-in file.
        $this->app->bind(AudioClipper::class, fn () => new class extends AudioClipper
        {
            public function cut(string $path, int $startMs, int $lengthMs): string
            {
                return "clip:{$startMs}+{$lengthMs}";
            }
        });
        $this->audio->update(['duration_seconds' => 600]);
    }

    /** @param  list<array{start_ms: int, end_ms: int, text: string}>|null  $draft  null makes the engine fail */
    private function fakeEngines(bool $available = true, ?array $draft = [['start_ms' => 0, 'end_ms' => 2000, 'text' => 'مسودة آلية']]): void
    {
        $this->app->bind(Transcriber::class, fn () => new class($available, $draft) implements Transcriber
        {
            public function __construct(private bool $available, private ?array $draft) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function model(): string
            {
                return 'fake';
            }

            /** @var list<array{string, int, ?int}> path, offset, keep-before of every call */
            public static array $calls = [];

            /** Set by a test: called where a real engine would be busy, e.g. to press stop meanwhile. */
            public static ?Closure $meanwhile = null;

            public function transcribe(
                string $audioPath,
                Closure $progress,
                ?string $model = null,
                ?Closure $shouldStop = null,
                int $offsetMs = 0,
                ?int $keepBeforeMs = null,
            ): array {
                self::$calls[] = [$audioPath, $offsetMs, $keepBeforeMs];
                $progress(0, 'loading');
                $progress(50, 'transcribing');
                if (self::$meanwhile) {
                    (self::$meanwhile)();
                }
                if ($shouldStop && $shouldStop()) {
                    throw new AsrCancelled('stopped');
                }
                if ($this->draft === null) {
                    throw new AsrFailed('تعذّر التفريغ الآلي: خطأ تجريبي');
                }

                // A real engine returns times in the whole file: the clip's own times moved by the offset.
                return array_map(fn (array $p): array => ['start_ms' => $p['start_ms'] + $offsetMs, 'end_ms' => $p['end_ms'] + $offsetMs] + $p, $this->draft);
            }
        });

        $this->app->bind(DocxExporter::class, fn () => new class($available) implements DocxExporter
        {
            /** @var list<array{string, list<string>}> */
            public static array $calls = [];

            public function __construct(private bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function export(string $title, array $paragraphs): string
            {
                self::$calls[] = [$title, $paragraphs];

                return 'PK-docx-bytes';
            }
        });
    }

    private function doc(string ...$paragraphs): array
    {
        return ['type' => 'doc', 'content' => array_map(
            fn (string $t) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]],
            $paragraphs,
        )];
    }

    private function url(string $suffix = ''): string
    {
        return "/api/audio-files/{$this->audio->id}/transcript{$suffix}";
    }

    public function test_a_member_reads_an_empty_transcript_then_saves_and_reloads_it(): void
    {
        $this->actingAs($this->transcriber, 'web')->getJson($this->url())
            ->assertOk()
            ->assertJson(['version' => 0, 'content' => null, 'asr' => ['status' => 'none', 'available' => true], 'export_available' => true]);

        $this->putJson($this->url(), ['version' => 0, 'title' => 'الدرس الأول', 'content' => $this->doc('بسم الله')])
            ->assertOk()
            ->assertJson(['version' => 1, 'title' => 'الدرس الأول', 'updated_by' => ['id' => $this->transcriber->id]]);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('content.content.0.content.0.text', 'بسم الله');
    }

    public function test_a_stale_save_is_refused_and_does_not_overwrite(): void
    {
        $this->actingAs($this->transcriber, 'web');
        $this->putJson($this->url(), ['version' => 0, 'content' => $this->doc('النسخة الأولى')])->assertOk();

        $this->putJson($this->url(), ['version' => 0, 'content' => $this->doc('كتابة من جلسة قديمة')])
            ->assertStatus(409)
            ->assertJson(['version' => 1]);

        $this->getJson($this->url())->assertJsonPath('content.content.0.content.0.text', 'النسخة الأولى');
    }

    public function test_people_outside_the_project_cannot_read_write_transcribe_or_export(): void
    {
        $outsider = User::factory()->role(Role::Transcriber)->create();
        $this->actingAs($outsider, 'web');

        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url(), ['version' => 0, 'content' => $this->doc('x')])->assertForbidden();
        $this->postJson($this->url('/transcribe'))->assertForbidden();
        $this->getJson($this->url('/export'))->assertForbidden();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_transcribe_queues_the_job_once_and_the_job_stores_the_draft(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');

        $this->postJson($this->url('/transcribe'))->assertOk()->assertJsonPath('asr.status', 'queued');
        Queue::assertPushed(TranscribeAudio::class, fn (TranscribeAudio $job) => $job->connection === 'asr');

        $this->postJson($this->url('/transcribe'))->assertStatus(409);
        Queue::assertPushed(TranscribeAudio::class, 1);

        (new TranscribeAudio($this->audio))->handle(app(Transcriber::class));

        $this->getJson($this->url())
            ->assertJsonPath('asr.status', 'done')
            ->assertJsonPath('asr.progress', 100)
            ->assertJsonPath('asr.model', 'fake')
            ->assertJsonPath('asr.paragraphs.0.text', 'مسودة آلية')
            ->assertJsonPath('content', null);   // the draft never overwrites the editor's text by itself
    }

    public function test_a_failed_engine_is_reported_and_can_be_retried(): void
    {
        $this->fakeEngines(draft: null);
        $this->actingAs($this->transcriber, 'web');
        $this->audio->transcript()->create(['asr_status' => AsrStatus::Queued]);

        (new TranscribeAudio($this->audio))->handle(app(Transcriber::class));

        $this->getJson($this->url())
            ->assertJsonPath('asr.status', 'failed')
            ->assertJsonPath('asr.error', 'تعذّر التفريغ الآلي: خطأ تجريبي');

        Queue::fake();
        $this->postJson($this->url('/transcribe'))->assertOk();
    }

    public function test_transcribe_says_so_when_no_engine_is_installed_or_the_audio_is_not_ready(): void
    {
        $this->fakeEngines(available: false);
        $this->actingAs($this->transcriber, 'web');
        $this->postJson($this->url('/transcribe'))->assertStatus(503);

        $this->fakeEngines();
        $this->audio->update(['status' => AudioStatus::Processing]);
        $this->postJson($this->url('/transcribe'))->assertStatus(409);
    }

    public function test_export_sends_the_flattened_paragraphs_to_the_word_template(): void
    {
        $this->actingAs($this->transcriber, 'web');
        $this->getJson($this->url('/export'))->assertStatus(422);

        $content = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'مقدمة']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'قال ', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => 'الشيخ'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'سطر ثان'],
            ]],
            ['type' => 'paragraph'],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'بند أول']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'بند ثان']]]]],
            ]],
            ['type' => 'orderedList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'مرقّم']]]]],
            ]],
        ]];
        $this->putJson($this->url(), ['version' => 0, 'title' => 'فضل: العلم؟', 'content' => $content])->assertOk();

        $response = $this->get($this->url('/export'))->assertOk();
        $this->assertSame('PK-docx-bytes', $response->getContent());
        $this->assertStringContainsString('wordprocessingml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(rawurlencode('فضل العلم؟.docx'), $response->headers->get('Content-Disposition'));

        $this->assertSame(
            ['مقدمة', "قال الشيخ\nسطر ثان", '• بند أول', '• بند ثان', '١- مرقّم'],
            EditorText::paragraphs($content),
        );
    }

    public function test_export_falls_back_to_the_file_name_and_reports_a_missing_engine(): void
    {
        $this->actingAs($this->transcriber, 'web');
        $this->putJson($this->url(), ['version' => 0, 'content' => $this->doc('نص')])->assertOk();

        $response = $this->get($this->url('/export'))->assertOk();
        $this->assertStringContainsString(rawurlencode('درس الفقه.docx'), $response->headers->get('Content-Disposition'));

        $this->fakeEngines(available: false);
        // The fake still "exports"; the real exporter throws ExportFailed -> 503 (covered by its own unit below).
        $this->getJson($this->url())->assertJson(['export_available' => false, 'asr' => ['available' => false]]);
    }

    public function test_a_stalled_draft_can_be_started_again_and_a_live_one_cannot(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');
        $transcript = $this->audio->transcript()->create([
            'asr_status' => AsrStatus::Running, 'asr_progress' => 40, 'asr_heartbeat_at' => now()->subMinutes(5),
        ]);

        $this->getJson($this->url())->assertJsonPath('asr.stalled', false);
        $this->postJson($this->url('/transcribe'))->assertStatus(409);

        $transcript->update(['asr_heartbeat_at' => now()->subMinutes(45)]);
        $this->getJson($this->url())->assertJsonPath('asr.stalled', true);
        $this->postJson($this->url('/transcribe'))->assertOk()->assertJsonPath('asr.status', 'queued');
        Queue::assertPushed(TranscribeAudio::class, 1);
    }

    public function test_a_queued_draft_no_worker_picks_up_is_reported_stalled(): void
    {
        $this->actingAs($this->transcriber, 'web');
        $this->audio->transcript()->create(['asr_status' => AsrStatus::Queued, 'asr_heartbeat_at' => now()->subMinutes(10)]);

        $this->getJson($this->url())->assertJsonPath('asr.stalled', true);
    }

    public function test_the_job_records_its_stage_and_clears_it_when_done(): void
    {
        $this->audio->transcript()->create(['asr_status' => AsrStatus::Queued]);
        (new TranscribeAudio($this->audio))->handle(app(Transcriber::class));

        $transcript = $this->audio->fresh()->transcript;
        $this->assertSame(AsrStatus::Done, $transcript->asr_status);
        $this->assertNull($transcript->asr_stage);
        $this->assertNotNull($transcript->asr_heartbeat_at);
    }

    public function test_a_leftover_job_does_not_transcribe_a_file_again(): void
    {
        $this->audio->transcript()->create(['asr_status' => AsrStatus::Done, 'asr_result' => [['start_ms' => 0, 'end_ms' => 1, 'text' => 'قديم']]]);

        (new TranscribeAudio($this->audio))->handle(app(Transcriber::class));

        $this->assertSame('قديم', $this->audio->fresh()->transcript->asr_result[0]['text']);
    }

    public function test_the_health_check_reports_without_failing(): void
    {
        $this->audio->transcript()->create(['asr_status' => AsrStatus::Failed, 'asr_error' => 'نفدت الذاكرة']);

        $this->artisan('mofaregh:asr-check')
            ->expectsOutputToContain('asr: drafts failed=1')
            ->expectsOutputToContain('نفدت الذاكرة')
            ->assertSuccessful();
    }

    public function test_deleting_the_audio_removes_its_transcript(): void
    {
        $this->audio->transcript()->create([]);
        $this->audio->delete();
        $this->assertDatabaseCount('transcripts', 0);
    }

    private function runJob(): void
    {
        (new TranscribeAudio($this->audio))->handle(app(Transcriber::class), app(AudioClipper::class));
    }

    public function test_a_part_is_cut_transcribed_with_its_offset_and_merged_into_the_draft(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');
        $engine = get_class(app(Transcriber::class));

        // First minute, then the second: each part adds its own paragraphs and coverage.
        $this->postJson($this->url('/transcribe'), ['from_ms' => 0, 'to_ms' => 60000])
            ->assertOk()->assertJsonPath('asr.range', ['start_ms' => 0, 'end_ms' => 60000]);
        $this->runJob();
        $this->postJson($this->url('/transcribe'), ['from_ms' => 60000, 'to_ms' => 120000])->assertOk();
        $this->runJob();

        $this->assertSame([
            ['clip:0+'.(60000 + TranscribeAudio::RUN_ON_MS), 0, 60000],
            ['clip:60000+'.(60000 + TranscribeAudio::RUN_ON_MS), 60000, 60000],
        ], $engine::$calls);

        $this->getJson($this->url())
            ->assertJsonPath('asr.status', 'done')
            ->assertJsonPath('asr.covered', [[0, 120000]])
            ->assertJsonPath('asr.paragraphs.0.start_ms', 0)
            ->assertJsonPath('asr.paragraphs.1.start_ms', 60000)
            ->assertJsonCount(2, 'asr.paragraphs');

        // Doing the first minute again replaces it instead of adding a copy.
        $this->postJson($this->url('/transcribe'), ['from_ms' => 0, 'to_ms' => 60000])->assertOk();
        $this->runJob();
        $this->getJson($this->url())->assertJsonCount(2, 'asr.paragraphs')->assertJsonPath('asr.covered', [[0, 120000]]);
    }

    public function test_a_part_is_kept_inside_the_audio_and_must_be_long_enough(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');

        $this->postJson($this->url('/transcribe'), ['from_ms' => 600000, 'to_ms' => 660000])->assertStatus(422);
        $this->postJson($this->url('/transcribe'), ['from_ms' => 0, 'to_ms' => 3000])->assertStatus(422);
        $this->postJson($this->url('/transcribe'), ['from_ms' => 9000])->assertStatus(422);

        $this->postJson($this->url('/transcribe'), ['from_ms' => 570000, 'to_ms' => 900000])
            ->assertOk()->assertJsonPath('asr.range', ['start_ms' => 570000, 'end_ms' => 600000]);
    }

    public function test_a_queued_draft_is_stopped_before_it_starts(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');
        $engine = get_class(app(Transcriber::class));

        $this->postJson($this->url('/transcribe'))->assertOk();
        $this->postJson($this->url('/transcribe/cancel'))->assertOk()->assertJsonPath('asr.status', 'cancelled');
        $this->runJob();

        $this->assertSame([], $engine::$calls);
        $this->getJson($this->url())->assertJsonPath('asr.status', 'cancelled')->assertJsonPath('asr.paragraphs', null);
        $this->postJson($this->url('/transcribe/cancel'))->assertStatus(409);
    }

    public function test_stopping_a_running_part_keeps_the_parts_finished_before(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');
        $engine = get_class(app(Transcriber::class));

        $this->postJson($this->url('/transcribe'), ['from_ms' => 0, 'to_ms' => 60000])->assertOk();
        $this->runJob();

        $this->postJson($this->url('/transcribe'), ['from_ms' => 60000, 'to_ms' => 120000])->assertOk();
        $engine::$meanwhile = fn () => $this->postJson($this->url('/transcribe/cancel'))->assertOk();
        $this->runJob();

        $this->getJson($this->url())
            ->assertJsonPath('asr.status', 'cancelled')
            ->assertJsonPath('asr.error', null)
            ->assertJsonPath('asr.covered', [[0, 60000]])
            ->assertJsonCount(1, 'asr.paragraphs');

        // Stopped, it can be started again at once.
        $engine::$meanwhile = null;
        $this->postJson($this->url('/transcribe'), ['from_ms' => 60000, 'to_ms' => 120000])->assertOk()->assertJsonPath('asr.status', 'queued');
    }

    public function test_deleting_the_file_while_it_is_transcribed_stops_the_engine(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');
        $engine = get_class(app(Transcriber::class));

        $this->postJson($this->url('/transcribe'))->assertOk();
        $engine::$meanwhile = fn () => AudioFile::whereKey($this->audio->id)->delete();
        $this->runJob();

        $this->assertDatabaseMissing('audio_files', ['id' => $this->audio->id]);
    }

    public function test_the_draft_can_be_thrown_away_but_not_while_it_runs(): void
    {
        Queue::fake();
        $this->actingAs($this->transcriber, 'web');

        $this->postJson($this->url('/transcribe'))->assertOk();
        $this->deleteJson($this->url('/draft'))->assertStatus(409);
        $this->runJob();

        $this->putJson($this->url(), ['version' => 0, 'content' => $this->doc('نص المحرر')])->assertOk();
        $this->deleteJson($this->url('/draft'))
            ->assertOk()
            ->assertJsonPath('asr.status', 'none')
            ->assertJsonPath('asr.paragraphs', null)
            ->assertJsonPath('asr.covered', [])
            ->assertJsonPath('content.content.0.content.0.text', 'نص المحرر');

        $outsider = User::factory()->role(Role::Transcriber)->create();
        $this->actingAs($outsider, 'web')->deleteJson($this->url('/draft'))->assertForbidden();
        $this->postJson($this->url('/transcribe/cancel'))->assertForbidden();
    }
}
