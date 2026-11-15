<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $project->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::ProjectManager);
    }

    public function update(User $user, Project $project): bool
    {
        return $project->isManageableBy($user);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $project->isManageableBy($user);
    }

    public function uploadAudio(User $user, Project $project): bool
    {
        return $project->isManageableBy($user) && $project->status === 'active';
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isAdmin();
    }
}
