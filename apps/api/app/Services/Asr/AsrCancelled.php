<?php

namespace App\Services\Asr;

use RuntimeException;

/** The engine was stopped on request (the user pressed stop, or the file was deleted meanwhile). */
class AsrCancelled extends RuntimeException {}
