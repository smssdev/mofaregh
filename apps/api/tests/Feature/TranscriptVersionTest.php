<?php

namespace Tests\Feature;

use App\Enums\AudioStatus;
use App\Enums\Role;
use App\Models\AudioFile;
use App\Models\Project;
use App\Models\TranscriptVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transcript history: snapshots on save (one per editor per ten minutes), reading one, restoring one with the
 * same version check as a save, and keeping what a restore replaced.
 */
class TranscriptVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $transcriber;

    private User $reviewer;

    private AudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $project = Project::factory()->create();
        $this->transcriber = User::factory()->role(Role::Transcriber)->create();
        $this->reviewer = User::factory()->role(Role::Transcriber)->create();
        $project->members()->attach([$this->transcriber->id, $this->reviewer->id]);

        $this->audio = AudioFile::factory()->create(['project_id' => $project->id, 'status' => AudioStatus::Ready]);
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

    private function save(User $user, int $version, string ...$text): void
    {
        $this->actingAs($user, 'web')->putJson($this->url(), ['version' => $version, 'content' => $this->doc(...$text)])->assertOk();
    }

    public function test_saves_by_one_editor_within_ten_minutes_share_one_snapshot(): void
    {
        $this->save($this->transcriber, 0, 'السطر الأول');
        $this->save($this->transcriber, 1, 'السطر الأول', 'والثاني');

        $this->assertSame(1, TranscriptVersion::count());
        $this->assertSame(2, TranscriptVersion::first()->version);
        $this->assertSame(3, TranscriptVersion::first()->words);
    }

    public function test_a_new_snapshot_starts_after_ten_minutes_or_with_another_editor(): void
    {
        $this->save($this->transcriber, 0, 'أ');
        $this->travel(11)->minutes();
        $this->save($this->transcriber, 1, 'أ ب');
        $this->save($this->reviewer, 2, 'أ ب ج');

        $this->getJson($this->url('/versions'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.version', 3)
            ->assertJsonPath('data.0.author.id', $this->reviewer->id)
            ->assertJsonPath('data.2.version', 1)
            ->assertJsonMissingPath('data.0.content');
    }

    public function test_one_snapshot_is_read_with_its_text(): void
    {
        $this->save($this->transcriber, 0, 'بسم الله', 'الحمد لله');
        $id = TranscriptVersion::first()->id;

        $this->getJson($this->url("/versions/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.paragraphs', ['بسم الله', 'الحمد لله'])
            ->assertJsonPath('data.content.content.1.content.0.text', 'الحمد لله');
    }

    public function test_restoring_brings_the_old_text_back_and_keeps_what_it_replaced(): void
    {
        $this->save($this->transcriber, 0, 'النص الصحيح');
        $good = TranscriptVersion::first()->id;
        $this->travel(11)->minutes();
        $this->save($this->transcriber, 1, 'نص حُذف خطأً');

        $this->postJson($this->url("/versions/{$good}/restore"), ['version' => 2])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.content.content.0.content.0.text', 'النص الصحيح');

        $this->getJson($this->url())->assertJsonPath('content.content.0.content.0.text', 'النص الصحيح')->assertJsonPath('version', 3);

        $reasons = TranscriptVersion::orderBy('id')->pluck('reason')->all();
        $this->assertSame(['edit', 'edit', 'restore', 'restored'], $reasons);
        $kept = TranscriptVersion::where('reason', 'restore')->first();
        $this->assertSame('نص حُذف خطأً', $kept->content['content'][0]['content'][0]['text']);

        // The next save after a restore starts a new snapshot instead of overwriting the "restored" one.
        $this->save($this->transcriber, 3, 'النص الصحيح مع إضافة');
        $this->assertSame(5, TranscriptVersion::count());
    }

    public function test_a_stale_restore_is_refused_and_changes_nothing(): void
    {
        $this->save($this->transcriber, 0, 'أ');
        $id = TranscriptVersion::first()->id;
        $this->save($this->reviewer, 1, 'ب');

        $this->actingAs($this->transcriber, 'web')
            ->postJson($this->url("/versions/{$id}/restore"), ['version' => 1])
            ->assertStatus(409)
            ->assertJson(['version' => 2]);

        $this->assertSame(2, TranscriptVersion::count());
        $this->getJson($this->url())->assertJsonPath('content.content.0.content.0.text', 'ب');
    }

    public function test_outsiders_cannot_see_or_restore_and_versions_of_other_files_are_hidden(): void
    {
        $this->save($this->transcriber, 0, 'سرّي');
        $id = TranscriptVersion::first()->id;

        $outsider = User::factory()->role(Role::Transcriber)->create();
        $this->actingAs($outsider, 'web')->getJson($this->url('/versions'))->assertForbidden();
        $this->postJson($this->url("/versions/{$id}/restore"), ['version' => 1])->assertForbidden();

        $other = AudioFile::factory()->create(['project_id' => $this->audio->project_id, 'status' => AudioStatus::Ready]);
        $this->actingAs($this->transcriber, 'web')
            ->getJson("/api/audio-files/{$other->id}/transcript/versions/{$id}")
            ->assertNotFound();
    }
}
