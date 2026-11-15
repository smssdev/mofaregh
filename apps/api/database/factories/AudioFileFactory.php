<?php

namespace Database\Factories;

use App\Enums\AudioStatus;
use App\Models\AudioFile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudioFile>
 */
class AudioFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'original_name' => 'lecture.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 1000,
            'received_bytes' => 0,
            'status' => AudioStatus::Uploading,
            'disk' => 'audio',
        ];
    }
}
