<?php

namespace App\Services\Audio;

interface AudioNormalizer
{
    /**
     * Converts any audio/video file to the project's single working format and returns its duration in seconds.
     *
     * @throws NormalizationFailed when the input is not usable audio
     */
    public function normalize(string $inputPath, string $outputPath): float;

    /** File extension of the normalized output (without the dot). */
    public function extension(): string;

    public function mimeType(): string;
}
