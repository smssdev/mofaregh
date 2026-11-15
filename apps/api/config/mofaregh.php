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

];
