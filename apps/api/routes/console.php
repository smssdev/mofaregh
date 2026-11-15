<?php

use Illuminate\Support\Facades\Schedule;

// Shared hosting has no supervisor: the cron-driven scheduler drains the queue every minute instead.
// A long ffmpeg job keeps its worker busy; withoutOverlapping stops a second worker from piling up behind it.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=2 --timeout=1900')
    ->everyMinute()
    ->withoutOverlapping(40)
    ->runInBackground();

// Unfinished uploads older than a week are abandoned: free their disk space.
Schedule::command('mofaregh:prune-uploads --days=7')->daily();
