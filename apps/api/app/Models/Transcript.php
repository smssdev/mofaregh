<?php

namespace App\Models;

use App\Enums\AsrStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The text of one audio file: the editor's document plus the state of its automatic draft.
 *
 * @property AsrStatus $asr_status
 * @property array<string, mixed>|null $content
 * @property list<array{start_ms: int, end_ms: int, text: string}>|null $asr_result
 */
#[Fillable([
    'audio_file_id', 'title', 'content', 'version', 'updated_by',
    'asr_status', 'asr_progress', 'asr_stage', 'asr_heartbeat_at', 'asr_result', 'asr_model', 'asr_error', 'asr_finished_at',
])]
class Transcript extends Model
{
    protected $attributes = [
        'version' => 0,
        'asr_status' => 'none',
        'asr_progress' => 0,
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'asr_result' => 'array',
            'asr_status' => AsrStatus::class,
            'version' => 'integer',
            'asr_progress' => 'integer',
            'asr_finished_at' => 'datetime',
            'asr_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * A draft that stopped giving signs of life: queued with no worker picking it up, or running with no progress
     * (the engine was killed, the server restarted). It may then be started again.
     */
    public function asrStalled(): bool
    {
        if (! $this->asr_status->isBusy()) {
            return false;
        }
        $last = $this->asr_heartbeat_at ?? $this->updated_at;
        $limit = $this->asr_status === AsrStatus::Queued
            ? (int) config('mofaregh.asr.queued_stall_minutes')
            : (int) config('mofaregh.asr.stall_minutes');

        return $last === null || $last->lt(now()->subMinutes($limit));
    }

    public function audioFile(): BelongsTo
    {
        return $this->belongsTo(AudioFile::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
