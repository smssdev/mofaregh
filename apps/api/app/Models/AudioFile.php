<?php

namespace App\Models;

use App\Enums\AudioStatus;
use Database\Factories\AudioFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * @property AudioStatus $status
 */
#[Fillable([
    'project_id', 'uploaded_by', 'original_name', 'mime_type', 'size_bytes', 'received_bytes', 'status',
    'disk', 'original_path', 'normalized_path', 'duration_seconds', 'error', 'upload_completed_at', 'processed_at',
])]
class AudioFile extends Model
{
    /** @use HasFactory<AudioFileFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'uploading',
        'received_bytes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => AudioStatus::class,
            'size_bytes' => 'integer',
            'received_bytes' => 'integer',
            'duration_seconds' => 'float',
            'upload_completed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Where the bytes of an unfinished upload are appended (always on the local disk, then moved). */
    public function partialPath(): string
    {
        return "uploads/partial/{$this->id}.part";
    }

    public function extension(): string
    {
        $extension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) ? $extension : 'bin';
    }

    public function deleteStoredFiles(): void
    {
        Storage::disk('local')->delete($this->partialPath());
        $disk = Storage::disk($this->disk);
        foreach (array_filter([$this->original_path, $this->normalized_path]) as $path) {
            $disk->delete($path);
        }
    }
}
