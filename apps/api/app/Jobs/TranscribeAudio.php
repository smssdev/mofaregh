<?php

namespace App\Jobs;

use App\Enums\AsrStatus;
use App\Enums\AudioStatus;
use App\Models\AudioFile;
use App\Services\Asr\AsrFailed;
use App\Services\Asr\Transcriber;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Produces the automatic first draft of one file (Sprint 4). Runs on its own "asr" connection: Whisper on a CPU
 * can take longer than the audio itself, far beyond the default queue's retry_after.
 */
class TranscribeAudio implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public AudioFile $audioFile)
    {
        $this->timeout = (int) config('mofaregh.asr.timeout') + 120;
        $this->onConnection('asr');
    }

    public function uniqueId(): string
    {
        return (string) $this->audioFile->id;
    }

    public function handle(Transcriber $transcriber): void
    {
        $audio = $this->audioFile->fresh();
        $transcript = $audio?->transcript;
        if ($audio === null || $transcript === null || $audio->status !== AudioStatus::Ready) {
            return;
        }

        $transcript->update(['asr_status' => AsrStatus::Running, 'asr_progress' => 0, 'asr_error' => null]);

        $temporary = null;
        try {
            $path = $this->localPath($audio, $temporary);
            $last = 0;
            $paragraphs = $transcriber->transcribe($path, function (int $percent) use ($transcript, &$last): void {
                if ($percent >= $last + 2) {   // a write every two percent is plenty for the progress bar
                    $last = $percent;
                    $transcript->update(['asr_progress' => min(99, $percent)]);
                }
            });

            $transcript->update([
                'asr_status' => AsrStatus::Done,
                'asr_progress' => 100,
                'asr_result' => $paragraphs,
                'asr_model' => $transcriber->model(),
                'asr_finished_at' => now(),
            ]);
        } catch (AsrFailed $e) {
            $transcript->update(['asr_status' => AsrStatus::Failed, 'asr_error' => $e->getMessage()]);
        } finally {
            $temporary && @unlink($temporary);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->audioFile->transcript?->update([
            'asr_status' => AsrStatus::Failed,
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
