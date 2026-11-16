<?php

namespace Tests\Feature;

use App\Enums\AsrStatus;
use App\Enums\AudioStatus;
use App\Enums\Role;
use App\Jobs\TranscribeAudio;
use App\Models\AudioFile;
use App\Models\Project;
use App\Models\User;
use App\Services\Asr\AsrFailed;
use App\Services\Asr\Transcriber;
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

            public function transcribe(string $audioPath, Closure $progress): array
            {
                $progress(50);

                return $this->draft ?? throw new AsrFailed('تعذّر التفريغ الآلي: خطأ تجريبي');
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

    public function test_deleting_the_audio_removes_its_transcript(): void
    {
        $this->audio->transcript()->create([]);
        $this->audio->delete();
        $this->assertDatabaseCount('transcripts', 0);
    }
}
