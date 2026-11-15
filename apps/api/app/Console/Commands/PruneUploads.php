<?php

namespace App\Console\Commands;

use App\Enums\AudioStatus;
use App\Models\AudioFile;
use Illuminate\Console\Command;

class PruneUploads extends Command
{
    protected $signature = 'mofaregh:prune-uploads {--days=7}';

    protected $description = 'Delete uploads that stopped before the last byte more than N days ago';

    public function handle(): int
    {
        $count = 0;
        AudioFile::where('status', AudioStatus::Uploading)
            ->where('updated_at', '<', now()->subDays((int) $this->option('days')))
            ->each(function (AudioFile $audio) use (&$count) {
                $audio->deleteStoredFiles();
                $audio->delete();
                $count++;
            });

        $this->info("Pruned {$count} abandoned upload(s).");

        return self::SUCCESS;
    }
}
