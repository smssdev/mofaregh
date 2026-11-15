<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'status', 'manager_id', 'created_by'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function audioFiles(): HasMany
    {
        return $this->hasMany(AudioFile::class);
    }

    /**
     * The projects a user may see: all of them for an admin, the ones they manage for a project manager,
     * and the ones they are a member of for everyone else.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            Role::Admin => null,
            Role::ProjectManager => $query->where('manager_id', $user->id),
            default => $query->whereHas('members', fn (Builder $q) => $q->whereKey($user->id)),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return match ($user->role) {
            Role::Admin => true,
            Role::ProjectManager => $this->manager_id === $user->id,
            default => $this->members()->whereKey($user->id)->exists(),
        };
    }

    public function isManageableBy(User $user): bool
    {
        return $user->isAdmin() || ($user->role === Role::ProjectManager && $this->manager_id === $user->id);
    }
}
