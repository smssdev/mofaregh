<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** mofaregh:backup against a real SQLite file: a readable gzipped snapshot, pruning, and the audio mirror. */
class BackupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/mofaregh-backup-'.uniqid();
        File::ensureDirectoryExists($this->root);
        touch("{$this->root}/app.sqlite");

        config([
            'database.connections.sqlite.database' => "{$this->root}/app.sqlite",
            'mofaregh.backup.dir' => "{$this->root}/backups",
            'mofaregh.backup.keep_days' => 14,
            'mofaregh.backup.audio_dir' => '',
        ]);
        DB::purge('sqlite');
        DB::statement('create table notes (body text)');
        DB::table('notes')->insert(['body' => 'نص محفوظ']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_writes_a_gzipped_snapshot_that_opens_as_the_same_database(): void
    {
        $this->artisan('mofaregh:backup --reason=manual')->assertSuccessful();

        $files = glob("{$this->root}/backups/db-*-manual.sqlite.gz");
        $this->assertCount(1, $files);
        File::put("{$this->root}/restored.sqlite", gzdecode((string) file_get_contents($files[0])));

        $restored = new \PDO("sqlite:{$this->root}/restored.sqlite");
        $this->assertSame('نص محفوظ', $restored->query('select body from notes')->fetchColumn());
    }

    public function test_old_snapshots_are_pruned_but_the_three_newest_always_stay(): void
    {
        File::ensureDirectoryExists("{$this->root}/backups");
        foreach (['20260101-000000', '20260102-000000', '20260103-000000', '20260104-000000'] as $stamp) {
            $file = "{$this->root}/backups/db-{$stamp}-daily.sqlite.gz";
            File::put($file, 'old');
            touch($file, now()->subDays(30)->getTimestamp());
        }

        $this->artisan('mofaregh:backup')->assertSuccessful();

        $left = array_map('basename', glob("{$this->root}/backups/db-*.sqlite.gz"));
        $this->assertCount(3, $left);
        $this->assertNotContains('db-20260101-000000-daily.sqlite.gz', $left);
        $this->assertNotContains('db-20260102-000000-daily.sqlite.gz', $left);
    }

    public function test_audio_is_mirrored_once_when_a_mirror_folder_is_set(): void
    {
        Storage::fake('audio');
        Storage::disk('audio')->put('normalized/1.webm', 'audio-bytes');
        config(['mofaregh.audio.disk' => 'audio', 'mofaregh.backup.audio_dir' => "{$this->root}/audio-mirror"]);

        $this->artisan('mofaregh:backup')->expectsOutputToContain('+1 new file')->assertSuccessful();
        $this->assertSame('audio-bytes', file_get_contents("{$this->root}/audio-mirror/normalized/1.webm"));

        $this->artisan('mofaregh:backup')->expectsOutputToContain('+0 new file')->assertSuccessful();
    }
}
