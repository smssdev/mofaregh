<?php

namespace App\Services\Export;

interface DocxExporter
{
    public function isAvailable(): bool;

    /**
     * Renders the client Word layout (services/text-engine, template "client").
     *
     * @param  list<string>  $paragraphs
     * @return string the .docx bytes
     *
     * @throws ExportFailed
     */
    public function export(string $title, array $paragraphs): string;
}
