<?php

return [

    'audio' => [
        // Disk that keeps the original and the normalized audio. Unfinished uploads always live on "local".
        'disk' => env('AUDIO_DISK', 'audio'),

        // Largest file accepted (default 2 GB; Sprint 1 asks for at least 500 MB).
        'max_bytes' => (int) env('AUDIO_MAX_BYTES', 2 * 1024 * 1024 * 1024),

        // Largest single PATCH body. The web client sends 8 MB chunks; keep this under nginx/PHP limits.
        'max_chunk_bytes' => (int) env('AUDIO_MAX_CHUNK_BYTES', 16 * 1024 * 1024),

        'extensions' => ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wma', 'amr', 'webm', 'mp4', 'mkv', 'mov', '3gp'],

        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),

        // Seconds ffmpeg may run on one file (a 3-hour lecture takes well under this on one core).
        'timeout' => (int) env('AUDIO_NORMALIZE_TIMEOUT', 1800),
    ],

    // Python of the server's virtualenv (shared/venv/bin/python), which has services/text-engine and
    // services/asr installed. deploy/activate.sh creates it and writes this setting; empty = both features off.
    'python' => env('MOFAREGH_PYTHON'),

    'asr' => [
        // faster-whisper model: small (fast, weaker), medium (default), large-v3 (best, heavy on a CPU).
        'model' => env('ASR_MODEL', 'medium'),
        // CPU threads Whisper may use. The VPS is shared with other sites: keep this below the core count.
        'threads' => (int) env('ASR_THREADS', 2),
        // Seconds one file may take (default 6 hours: a long lecture on two threads).
        'timeout' => (int) env('ASR_TIMEOUT', 6 * 3600),
    ],

];
