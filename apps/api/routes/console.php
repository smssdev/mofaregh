<?php

use Illuminate\Support\Facades\Schedule;

// Shared hosting has no supervisor: the cron-driven scheduler drains the queue every minute instead.
// A long ffmpeg job keeps its worker busy; withoutOverlapping stops a second worker from piling up behind it.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=2 --timeout=1900')
    ->everyMinute()
    ->withoutOverlapping(40)
    ->runInBackground();

// The automatic draft (Whisper on the CPU) can run for hours: its own worker, one file at a time, and a lock
// that outlives the longest job so the next minute's run does not start a second Whisper beside it.
Schedule::command('queue:work asr --stop-when-empty --tries=1 --timeout='.((int) config('mofaregh.asr.timeout') + 180))
    ->everyMinute()
    ->withoutOverlapping((int) ceil(config('mofaregh.asr.timeout') / 60) + 10)
    ->runInBackground();

// Unfinished uploads older than a week are abandoned: free their disk space.
Schedule::command('mofaregh:prune-uploads --days=7')->daily();
