<?php

namespace Tests\Unit;

use App\Services\Asr\WhisperTranscriber;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WhisperTranscriberTest extends TestCase
{
    private function messageFor(string $stderr, int $exit = 1): string
    {
        $process = new Process([PHP_BINARY, '-r', 'fwrite(STDERR, base64_decode($argv[1])); exit((int) $argv[2]);', base64_encode($stderr), (string) $exit]);
        $process->run();

        $method = new ReflectionMethod(WhisperTranscriber::class, 'failureMessage');

        return $method->invoke(new WhisperTranscriber(null, 'small', 1, 60), $process);
    }

    public function test_the_real_error_is_shown_not_the_warning_printed_after_it(): void
    {
        $message = $this->messageFor("Traceback (most recent call last):\nPermissionError: [Errno 13] Permission denied: '/x'\nWarning: You are sending unauthenticated requests to the HF Hub.\n");

        $this->assertStringContainsString('PermissionError', $message);
        $this->assertStringNotContainsString('unauthenticated', $message);
    }

    public function test_a_process_killed_for_memory_says_so(): void
    {
        $this->assertStringContainsString('ذاكرة', $this->messageFor("Warning: something\n", 137));
        $this->assertStringContainsString('ذاكرة', $this->messageFor("MemoryError\n"));
    }

    public function test_a_failed_model_download_says_so(): void
    {
        $this->assertStringContainsString('تنزيل', $this->messageFor("requests.exceptions.ConnectionError: HTTPSConnectionPool(host='huggingface.co')\n"));
    }

    public function test_an_unwritable_model_folder_falls_back_to_the_default_cache(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mofaregh-');   // a file cannot hold a folder
        $blocked = new WhisperTranscriber(null, 'small', 1, 60, $file.'/models');
        $this->assertNull($blocked->usableModelDir());
        $this->assertStringContainsString('cannot create it inside', (string) $blocked->modelDirProblem());

        $dir = sys_get_temp_dir().'/mofaregh-models-'.uniqid();
        $this->assertSame($dir, (new WhisperTranscriber(null, 'small', 1, 60, $dir))->usableModelDir());
        $this->assertDirectoryExists($dir);

        @rmdir($dir);
        @unlink($file);
    }
}
