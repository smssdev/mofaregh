<?php

namespace App\Services\Export;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Pipes the paragraphs to `python -m mofaregh_text.cli export-docx` and returns the .docx it prints. */
class TextEngineDocxExporter implements DocxExporter
{
    public function __construct(private readonly ?string $python) {}

    public function isAvailable(): bool
    {
        return $this->python !== null && $this->python !== '' && is_file($this->python);
    }

    public function export(string $title, array $paragraphs): string
    {
        if (! $this->isAvailable()) {
            throw new ExportFailed('محرّك التصدير غير مثبّت على الخادم.');
        }

        $process = new Process([$this->python, '-m', 'mofaregh_text.cli', 'export-docx'], timeout: 120);
        $process->setInput(json_encode(
            ['title' => $title, 'paragraphs' => array_values($paragraphs)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $process->run();

        $bytes = $process->getOutput();
        if (! $process->isSuccessful() || ! str_starts_with($bytes, 'PK')) {
            throw new ExportFailed('تعذّر إنشاء ملف Word: '.Str::limit(trim($process->getErrorOutput()), 300));
        }

        return $bytes;
    }
}
