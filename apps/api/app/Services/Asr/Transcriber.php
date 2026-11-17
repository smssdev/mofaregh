<?php

namespace App\Services\Asr;

use Closure;

/** The speech-to-text engine behind one interface, so it can be replaced (docs/adr/0001). */
interface Transcriber
{
    /** False when the server has no engine installed (the editor then hides nothing but says so). */
    public function isAvailable(): bool;

    public function model(): string;

    /**
     * @param  Closure(int, ?string): void  $progress  called with 0..100 and the stage ("loading" while the model is
     *                                                 downloaded or opened, then "transcribing") while the engine works
     * @param  (Closure(): bool)|null  $shouldStop  asked every few seconds; true stops the engine (AsrCancelled)
     * @param  int  $offsetMs  the audio is a clip starting this far into the file: every time is moved by it
     * @param  int|null  $keepBeforeMs  the clip runs on a little past its part; what starts after this is dropped
     * @return list<array{start_ms: int, end_ms: int, text: string, unclear?: list<array{start_ms: int, end_ms: int, text: string, offset: int}>}>
     *
     * @throws AsrFailed
     * @throws AsrCancelled
     */
    public function transcribe(
        string $audioPath,
        Closure $progress,
        ?string $model = null,
        ?Closure $shouldStop = null,
        int $offsetMs = 0,
        ?int $keepBeforeMs = null,
    ): array;
}
