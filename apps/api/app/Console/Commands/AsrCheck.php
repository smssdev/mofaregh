<?php

namespace App\Console\Commands;

use App\Enums\AsrStatus;
use App\Models\Transcript;
use App\Services\Asr\AsrFailed;
use App\Services\Asr\Transcriber;
use App\Services\Asr\WhisperTranscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Read-only health report of the automatic draft, printed in every deploy log (deploy/activate.sh). With --run it
 * also transcribes three seconds of generated audio with the smallest model, which proves the whole chain:
 * Python, faster-whisper, audio decoding and the model download.
 */
class AsrCheck extends Command
{
    protected $signature = 'mofaregh:asr-check {--run : Transcribe a short generated clip with the "tiny" model}';

    protected $description = 'Report whether the automatic draft (Whisper) can run on this server, and why not';

    public function handle(Transcriber $transcriber): int
    {
        $python = (string) config('mofaregh.python');
        $this->line('asr: python '.($python === '' ? '(MOFAREGH_PYTHON not set)' : $python).($transcriber->isAvailable() ? '' : '  <- missing'));

        if ($python !== '' && is_file($python)) {
            $probe = new Process([$python, '-c', 'import faster_whisper, ctranslate2, av, mofaregh_asr; print(faster_whisper.__version__, ctranslate2.__version__, av.__version__)'], timeout: 120);
            $probe->run();
            $this->line('asr: packages '.($probe->isSuccessful()
                ? 'faster-whisper/ctranslate2/av '.trim($probe->getOutput())
                : 'FAILED '.Str::limit(trim($probe->getErrorOutput()), 300)));
        }

        $usable = $transcriber instanceof WhisperTranscriber ? $transcriber->usableModelDir() : config('mofaregh.asr.model_dir');
        $this->line('asr: model '.config('mofaregh.asr.model').', '.config('mofaregh.asr.threads').' threads, dir '
            .($usable === null ? 'NOT WRITABLE ('.config('mofaregh.asr.model_dir').'), falling back to ~/.cache/huggingface' : $this->modelDirSummary()));
        $this->line('asr: server '.$this->serverSummary());
        $this->line('asr: scheduler '.$this->schedulerSummary());
        $this->queueSummary();
        $this->transcriptSummary();

        if ($this->option('run')) {
            $this->selfTest($transcriber);
        }

        return self::SUCCESS;
    }

    private function modelDirSummary(): string
    {
        $dir = (string) config('mofaregh.asr.model_dir');
        if (! is_dir($dir)) {
            return "{$dir} (empty: the first draft downloads the model)";
        }
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $bytes += $file->isFile() ? $file->getSize() : 0;
        }

