<?php

namespace App\Services\Asr;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
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
        /** Where models are downloaded and kept (shared storage, so every release reuses them). */
        private readonly ?string $modelDir = null,
        private readonly int $beamSize = 5,
    ) {}

    public function isAvailable(): bool
    {
        return $this->python !== null && $this->python !== '' && is_file($this->python);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function transcribe(string $audioPath, Closure $progress, ?string $model = null): array
    {
        if (! $this->isAvailable()) {
            throw new AsrFailed('محرّك التفريغ الآلي غير مثبّت على الخادم.');
        }

        $env = [
            'HF_HUB_DISABLE_PROGRESS_BARS' => '1',  // progress bars on stderr would hide the real error
            'HF_HUB_VERBOSITY' => 'error',
            'PYTHONWARNINGS' => 'ignore',
        ];
        $dir = $this->usableModelDir();
        if ($dir !== null) {
            $env['HF_HOME'] = $dir;
        }

        $out = tempnam(sys_get_temp_dir(), 'mofaregh-asr-');
        $process = new Process([
            $this->python, '-m', 'mofaregh_asr', $audioPath,
            '--out', $out, '--model', $model ?? $this->model, '--threads', (string) $this->threads,
            '--beam-size', (string) max(1, $this->beamSize),
        ], env: $env, timeout: $this->timeout);

        try {
            $stage = null;
            $process->run(function (string $type, string $buffer) use ($progress, &$stage): void {
                if ($type !== Process::OUT) {
                    return;
                }
                if (preg_match_all('/STAGE (\w+)/', $buffer, $s)) {
                    $stage = end($s[1]);
                    $progress(0, $stage);
                }
                if (preg_match_all('/PROGRESS ([0-9.]+)/', $buffer, $m)) {
                    $progress((int) round(min(1, (float) end($m[1])) * 100), $stage);
                }
            });

            if (! $process->isSuccessful()) {
                throw new AsrFailed($this->failureMessage($process));
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
        } catch (ProcessSignaledException) {
            throw new AsrFailed($this->failureMessage($process));
        } finally {
            @unlink($out);
        }
    }

    /** The model folder if it exists or can be created and is writable; null = Hugging Face's default cache. */
    public function usableModelDir(): ?string
    {
        if (! $this->modelDir) {
            return null;
        }
        if (! is_dir($this->modelDir)) {
            @mkdir($this->modelDir, 0775, true);
        }
        if (is_dir($this->modelDir) && is_writable($this->modelDir)) {
            return $this->modelDir;
        }
        Log::warning('asr: model folder is not writable, using the default cache', ['dir' => $this->modelDir, 'why' => $this->modelDirProblem()]);

        return null;
    }

    /** Why the model folder cannot be used, for the health check: the first missing or read-only folder on its path. */
    public function modelDirProblem(): ?string
    {
        if (! $this->modelDir) {
            return 'ASR_MODEL_DIR is empty';
        }
        $dir = $this->modelDir;
        while (! is_dir($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }
        if (is_dir($this->modelDir) && is_writable($this->modelDir)) {
            return null;
        }
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid((int) @fileowner($dir))['name'] ?? '?') : (string) @fileowner($dir);
        $me = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();
        $mode = substr(sprintf('%o', (int) @fileperms($dir)), -4);

        return ($dir === $this->modelDir ? 'not writable' : "cannot create it inside {$dir}")." (owner {$owner}, mode {$mode}, running as {$me})";
    }

    /** One Arabic sentence for the editor, with the engine's own last error line for whoever reads the logs. */
    private function failureMessage(Process $process): string
    {
        Log::warning('asr: engine failed', [
            'exit' => $process->isTerminated() ? $process->getExitCode() : null,
            'signal' => $process->hasBeenSignaled() ? $process->getTermSignal() : null,
            'stderr' => Str::limit($process->getErrorOutput(), 4000),
        ]);

        // Killed by the system (SIGKILL = 9): on a shared server that is almost always the memory limit. With no
        // traceback at all the process was most likely killed the same way.
        if (($process->hasBeenSignaled() && $process->getTermSignal() === 9) || $process->getExitCode() === 137) {
            return 'أوقف الخادم محرّك التفريغ لنفاد الذاكرة غالبًا. جرّب نموذجًا أصغر (ASR_MODEL=small) أو أعد المحاولة.';
        }

        // The last line that is not a warning: Hugging Face prints harmless warnings after the real error.
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $process->getErrorOutput())),
            fn (string $line): bool => $line !== '' && ! preg_match('/^(warning|warnings\.warn|\S*Warning:)/i', $line),
        ));
        $last = $lines === [] ? 'توقف دون رسالة خطأ.' : end($lines);
        if (str_contains($last, 'MemoryError') || str_contains($last, 'std::bad_alloc')) {
            return 'نفدت ذاكرة الخادم أثناء التفريغ. جرّب نموذجًا أصغر (ASR_MODEL=small) أو أعد المحاولة.';
        }
        if (Str::contains($last, ['ConnectionError', 'HTTPSConnection', 'Max retries', 'huggingface'], true)) {
            return 'تعذّر تنزيل نموذج التفريغ إلى الخادم (اتصال الإنترنت). أعد المحاولة لاحقًا.';
        }

        return 'تعذّر التفريغ الآلي: '.Str::limit($last, 300);
    }
}
