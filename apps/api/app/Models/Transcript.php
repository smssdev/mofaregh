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
    'asr_status', 'asr_progress', 'asr_result', 'asr_model', 'asr_error', 'asr_finished_at',
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
        ];
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