        return $dir.' ('.Number::fileSize($bytes).')';
    }

    private function serverSummary(): string
    {
        $parts = [];
        if (is_readable('/proc/meminfo') && preg_match('/MemAvailable:\s+(\d+) kB/', (string) file_get_contents('/proc/meminfo'), $m)) {
            $available = (int) $m[1] * 1024;
            $parts[] = 'memory available '.Number::fileSize($available);
            if ($available < 2.5 * 1024 ** 3 && ! in_array(config('mofaregh.asr.model'), ['tiny', 'base', 'small'], true)) {
                $parts[] = 'WARNING: under 2.5 GB free, the '.config('mofaregh.asr.model').' model may be killed; consider ASR_MODEL=small';
            }
        }
        $cores = (new Process(['nproc']));
        $cores->run();
        if ($cores->isSuccessful()) {
            $parts[] = trim($cores->getOutput()).' CPU cores';
        }
        $parts[] = 'disk free '.Number::fileSize((int) @disk_free_space(storage_path()));

        return implode(', ', $parts);
    }

    private function schedulerSummary(): string
    {
        $crontab = new Process(['crontab', '-l']);
        $crontab->run();
        if (! $crontab->isSuccessful()) {
            return 'unknown (crontab -l failed)';
        }

        return str_contains($crontab->getOutput(), 'schedule:run')
            ? 'ok (crontab runs schedule:run, which starts the asr queue worker)'
            : 'MISSING: no schedule:run in the crontab, so queued drafts never start';
    }

    private function queueSummary(): void
    {
        try {
            $jobs = DB::table(config('queue.connections.asr.table', 'jobs'))->where('queue', 'asr');
            $waiting = (clone $jobs)->whereNull('reserved_at')->count();
            $running = (clone $jobs)->whereNotNull('reserved_at')->count();
            $oldest = (clone $jobs)->whereNull('reserved_at')->min('available_at');
            $age = $oldest ? ' (oldest waiting '.now()->diffForHumans(now()->setTimestamp((int) $oldest), true).')' : '';
            $this->line("asr: queue {$waiting} waiting, {$running} running{$age}");

            $failed = DB::table('failed_jobs')->where('payload', 'like', '%TranscribeAudio%');
            $count = (clone $failed)->count();
            if ($count > 0) {
                $last = (clone $failed)->latest('failed_at')->first();
                $this->line("asr: {$count} failed job(s), last at {$last->failed_at}: ".Str::limit(strtok((string) $last->exception, "\n"), 250));
            }
        } catch (Throwable $e) {
            $this->line('asr: queue unknown ('.Str::limit($e->getMessage(), 120).')');
        }
    }

    private function transcriptSummary(): void
    {
        $counts = Transcript::query()->selectRaw('asr_status, count(*) as n')->groupBy('asr_status')->pluck('n', 'asr_status');
        $this->line('asr: drafts '.($counts->isEmpty() ? 'none yet' : $counts->map(fn ($n, $status) => "{$status}={$n}")->implode(', ')));

        Transcript::query()
            ->whereIn('asr_status', [AsrStatus::Queued->value, AsrStatus::Running->value])
            ->get()
            ->filter->asrStalled()
            ->each(fn (Transcript $t) => $this->line("asr: STALLED file {$t->audio_file_id} ({$t->asr_status->value}, last sign of life ".($t->asr_heartbeat_at ?? $t->updated_at)?->diffForHumans().')'));

        Transcript::query()
            ->where('asr_status', AsrStatus::Failed->value)
            ->latest('updated_at')
            ->limit(3)
            ->get()
            ->each(fn (Transcript $t) => $this->line("asr: failed file {$t->audio_file_id} at {$t->updated_at}: ".Str::limit((string) $t->asr_error, 250)));
    }

    private function selfTest(Transcriber $transcriber): void
    {
        if (! $transcriber->isAvailable()) {
            $this->line('asr: self-test SKIPPED (no Python)');

            return;
        }

        $clip = tempnam(sys_get_temp_dir(), 'mofaregh-asr-test-').'.wav';
        $make = new Process([
            (string) config('mofaregh.audio.ffmpeg'), '-nostdin', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'sine=frequency=300:duration=3', '-ac', '1', '-ar', '16000', $clip,
        ], timeout: 60);
        $make->run();
        if (! $make->isSuccessful()) {
            $this->line('asr: self-test SKIPPED (ffmpeg could not make a clip: '.Str::limit(trim($make->getErrorOutput()), 200).')');

            return;
        }

        $started = microtime(true);
        try {
            $stages = [];
            $transcriber->transcribe($clip, function (int $percent, ?string $stage) use (&$stages): void {
                $stage && $stages[$stage] = true;
            }, 'tiny');
            $this->line(sprintf('asr: self-test OK in %.1f s (model tiny; stages: %s)', microtime(true) - $started, implode(', ', array_keys($stages))));
        } catch (AsrFailed $e) {
            $this->line(sprintf('asr: self-test FAILED after %.1f s: %s', microtime(true) - $started, $e->getMessage()));
        } finally {
            @unlink($clip);
        }
    }
}
