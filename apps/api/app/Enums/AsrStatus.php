<?php

namespace App\Enums;

/** Lifecycle of the automatic draft of one audio file. */
enum AsrStatus: string
{
    case None = 'none';
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
