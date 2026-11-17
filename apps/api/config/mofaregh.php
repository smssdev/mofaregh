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
        // faster-whisper model. small (default) needs about 1 GB of memory and suits the current VPS (1 core,
        // about 2 GB free); medium is more accurate but needs about 2 GB and is several times slower; large-v3 needs
        // a bigger server. mofaregh:asr-check prints the memory and cores available.
        'model' => env('ASR_MODEL', 'small'),
        // CPU threads Whisper may use. The VPS is shared with other sites: keep this below the core count.
        'threads' => (int) env('ASR_THREADS', 2),
        // Beam search width: 5 is the most accurate; 2 is about twice as fast with a small loss, worth it when a
        // long lecture on one core would otherwise run past ASR_TIMEOUT.
        'beam_size' => (int) env('ASR_BEAM_SIZE', 5),
        // Seconds one file may take (default 6 hours: a long lecture on two threads).
        'timeout' => (int) env('ASR_TIMEOUT', 6 * 3600),
        // Where Whisper models are downloaded, so every release reuses them (medium is about 1.5 GB). On the server
        // deploy/activate.sh creates shared/asr-models and writes ASR_MODEL_DIR into shared/.env.
        'model_dir' => env('ASR_MODEL_DIR', storage_path('app/private/asr-models')),
        // A running draft with no progress for this long is treated as dead and may be restarted. The first run
        // downloads the model before any progress, hence the margin.
        'stall_minutes' => (int) env('ASR_STALL_MINUTES', 30),
        // A queued draft that no worker picked up in this long: the queue worker is not running.
        'queued_stall_minutes' => (int) env('ASR_QUEUED_STALL_MINUTES', 5),
    ],

];
