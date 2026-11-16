<?php

namespace App\Policies;

use App\Models\AudioFile;
use App\Models\User;

class AudioFilePolicy
{
    public function view(User $user, AudioFile $audioFile): bool
    {
        return $audioFile->project->isVisibleTo($user);
    }

    /** Writing the transcript, asking for an automatic draft: everyone who works in the project. */
    public function edit(User $user, AudioFile $audioFile): bool
    {
        return $audioFile->project->isVisibleTo($user);
    }

    /** Sending bytes to (or asking the offset of) an unfinished upload. */
    public function upload(User $user, AudioFile $audioFile): bool
    {
        return $audioFile->project->isManageableBy($user);
    }

    public function delete(User $user, AudioFile $audioFile): bool
    {
        return $audioFile->project->isManageableBy($user);
    }
}
