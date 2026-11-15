<?php

namespace App\Http\Resources;

use App\Enums\AudioStatus;
use App\Models\AudioFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AudioFile */
class AudioFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'original_name' => $this->original_name,
            'size_bytes' => $this->size_bytes,
            'received_bytes' => $this->received_bytes,
            'status' => $this->status->value,
            'duration_seconds' => $this->duration_seconds,
            'error' => $this->error,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader
                ? ['id' => $this->uploader->id, 'name' => $this->uploader->name]
                : null),
            'stream_url' => $this->status === AudioStatus::Ready ? "/api/audio-files/{$this->id}/stream" : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
