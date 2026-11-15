<?php

namespace App\Http\Controllers;

use App\Enums\AudioStatus;
use App\Http\Resources\AudioFileResource;
use App\Jobs\NormalizeAudio;
use App\Models\AudioFile;
use App\Models\Project;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Resumable uploads, modelled on the tus "core" protocol (https://tus.io) but kept to three calls:
 *
 *   POST  /api/projects/{project}/audio-files   declare name + size, get an id
 *   GET   /api/audio-files/{id}/upload          how many bytes the server holds (the offset)
 *   PATCH /api/audio-files/{id}/upload          Upload-Offset: <n>, raw bytes appended at n
 *
 * The bytes on disk are the single source of truth for the offset. A PATCH whose Upload-Offset does not match
 * gets 409 with the real offset, so a client that lost a response simply continues from there. When the last
 * byte arrives the file moves to the audio disk and normalization is queued.
 */
class AudioUploadController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('uploadAudio', $project);

        $config = config('mofaregh.audio');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.$config['max_bytes']],
            'mime_type' => ['nullable', 'string', 'max:128'],
        ], [
            'size.max' => 'حجم الملف أكبر من الحد المسموح.',
        ]);

        $extension = strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, $config['extensions'], true)) {
            throw ValidationException::withMessages([
                'name' => 'نوع الملف غير مدعوم. الأنواع المقبولة: '.implode('، ', $config['extensions']),
            ]);
        }

        $audio = $project->audioFiles()->create([
            'uploaded_by' => $request->user()->id,
            'original_name' => basename(str_replace('\\', '/', $data['name'])),
            'mime_type' => $data['mime_type'] ?? null,
            'size_bytes' => $data['size'],
            'received_bytes' => 0,
            'status' => AudioStatus::Uploading,
            'disk' => $config['disk'],
        ]);

        Storage::disk('local')->put($audio->partialPath(), '');

        return AudioFileResource::make($audio->load('uploader'))->response()->setStatusCode(201);
    }

    public function offset(AudioFile $audioFile): JsonResponse
    {
        $this->authorize('upload', $audioFile);

        return response()->json([
            'offset' => $this->currentOffset($audioFile),
            'size' => $audioFile->size_bytes,
            'status' => $audioFile->status->value,
        ])->header('Cache-Control', 'no-store');
    }

    public function append(Request $request, AudioFile $audioFile): JsonResponse
    {
        $this->authorize('upload', $audioFile);

        if (! str_starts_with((string) $request->header('Content-Type'), 'application/offset+octet-stream')) {
            abort(415, 'Content-Type must be application/offset+octet-stream.');
        }

        $offsetHeader = $request->header('Upload-Offset');
        if ($offsetHeader === null || ! ctype_digit((string) $offsetHeader)) {
            abort(400, 'Upload-Offset header is required.');
        }

        try {
            return Cache::lock("audio-upload:{$audioFile->id}", 120)->block(10, function () use ($request, $audioFile, $offsetHeader) {
                $audioFile->refresh();
                $current = $this->currentOffset($audioFile);

                if ($audioFile->status !== AudioStatus::Uploading || (int) $offsetHeader !== $current) {
                    return response()->json(['offset' => $current, 'message' => 'Offset mismatch.'], 409);
                }

                $written = $this->appendBody($request, $audioFile, $current);
                $offset = $current + $written;
                $audioFile->update(['received_bytes' => $offset]);

                if ($offset === $audioFile->size_bytes) {
                    $this->complete($audioFile);
                }

                return response()->json([
                    'offset' => $offset,
                    'status' => $audioFile->status->value,
                ]);
            });
        } catch (LockTimeoutException) {
            // Another request is still writing this upload: let the client ask for the offset again.
            return response()->json(['offset' => $this->currentOffset($audioFile), 'message' => 'Busy.'], 409);
        }
    }

    private function currentOffset(AudioFile $audioFile): int
    {
        if ($audioFile->status !== AudioStatus::Uploading) {
            return $audioFile->size_bytes;
        }

        $path = Storage::disk('local')->path($audioFile->partialPath());
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /** Appends the request body at $offset and returns how many bytes were written. */
    private function appendBody(Request $request, AudioFile $audioFile, int $offset): int
    {
        $remaining = $audioFile->size_bytes - $offset;
        $limit = min($remaining, (int) config('mofaregh.audio.max_chunk_bytes'));
        $path = Storage::disk('local')->path($audioFile->partialPath());

        $input = $request->getContent(true);
        $output = fopen($path, 'cb');
        if ($output === false) {
            abort(500, 'Cannot open the upload file.');
        }

        try {
            fseek($output, $offset);
            // Copy one byte past the limit so an oversized chunk is detected instead of silently cut.
            $written = (int) stream_copy_to_stream($input, $output, $limit + 1);
            if ($written > $limit) {
                ftruncate($output, $offset);
                abort(413, 'Chunk is larger than the remaining file or the chunk limit.');
            }
            fflush($output);
        } finally {
            fclose($output);
            if (is_resource($input)) {
                fclose($input);
            }
        }

        return $written;
    }

    private function complete(AudioFile $audioFile): void
    {
        $target = "originals/{$audioFile->id}.{$audioFile->extension()}";
        $local = Storage::disk('local');
        $disk = Storage::disk($audioFile->disk);

        if (config("filesystems.disks.{$audioFile->disk}.driver") === 'local') {
            $destination = $disk->path($target);
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0775, true);
            }
            rename($local->path($audioFile->partialPath()), $destination);
        } else {
            $stream = $local->readStream($audioFile->partialPath());
            $disk->writeStream($target, $stream);
            is_resource($stream) && fclose($stream);
            $local->delete($audioFile->partialPath());
        }

        $audioFile->update([
            'status' => AudioStatus::Uploaded,
            'original_path' => $target,
            'upload_completed_at' => now(),
        ]);

        NormalizeAudio::dispatch($audioFile);
    }
}
