<?php

namespace App\Http\Controllers;

use App\Enums\AsrStatus;
use App\Enums\AudioStatus;
use App\Http\Resources\TranscriptResource;
use App\Jobs\TranscribeAudio;
use App\Models\AudioFile;
use App\Models\Transcript;
use App\Services\Asr\Transcriber;
use App\Services\Export\DocxExporter;
use App\Services\Export\EditorText;
use App\Services\Export\ExportFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The editor's backend: one transcript per audio file, saved with a version check, an automatic draft on
 * request, and Word export through the client template.
 */
class TranscriptController extends Controller
{
    public function __construct(
        private readonly Transcriber $transcriber,
        private readonly DocxExporter $exporter,
    ) {}

    public function show(AudioFile $audioFile): TranscriptResource
    {
        $this->authorize('view', $audioFile);

        return $this->resource($this->transcriptOf($audioFile));
    }

    public function update(Request $request, AudioFile $audioFile): TranscriptResource|JsonResponse
    {
        $this->authorize('edit', $audioFile);

        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'title' => ['nullable', 'string', 'max:190'],
            'content' => ['present', 'nullable', 'array'],
            'content.type' => ['required_with:content', 'in:doc'],
        ]);

        $transcript = $this->transcriptOf($audioFile);
        // validate() keeps only the keys that have rules, which would strip the document down to its "type".
        $content = $request->input('content');

        // Compare-and-set: only the save that read the current version wins, the other is told to reload.
        $saved = Transcript::whereKey($transcript->id)->where('version', $data['version'])->update([
            'title' => $data['title'] ?? $transcript->title,
            'content' => $content === null ? null : json_encode($content, JSON_UNESCAPED_UNICODE),
            'version' => $data['version'] + 1,
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ]);

        if ($saved === 0) {
            return response()->json([
                'message' => 'عُدِّل هذا النص من جلسة أخرى. أعد تحميل الصفحة قبل المتابعة حتى لا يضيع عمل أحد.',
                'version' => $transcript->fresh()->version,
            ], 409);
        }

        return $this->resource($transcript->fresh());
    }

    /** Queues the automatic draft. The result lands in asr.paragraphs; the editor decides where to put it. */
    public function transcribe(AudioFile $audioFile): TranscriptResource|JsonResponse
    {
        $this->authorize('edit', $audioFile);
        abort_unless($audioFile->status === AudioStatus::Ready && $audioFile->normalized_path, 409, 'الملف الصوتي غير جاهز بعد.');

        if (! $this->transcriber->isAvailable()) {
            return response()->json(['message' => 'محرّك التفريغ الآلي غير مثبّت على الخادم بعد.'], 503);
        }

        $transcript = $this->transcriptOf($audioFile);
        if ($transcript->asr_status->isBusy()) {
            return response()->json(['message' => 'التفريغ الآلي لهذا الملف يعمل الآن.'], 409);
        }

        $transcript->update(['asr_status' => AsrStatus::Queued, 'asr_progress' => 0, 'asr_error' => null]);
        TranscribeAudio::dispatch($audioFile);

        return $this->resource($transcript);
    }

    public function export(Request $request, AudioFile $audioFile): Response|JsonResponse
    {
        $this->authorize('view', $audioFile);

        $transcript = $this->transcriptOf($audioFile);
        $paragraphs = EditorText::paragraphs($transcript->content);
        if ($paragraphs === []) {
            return response()->json(['message' => 'لا يوجد نص للتصدير بعد.'], 422);
        }

        try {
            $bytes = $this->exporter->export($this->titleOf($transcript, $audioFile), $paragraphs);
        } catch (ExportFailed $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $name = Str::of($this->titleOf($transcript, $audioFile))->replaceMatches('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ')->squish()->limit(120, '');
        $fallback = "transcript-{$audioFile->id}.docx";

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => "attachment; filename=\"{$fallback}\"; filename*=UTF-8''".rawurlencode($name.'.docx'),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function transcriptOf(AudioFile $audioFile): Transcript
    {
        // fresh(): a just-created model would otherwise turn every response into a 201.
        return Transcript::firstOrCreate(['audio_file_id' => $audioFile->id])->fresh();
    }

    private function titleOf(Transcript $transcript, AudioFile $audioFile): string
    {
        return $transcript->title ?: pathinfo($audioFile->original_name, PATHINFO_FILENAME);
    }

    private function resource(Transcript $transcript): TranscriptResource
    {
        $resource = TranscriptResource::make($transcript->load('editor'));
        $resource->asrAvailable = $this->transcriber->isAvailable();
        $resource->exportAvailable = $this->exporter->isAvailable();

        return $resource;
    }
}
