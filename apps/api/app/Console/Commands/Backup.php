<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backs up what cannot be rebuilt: the database (a consistent SQLite snapshot, gzipped) and, when a second
 * folder is configured, a mirror of the audio. Runs every night from the scheduler and before every migration
 * on deploy. Restore: stop nothing, `gunzip -c <file> > shared/database.sqlite` (see deploy/README.md).
 */
class Backup extends Command
{
    protected $signature = 'mofaregh:backup {--reason=daily : daily, pre-deploy or manual (part of the file name)} {--keep= : days of backups to keep}';

    protected $description = 'Snapshot the database (and mirror the audio when BACKUP_AUDIO_DIR is set), keeping N days';

    public function handle(): int
    {
        $dir = (string) config('mofaregh.backup.dir');
        $keep = (int) ($this->option('keep') ?? config('mofaregh.backup.keep_days'));
        $reason = preg_replace('/[^a-z-]/', '', (string) $this->option('reason')) ?: 'manual';

        if (! File::isDirectory($dir) && ! File::makeDirectory($dir, 0700, true, true)) {
            $this->error("Cannot create the backup folder {$dir}.");

            return self::FAILURE;
        }

        $ok = $this->database($dir, $reason);
        $ok = $this->audio() && $ok;
        $this->prune($dir, $keep);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function database(string $dir, string $reason): bool
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $this->warn('backup: database '.$connection->getDriverName().' is not SQLite; back it up with its own tool (pg_dump, mysqldump).');

            return true;
        }

        $source = (string) $connection->getConfig('database');
        if ($source === ':memory:' || ! is_file($source)) {
            $this->warn('backup: no SQLite file to back up.');

            return true;
        }

        $stamp = now()->format('Ymd-His');
        $plain = "{$dir}/db-{$stamp}-{$reason}.sqlite";
        try {
            // VACUUM INTO writes a consistent copy while the app keeps running (a plain cp could catch a half write).
            $connection->statement('VACUUM INTO ?', [$plain]);
            $this->gzip($plain, "{$plain}.gz");
        } catch (Throwable $e) {
            File::delete([$plain, "{$plain}.gz"]);
            $this->error('backup: database failed: '.$e->getMessage());

            return false;
        } finally {
            File::delete($plain);
        }

        $size = round(filesize("{$plain}.gz") / 1024, 1);
        $this->info("backup: database -> {$plain}.gz ({$size} KB)");

        return true;
    }

    private function gzip(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb6');
        if ($in === false || $out === false) {
            throw new \RuntimeException("cannot open {$from} or {$to}");
        }
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        chmod($to, 0600);
    }

    /** Copies audio files the mirror does not have yet (by name and size). Files are never deleted from it. */
    private function audio(): bool
    {
        $mirror = (string) config('mofaregh.backup.audio_dir');
        if ($mirror === '') {
            return true;
        }

        $disk = Storage::disk((string) config('mofaregh.audio.disk'));
        $copied = 0;
        try {
            foreach ($disk->allFiles() as $path) {
                $target = $mirror.'/'.$path;
                if (is_file($target) && filesize($target) === $disk->size($path)) {
                    continue;
                }
                File::ensureDirectoryExists(dirname($target), 0700);
                $stream = $disk->readStream($path);
                file_put_contents($target, $stream);
                is_resource($stream) && fclose($stream);
                $copied++;
            }
        } catch (Throwable $e) {
            $this->error('backup: audio mirror failed: '.$e->getMessage());

            return false;
        }

        $this->info("backup: audio mirror {$mirror} (+{$copied} new file(s))");

        return true;
    }

    private function prune(string $dir, int $keepDays): void
    {
        if ($keepDays < 1) {
            return;
        }
        $limit = now()->subDays($keepDays)->getTimestamp();
        $files = glob("{$dir}/db-*.sqlite.gz") ?: [];
        // Never leave fewer than three, whatever their age.
        rsort($files);
        foreach (array_slice($files, 3) as $file) {
            if (filemtime($file) < $limit) {
                File::delete($file);
            }
        }
    }
}
