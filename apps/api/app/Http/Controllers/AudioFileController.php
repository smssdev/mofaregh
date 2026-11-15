<?php

namespace App\Http\Controllers;

use App\Enums\AudioStatus;
use App\Http\Resources\AudioFileResource;
use App\Jobs\NormalizeAudio;
use App\Models\AudioFile;
use App\Models\Project;
use App\Services\Audio\AudioNormalizer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioFileController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return AudioFileResource::collection(
            $project->audioFiles()->with('uploader')->latest()->get()
        );
    }

    public function show(AudioFile $audioFile): AudioFileResource
    {
        $this->authorize('view', $audioFile);

        return AudioFileResource::make($audioFile->load('uploader'));
    }

    /**
     * Streams the normalized file. BinaryFileResponse answers Range requests, so the player can seek.
     */
    public function stream(AudioFile $audioFile, AudioNormalizer $normalizer): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('view', $audioFile);
        abort_unless($audioFile->status === AudioStatus::Ready && $audioFile->normalized_path, 404);

        $disk = Storage::disk($audioFile->disk);
        $headers = ['Content-Type' => $normalizer->mimeType(), 'Cache-Control' => 'private, max-age=3600'];

        if (config("filesystems.disks.{$audioFile->disk}.driver") === 'local') {
            return response()->file($disk->path($audioFile->normalized_path), $headers);
        }

        return $disk->response($audioFile->normalized_path, null, $headers);
    }

    /** Queues normalization again for a file that failed (e.g. ffmpeg was missing). */
    public function retry(AudioFile $audioFile): AudioFileResource
    {
        $this->authorize('upload', $audioFile);
        abort_unless($audioFile->status === AudioStatus::Failed && $audioFile->original_path, 409);

        $audioFile->update(['status' => AudioStatus::Uploaded, 'error' => null]);
        NormalizeAudio::dispatch($audioFile);

        return AudioFileResource::make($audioFile->load('uploader'));
    }

    public function destroy(AudioFile $audioFile): Response
    {
        $this->authorize('delete', $audioFile);

        $audioFile->deleteStoredFiles();
        $audioFile->delete();

        return response()->noContent();
    }
}
