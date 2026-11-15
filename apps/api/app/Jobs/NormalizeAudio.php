<?php

namespace App\Jobs;

use App\Enums\AudioStatus;
use App\Models\AudioFile;
use App\Services\Audio\AudioNormalizer;
use App\Services\Audio\NormalizationFailed;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a fully uploaded original into the normalized working file (Sprint 1: "تطبيع ffmpeg عبر الطوابير").
 */
class NormalizeAudio implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout;

    public function __construct(public AudioFile $audioFile)
    {
        $this->timeout = config('mofaregh.audio.timeout') + 60;
    }

    public function uniqueId(): string
    {
        return (string) $this->audioFile->id;
    }

    public function handle(AudioNormalizer $normalizer): void
    {
        $audio = $this->audioFile->fresh();
        if ($audio === null || $audio->original_path === null) {
            return;
        }

        $audio->update(['status' => AudioStatus::Processing, 'error' => null]);

        $disk = Storage::disk($audio->disk);
        $temporary = [];

        try {
            $input = $this->localPath($audio->disk, $audio->original_path, $temporary);
            $target = "normalized/{$audio->id}.{$normalizer->extension()}";
            if ($this->isLocal($audio->disk)) {
                $output = $this->ensureDirectory($disk->path($target));
            } else {
                $output = tempnam(sys_get_temp_dir(), 'mofaregh-out-');
                $temporary[] = $output;
            }

            $duration = $normalizer->normalize($input, $output);

            if (! $this->isLocal($audio->disk)) {
                $stream = fopen($output, 'rb');
                $disk->writeStream($target, $stream);
                is_resource($stream) && fclose($stream);
            }

            $audio->update([
                'status' => AudioStatus::Ready,
                'normalized_path' => $target,
                'duration_seconds' => round($duration, 3),
                'processed_at' => now(),
            ]);
        } catch (NormalizationFailed $e) {
            // The file itself is the problem: retrying will not help.
            $audio->update(['status' => AudioStatus::Failed, 'error' => $e->getMessage()]);
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->audioFile->update([
            'status' => AudioStatus::Failed,
            'error' => 'تعذّرت معالجة الملف، أعد المحاولة لاحقًا.',
        ]);
    }

    private function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    /** @param  list<string>  $temporary */
    private function localPath(string $disk, string $path, array &$temporary): string
    {
        if ($this->isLocal($disk)) {
            return Storage::disk($disk)->path($path);
        }

        $local = tempnam(sys_get_temp_dir(), 'mofaregh-in-');
        $temporary[] = $local;
        $source = Storage::disk($disk)->readStream($path);
        $target = fopen($local, 'wb');
        stream_copy_to_stream($source, $target);
        fclose($target);
        is_resource($source) && fclose($source);

        return $local;
    }

    private function ensureDirectory(string $file): string
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }

        return $file;
    }
}
