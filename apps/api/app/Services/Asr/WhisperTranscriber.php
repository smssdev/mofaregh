<?php

namespace App\Services\Asr;

use Closure;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs services/asr (faster-whisper on the CPU) with the server's Python. The audio never leaves the machine.
 */
class WhisperTranscriber implements Transcriber
{
    public function __construct(
        private readonly ?string $python,
        private readonly string $model,
        private readonly int $threads,
        private readonly int $timeout,
    ) {}

    public function isAvailable(): bool
    {
        return $this->python !== null && $this->python !== '' && is_file($this->python);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function transcribe(string $audioPath, Closure $progress): array
    {
        if (! $this->isAvailable()) {
            throw new AsrFailed('محرّك التفريغ الآلي غير مثبّت على الخادم.');
        }

        $out = tempnam(sys_get_temp_dir(), 'mofaregh-asr-');
        $process = new Process([
            $this->python, '-m', 'mofaregh_asr', $audioPath,
            '--out', $out, '--model', $this->model, '--threads', (string) $this->threads,
        ], timeout: $this->timeout);

        try {
            $process->run(function (string $type, string $buffer) use ($progress): void {
                if ($type === Process::OUT && preg_match_all('/PROGRESS ([0-9.]+)/', $buffer, $m)) {
                    $progress((int) round(min(1, (float) end($m[1])) * 100));
                }
            });

            if (! $process->isSuccessful()) {
                throw new AsrFailed('تعذّر التفريغ الآلي: '.Str::limit(trim($process->getErrorOutput()), 300));
            }

            $result = json_decode((string) file_get_contents($out), true);
            if (! is_array($result) || ! is_array($result['paragraphs'] ?? null)) {
                throw new AsrFailed('أعاد محرّك التفريغ نتيجة غير مفهومة.');
            }

            return array_values(array_map(fn (array $p): array => [
                'start_ms' => (int) $p['start_ms'],
                'end_ms' => (int) $p['end_ms'],
                'text' => (string) $p['text'],
                // Words the engine was unsure of, with their exact times and offset in the text.
                'unclear' => array_values(array_map(fn (array $u): array => [
                    'start_ms' => (int) $u['start_ms'],
                    'end_ms' => (int) $u['end_ms'],
                    'text' => (string) $u['text'],
                    'offset' => (int) $u['offset'],
                ], $p['unclear'] ?? [])),
            ], $result['paragraphs']));
        } catch (ProcessTimedOutException) {
            throw new AsrFailed('تجاوز التفريغ الآلي المهلة المحددة.');
        } finally {
            @unlink($out);
        }
    }
}
