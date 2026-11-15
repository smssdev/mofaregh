<?php

namespace Tests\Feature;

use App\Enums\AudioStatus;
use App\Enums\Role;
use App\Jobs\NormalizeAudio;
use App\Models\AudioFile;
use App\Models\Project;
use App\Models\User;
use App\Services\Audio\AudioNormalizer;
use App\Services\Audio\NormalizationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sprint 1 acceptance: a large upload resumes after an interruption, and the normalized file appears.
 */
class AudioUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('audio');
        config(['filesystems.disks.audio.driver' => 'local', 'filesystems.disks.local.driver' => 'local']);

        $this->manager = User::factory()->role(Role::ProjectManager)->create();
        $this->project = Project::factory()->create(['manager_id' => $this->manager->id]);
    }

    private function begin(string $name = 'درس 1.mp3', int $size = 30): TestResponse
    {
        return $this->actingAs($this->manager, 'web')->postJson("/api/projects/{$this->project->id}/audio-files", [
            'name' => $name,
            'size' => $size,
            'mime_type' => 'audio/mpeg',
        ]);
    }

    private function send(int $id, int $offset, string $bytes, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager, 'web')->call(
            'PATCH',
            "/api/audio-files/{$id}/upload",
            server: [
                'CONTENT_TYPE' => 'application/offset+octet-stream',
                'HTTP_UPLOAD_OFFSET' => (string) $offset,
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $bytes,
        );
    }

    public function test_an_upload_arrives_in_chunks_and_is_queued_for_normalization(): void
    {
        Queue::fake();
        $bytes = random_bytes(30);

        $id = $this->begin()->assertCreated()->assertJsonPath('status', 'uploading')->json('id');

        $this->send($id, 0, substr($bytes, 0, 10))->assertOk()->assertJson(['offset' => 10]);
        $this->send($id, 10, substr($bytes, 10, 10))->assertOk()->assertJson(['offset' => 20]);
        $this->send($id, 20, substr($bytes, 20))->assertOk()->assertJson(['offset' => 30, 'status' => 'uploaded']);

        $audio = AudioFile::findOrFail($id);
        $this->assertSame(AudioStatus::Uploaded, $audio->status);
        $this->assertSame("originals/{$id}.mp3", $audio->original_path);
        $this->assertSame($bytes, Storage::disk('audio')->get($audio->original_path));
        Storage::disk('local')->assertMissing($audio->partialPath());
        Queue::assertPushed(NormalizeAudio::class, fn ($job) => $job->audioFile->is($audio));
    }

    public function test_an_interrupted_upload_resumes_from_the_server_offset(): void
    {
        Queue::fake();
        $bytes = random_bytes(30);
        $id = $this->begin()->json('id');

        $this->send($id, 0, substr($bytes, 0, 12))->assertOk();

        // The connection drops; the client asks where to continue.
        $this->actingAs($this->manager, 'web')->getJson("/api/audio-files/{$id}/upload")
            ->assertOk()->assertJson(['offset' => 12, 'size' => 30, 'status' => 'uploading']);

        // A replayed or stale chunk is refused with the real offset instead of corrupting the file.
        $this->send($id, 0, substr($bytes, 0, 12))->assertStatus(409)->assertJson(['offset' => 12]);
        $this->send($id, 20, substr($bytes, 20))->assertStatus(409)->assertJson(['offset' => 12]);

        $this->send($id, 12, substr($bytes, 12))->assertOk()->assertJson(['offset' => 30]);
        $this->assertSame($bytes, Storage::disk('audio')->get(AudioFile::find($id)->original_path));
    }

    public function test_bytes_beyond_the_declared_size_are_rejected(): void
    {
        $id = $this->begin(size: 10)->json('id');

        $this->send($id, 0, random_bytes(11))->assertStatus(413);

        $this->actingAs($this->manager, 'web')->getJson("/api/audio-files/{$id}/upload")->assertJson(['offset' => 0]);
    }

    public function test_a_finished_upload_reports_its_full_size(): void
    {
        Queue::fake();
        $id = $this->begin(size: 4)->json('id');
        $this->send($id, 0, 'abcd')->assertOk();

        $this->send($id, 4, 'e')->assertStatus(409)->assertJson(['offset' => 4]);
    }

    public function test_the_upload_needs_the_right_headers(): void
    {
        $id = $this->begin()->json('id');

        $this->actingAs($this->manager, 'web')->call('PATCH', "/api/audio-files/{$id}/upload",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_UPLOAD_OFFSET' => '0', 'HTTP_ACCEPT' => 'application/json'],
            content: 'x')->assertStatus(415);

        $this->actingAs($this->manager, 'web')->call('PATCH', "/api/audio-files/{$id}/upload",
            server: ['CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_ACCEPT' => 'application/json'],
            content: 'x')->assertStatus(400);
    }

    public function test_unsupported_and_oversized_files_are_refused(): void
    {
        $this->begin('virus.exe')->assertStatus(422)->assertJsonValidationErrors('name');

        config(['mofaregh.audio.max_bytes' => 100]);
        $this->begin(size: 101)->assertStatus(422)->assertJsonValidationErrors('size');
    }

    public function test_a_500_mb_upload_is_accepted(): void
    {
        $this->begin(size: 500 * 1024 * 1024)->assertCreated();
    }

    public function test_members_cannot_upload_and_strangers_cannot_see(): void
    {
        $transcriber = User::factory()->role(Role::Transcriber)->create();
        $this->project->members()->attach($transcriber);
        $stranger = User::factory()->role(Role::ProjectManager)->create();
        $id = $this->begin()->json('id');

        $this->actingAs($transcriber, 'web')->postJson("/api/projects/{$this->project->id}/audio-files", ['name' => 'a.mp3', 'size' => 1])
            ->assertForbidden();
        $this->send($id, 0, 'abc', $transcriber)->assertForbidden();
        $this->actingAs($transcriber, 'web')->getJson("/api/projects/{$this->project->id}/audio-files")->assertOk()->assertJsonCount(1);

        $this->actingAs($stranger, 'web')->getJson("/api/audio-files/{$id}")->assertForbidden();
        $this->send($id, 0, 'abc', $stranger)->assertForbidden();
    }

    public function test_normalization_marks_the_file_ready_and_it_can_be_played(): void
    {
        $this->app->instance(AudioNormalizer::class, new class implements AudioNormalizer
        {
            public function normalize(string $inputPath, string $outputPath): float
            {
                file_put_contents($outputPath, 'normalized:'.file_get_contents($inputPath));

                return 3725.5;
            }

            public function extension(): string
            {
                return 'webm';
            }

            public function mimeType(): string
            {
                return 'audio/webm';
            }
        });

        $id = $this->begin(size: 5)->json('id');
        $this->send($id, 0, 'hello')->assertOk(); // the sync queue runs the job right away

        $this->actingAs($this->manager, 'web')->getJson("/api/audio-files/{$id}")
            ->assertOk()
            ->assertJson(['status' => 'ready', 'duration_seconds' => 3725.5, 'stream_url' => "/api/audio-files/{$id}/stream"]);

        $transcriber = User::factory()->role(Role::Transcriber)->create();
        $this->project->members()->attach($transcriber);
        $response = $this->actingAs($transcriber, 'web')->get("/api/audio-files/{$id}/stream")->assertOk();
        $this->assertSame('audio/webm', $response->headers->get('Content-Type'));
        $this->assertSame('normalized:hello', $response->streamedContent());
    }

    public function test_a_file_that_is_not_audio_is_marked_failed_and_can_be_retried(): void
    {
        $this->app->instance(AudioNormalizer::class, new class implements AudioNormalizer
        {
            public function normalize(string $inputPath, string $outputPath): float
            {
                throw new NormalizationFailed('لا يحتوي الملف على صوت قابل للتشغيل.');
            }

            public function extension(): string
            {
                return 'webm';
            }

            public function mimeType(): string
            {
                return 'audio/webm';
            }
        });

        $id = $this->begin(size: 3)->json('id');
        $this->send($id, 0, 'abc')->assertOk();

        $this->actingAs($this->manager, 'web')->getJson("/api/audio-files/{$id}")
            ->assertJson(['status' => 'failed', 'error' => 'لا يحتوي الملف على صوت قابل للتشغيل.', 'stream_url' => null]);
        $this->actingAs($this->manager, 'web')->get("/api/audio-files/{$id}/stream")->assertNotFound();

        Queue::fake();
        $this->actingAs($this->manager, 'web')->postJson("/api/audio-files/{$id}/retry")->assertOk()->assertJson(['status' => 'uploaded']);
        Queue::assertPushed(NormalizeAudio::class);
    }

    public function test_deleting_a_file_removes_its_bytes(): void
    {
        Queue::fake();
        $id = $this->begin(size: 3)->json('id');
        $this->send($id, 0, 'abc');
        $path = AudioFile::find($id)->original_path;

        $this->actingAs($this->manager, 'web')->deleteJson("/api/audio-files/{$id}")->assertNoContent();

        Storage::disk('audio')->assertMissing($path);
        $this->assertDatabaseMissing('audio_files', ['id' => $id]);
    }

    public function test_abandoned_uploads_are_pruned(): void
    {
        $id = $this->begin()->json('id');
        AudioFile::whereKey($id)->update(['updated_at' => now()->subDays(8)]);

        $this->artisan('mofaregh:prune-uploads --days=7')->assertSuccessful();

        $this->assertDatabaseMissing('audio_files', ['id' => $id]);
    }
}
