<?php

namespace App\Jobs;

use App\Enums\AsrStatus;
use App\Enums\AudioStatus;
use App\Models\AudioFile;
use App\Services\Asr\AsrCancelled;
use App\Services\Asr\AsrFailed;
use App\Services\Asr\DraftParts;
use App\Services\Asr\Transcriber;
use App\Services\Audio\AudioClipper;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Produces the automatic first draft of one file (Sprint 4), or of one part of it (a minute, five minutes…) that
 * then replaces the same stretch of the draft. Runs on its own "asr" connection: Whisper on a CPU can take longer
 * than the audio itself, far beyond the default queue's retry_after. Stops when asked (status set to cancelled)
 * or when the file is deleted meanwhile.
 */
class TranscribeAudio implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** The part is cut this much longer, so a word spoken across its end is not cut in half (and is kept in it). */
    public const RUN_ON_MS = 3000;

    public int $timeout;

    /** The unique lock must not outlive the job: a worker killed mid-way would otherwise block every retry. */
    public int $uniqueFor;

    public function __construct(public AudioFile $audioFile)
    {
        $this->timeout = (int) config('mofaregh.asr.timeout') + 120;
        $this->uniqueFor = $this->timeout + 600;
        $this->onConnection('asr');
    }

    public function uniqueId(): string
    {
        return (string) $this->audioFile->id;
    }

    public function handle(Transcriber $transcriber, ?AudioClipper $clipper = null): void
    {
        $audio = $this->audioFile->fresh();
        $transcript = $audio?->transcript;
        if ($audio === null || $transcript === null || $audio->status !== AudioStatus::Ready) {
            return;
        }
        // Only the job the draft is waiting for runs. After a restart the old job may still be in the queue (its
        // worker never started) and would otherwise transcribe the same file a second time.
        if ($transcript->asr_status !== AsrStatus::Queued) {
            return;
        }

        $transcript->update([
            'asr_status' => AsrStatus::Running, 'asr_progress' => 0, 'asr_stage' => 'loading',
            'asr_error' => null, 'asr_heartbeat_at' => now(),
        ]);

        $start = $transcript->asr_range_start_ms;
        $end = $transcript->asr_range_end_ms;
        $isPart = $start !== null && $end !== null;

        $temporary = null;
        $clip = null;
        try {
            $path = $this->localPath($audio, $temporary);
            if ($isPart) {
                $clip = ($clipper ?? app(AudioClipper::class))->cut($path, $start, $end - $start + self::RUN_ON_MS);
            }
            $askedStop = fn (): bool => ! AudioFile::whereKey($audio->id)->exists()
                || $transcript->newQuery()->toBase()->where('id', $transcript->id)->value('asr_status') !== AsrStatus::Running->value;
            $last = 0;
            $lastStage = 'loading';
            $paragraphs = $transcriber->transcribe($clip ?? $path, function (int $percent, ?string $stage) use ($transcript, &$last, &$lastStage): void {
                // A write every two percent (or on a new stage) is plenty for the progress bar and the heartbeat.
                if ($percent >= $last + 2 || ($stage !== null && $stage !== $lastStage)) {
                    $last = max($last, $percent);
                    $lastStage = $stage ?? $lastStage;
                    // A query, not save(): the model is stale on purpose and must not write back a cancelled status.
                    $transcript->newQuery()->whereKey($transcript->id)->where('asr_status', AsrStatus::Running->value)
                        ->update(['asr_progress' => min(99, $last), 'asr_stage' => $lastStage, 'asr_heartbeat_at' => now()]);
                }
            }, shouldStop: $askedStop, offsetMs: $isPart ? $start : 0, keepBeforeMs: $isPart ? $end - $start : null);

            $fresh = $transcript->fresh();
            if ($fresh === null || $fresh->asr_status !== AsrStatus::Running) {
                return; // stopped in the last seconds: nothing is written
            }
            $duration = (int) round(($audio->duration_seconds ?? 0) * 1000);
            $fresh->update([
                'asr_status' => AsrStatus::Done,
                'asr_progress' => 100,
                'asr_stage' => null,
                'asr_result' => $isPart ? DraftParts::merge($fresh->asr_result, $paragraphs, $start, $end) : $paragraphs,
                'asr_covered' => $isPart ? DraftParts::cover($fresh->asr_covered, $start, $end) : [[0, max($duration, (int) (end($paragraphs)['end_ms'] ?? 0))]],
                'asr_model' => $transcriber->model(),
                'asr_finished_at' => now(),
            ]);
        } catch (AsrCancelled) {
            // The controller already set the status; the draft keeps the parts finished before.
        } catch (AsrFailed $e) {
            report($e);
            $transcript->update(['asr_status' => AsrStatus::Failed, 'asr_stage' => null, 'asr_error' => $e->getMessage()]);
        } finally {
            $temporary && @unlink($temporary);
            $clip && @unlink($clip);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->audioFile->transcript?->newQuery()->whereKey($this->audioFile->transcript->id)
            ->where('asr_status', '!=', AsrStatus::Cancelled->value)->update([
                'asr_status' => AsrStatus::Failed,
                'asr_stage' => null,
                'asr_error' => 'تعذّر التفريغ الآلي، أعد المحاولة لاحقًا.',
            ]);
    }

    private function localPath(AudioFile $audio, ?string &$temporary): string
    {
        $disk = Storage::disk($audio->disk);
        if (config("filesystems.disks.{$audio->disk}.driver") === 'local') {
            return $disk->path($audio->normalized_path);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mofaregh-asr-in-');
        $source = $disk->readStream($audio->normalized_path);
        $target = fopen($temporary, 'wb');
        stream_copy_to_stream($source, $target);
        fclose($target);
        is_resource($source) && fclose($source);

        return $temporary;
    }
}
