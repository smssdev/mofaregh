<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Models\Transcript;
use App\Models\TranscriptVersion;
use App\Services\Export\EditorText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The history of a transcript: list its snapshots, read one, and bring one back. A restore is a normal save
 * (same version check), and the state it replaces is kept as a snapshot first, so a restore can be undone too.
 */
class TranscriptVersionController extends Controller
{
    public function index(AudioFile $audioFile): JsonResponse
    {
        $this->authorize('view', $audioFile);

        $transcript = $audioFile->transcript;
        $versions = $transcript
            ? $transcript->versions()->with('author:id,name')->latest('id')->limit(200)
                ->get(['id', 'transcript_id', 'version', 'reason', 'title', 'words', 'created_by', 'created_at', 'updated_at'])
            : collect();

        return response()->json(['data' => $versions->map(fn (TranscriptVersion $v): array => $this->summary($v))->values()]);
    }

    public function show(AudioFile $audioFile, TranscriptVersion $version): JsonResponse
    {
        $this->authorize('view', $audioFile);
        $this->ensureBelongs($audioFile, $version);

        return response()->json(['data' => $this->summary($version->load('author:id,name')) + [
            'content' => $version->content,
            'paragraphs' => EditorText::paragraphs($version->content),
        ]]);
    }

    public function restore(Request $request, AudioFile $audioFile, TranscriptVersion $version): JsonResponse
    {
        $this->authorize('edit', $audioFile);
        $this->ensureBelongs($audioFile, $version);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);

        /** @var Transcript $transcript */
        $transcript = $audioFile->transcript;
        $userId = $request->user()->id;
        $conflict = fn (): JsonResponse => response()->json([
            'message' => 'عُدِّل هذا النص من جلسة أخرى. أعد تحميل الصفحة ثم أعد المحاولة.',
            'version' => $transcript->fresh()->version,
        ], 409);

        if ($transcript->version !== (int) $data['version']) {
            return $conflict();
        }

        // What is about to be replaced stays in the history, under a "restore" row that is never merged into.
        TranscriptVersion::record($transcript, $userId, 'restore');

        $saved = Transcript::whereKey($transcript->id)->where('version', $data['version'])->update([
            'title' => $version->title ?? $transcript->title,
            'content' => $version->content === null ? null : json_encode($version->content, JSON_UNESCAPED_UNICODE),
            'version' => $data['version'] + 1,
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);

        if ($saved === 0) {
            return $conflict();
        }

        $fresh = $transcript->fresh();
        TranscriptVersion::create([
            'transcript_id' => $fresh->id, 'version' => $fresh->version, 'reason' => 'restored',
            'title' => $fresh->title, 'content' => $fresh->content,
            'words' => TranscriptVersion::wordsIn($fresh->content), 'created_by' => $userId,
        ]);

        return response()->json(['data' => ['version' => $fresh->version, 'title' => $fresh->title, 'content' => $fresh->content]]);
    }

    private function ensureBelongs(AudioFile $audioFile, TranscriptVersion $version): void
    {
        abort_unless($version->transcript_id === $audioFile->transcript?->id, 404);
    }

    /** @return array<string, mixed> */
    private function summary(TranscriptVersion $v): array
    {
        return [
            'id' => $v->id,
            'version' => $v->version,
            'reason' => $v->reason,
            'title' => $v->title,
            'words' => $v->words,
            'author' => $v->author ? ['id' => $v->author->id, 'name' => $v->author->name] : null,
            'created_at' => $v->created_at?->toIso8601String(),
            'updated_at' => $v->updated_at?->toIso8601String(),
        ];
    }
}
