<?php

namespace App\Services\Audio;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * The working format (docs/adr/0001): mono, 16 kHz, Opus. 16 kHz mono is what ASR engines expect, and Opus
 * keeps an hour of speech near 15 MB for playback in the editor. WebM is the container because every current
 * browser, Safari included, plays Opus in WebM and can seek in it.
 */
class FfmpegAudioNormalizer implements AudioNormalizer
{
    public function __construct(
        private readonly string $ffmpeg,
        private readonly string $ffprobe,
        private readonly int $timeout,
    ) {}

    public function normalize(string $inputPath, string $outputPath): float
    {
        $this->run([
            $this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $inputPath,
            '-vn', '-map_metadata', '-1',
            '-ac', '1', '-ar', '16000',
            '-c:a', 'libopus', '-b:a', '32k', '-application', 'voip',
            '-f', 'webm', $outputPath,
        ]);

        $duration = (float) trim($this->run([
            $this->ffprobe, '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $outputPath,
        ]));

        if ($duration <= 0) {
            throw new NormalizationFailed('لا يحتوي الملف على صوت قابل للتشغيل.');
        }

        return $duration;
    }

    public function extension(): string
    {
        return 'webm';
    }

    public function mimeType(): string
    {
        return 'audio/webm';
    }

    /** @param  list<string>  $command */
    private function run(array $command): string
    {
        $process = new Process($command, timeout: $this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $detail = Str::limit(trim($process->getErrorOutput()) ?: 'exit code '.$process->getExitCode(), 500);
            throw new NormalizationFailed("تعذّر تحويل الملف الصوتي ({$detail}).");
        }

        return $process->getOutput();
    }
}
