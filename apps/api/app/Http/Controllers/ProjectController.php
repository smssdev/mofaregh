<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = Project::query()
            ->visibleTo($request->user())
            ->with('manager')
            ->withCount('audioFiles')
            ->latest()
            ->get();

        return ProjectResource::collection($projects);
    }

    public function store(Request $request): ProjectResource
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'manager_id' => ['nullable', 'integer', $this->managerRule()],
        ]);

        $user = $request->user();
        // A project manager always runs the projects they create; an admin may hand them to a manager.
        $data['manager_id'] = $user->isAdmin() ? ($data['manager_id'] ?? null) : $user->id;
        $data['created_by'] = $user->id;

        $project = Project::create($data);

        return ProjectResource::make($project->load('manager', 'members')->loadCount('audioFiles'));
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return ProjectResource::make($project->load('manager', 'members')->loadCount('audioFiles'));
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'manager_id' => ['sometimes', 'nullable', 'integer', $this->managerRule()],
        ]);

        if (array_key_exists('manager_id', $data) && ! $request->user()->isAdmin()) {
            unset($data['manager_id']);
        }

        $project->update($data);

        return ProjectResource::make($project->load('manager', 'members')->loadCount('audioFiles'));
    }

    public function destroy(Project $project): Response
    {
        $this->authorize('delete', $project);

        $project->audioFiles()->each(fn ($audio) => $audio->deleteStoredFiles());
        $project->delete();

        return response()->noContent();
    }

    /** Replaces the member list. Only active transcribers and reviewers can be members. */
    public function syncMembers(Request $request, Project $project): ProjectResource
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $ids = collect($data['user_ids'])->unique()->values();
        $valid = User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereIn('role', [Role::Transcriber, Role::LinguisticReviewer, Role::ShariaChecker])
            ->pluck('id');

        if ($valid->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'user_ids' => 'يمكن إضافة المفرِّغين والمراجعين والمدققين النشطين فقط.',
            ]);
        }

        DB::transaction(fn () => $project->members()->sync($valid->all()));

        return ProjectResource::make($project->load('manager', 'members')->loadCount('audioFiles'));
    }

    private function managerRule(): Exists
    {
        return Rule::exists('users', 'id')->where('role', Role::ProjectManager->value)->where('is_active', true);
    }
}
