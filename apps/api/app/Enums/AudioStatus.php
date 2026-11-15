<?php

namespace App\Enums;

/**
 * Lifecycle of an uploaded audio file:
 * uploading -> uploaded (all bytes received) -> processing (ffmpeg) -> ready | failed.
 */
enum AudioStatus: string
{
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
