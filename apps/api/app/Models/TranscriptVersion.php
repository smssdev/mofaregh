<?php

namespace App\Models;

use App\Services\Export\EditorText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved state of a transcript (see the transcript_versions migration for when rows are written).
 *
 * @property array<string, mixed>|null $content
 */
#[Fillable(['transcript_id', 'version', 'reason', 'title', 'content', 'words', 'created_by'])]
class TranscriptVersion extends Model
{
    /** While the same person keeps editing within this many minutes, their latest snapshot is refreshed. */
    public const WINDOW_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'version' => 'integer',
            'words' => 'integer',
        ];
    }

    /** @param  array<string, mixed>|null  $doc */
    public static function wordsIn(?array $doc): int
    {
        $count = 0;
        foreach (EditorText::paragraphs($doc) as $paragraph) {
            $count += count(preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        return $count;
    }

    /**
     * Records the transcript's current state: refreshes the editor's own recent snapshot, or adds a new one.
     */
    public static function record(Transcript $transcript, ?int $userId, string $reason = 'edit'): self
    {
        $values = [
            'version' => $transcript->version,
            'title' => $transcript->title,
            'content' => $transcript->content,
            'words' => self::wordsIn($transcript->content),
        ];

        if ($reason === 'edit') {
            $latest = self::where('transcript_id', $transcript->id)->latest('id')->first();
            if ($latest && $latest->reason === 'edit' && $latest->created_by === $userId
                && $latest->created_at?->gt(now()->subMinutes(self::WINDOW_MINUTES))) {
                $latest->update($values);

                return $latest;
            }
        }

        return self::create($values + ['transcript_id' => $transcript->id, 'reason' => $reason, 'created_by' => $userId]);
    }

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
