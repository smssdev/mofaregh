<?php

namespace App\Services\Audio;

use App\Services\Asr\AsrFailed;
use Symfony\Component\Process\Process;

/** Cuts one part of an audio file for the automatic draft (a 16 kHz mono WAV: what Whisper reads anyway). */
class AudioClipper
{
    /** @throws AsrFailed */
    public function cut(string $path, int $startMs, int $lengthMs): string
    {
        $clip = tempnam(sys_get_temp_dir(), 'mofaregh-asr-part-').'.wav';
        $process = new Process([
            (string) config('mofaregh.audio.ffmpeg'), '-nostdin', '-loglevel', 'error', '-y',
            '-ss', sprintf('%.3F', $startMs / 1000), '-i', $path, '-t', sprintf('%.3F', $lengthMs / 1000),
            '-vn', '-ac', '1', '-ar', '16000', $clip,
        ], timeout: 600);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($clip) || filesize($clip) < 100) {
            @unlink($clip);

            throw new AsrFailed('تعذّر قصّ الجزء المطلوب من الصوت (ffmpeg).');
        }

        return $clip;
    }
}
