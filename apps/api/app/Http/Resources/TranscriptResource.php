<?php

namespace App\Http\Resources;

use App\Models\Transcript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transcript */
class TranscriptResource extends JsonResource
{
    /** Set by the controller: what this server can do, so the editor can say why a button is disabled. */
    public bool $asrAvailable = false;

    public bool $exportAvailable = false;

    public function toArray(Request $request): array
    {
        return [
            'audio_file_id' => $this->audio_file_id,
            'title' => $this->title,
            'content' => $this->content,
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor
                ? ['id' => $this->editor->id, 'name' => $this->editor->name]
                : null),
            'asr' => [
                'available' => $this->asrAvailable,
                'status' => $this->asr_status->value,
                'progress' => $this->asr_progress,
                'stage' => $this->asr_stage,
                // No sign of life for too long: the editor offers to start it again.
                'stalled' => $this->asrStalled(),
                'error' => $this->asr_error,
                'model' => $this->asr_model,
                'finished_at' => $this->asr_finished_at?->toIso8601String(),
                // The draft itself is only sent once it exists; the editor inserts it on request.
                'paragraphs' => $this->asr_result,
                // The part being (or last) transcribed; null = the whole file.
                'range' => $this->asr_range_start_ms === null ? null : ['start_ms' => $this->asr_range_start_ms, 'end_ms' => $this->asr_range_end_ms],
                // Stretches of the audio the draft already covers: [[start_ms, end_ms], …].
                'covered' => $this->asr_covered ?? [],
            ],
            'export_available' => $this->exportAvailable,
        ];
    }
}
