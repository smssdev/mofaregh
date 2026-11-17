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
     * @return list<array{start_ms: int, end_ms: int, text: string, unclear?: list<array{start_ms: int, end_ms: int, text: string, offset: int}>}>
     *
     * @throws AsrFailed
     */
    public function transcribe(string $audioPath, Closure $progress, ?string $model = null): array;
}
