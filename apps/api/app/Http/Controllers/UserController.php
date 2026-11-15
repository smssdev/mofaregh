<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\MemberResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /** Admins get the full list; project managers get the active members they can add to projects. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()->orderBy('name');

        if ($request->user()->isAdmin()) {
            return UserResource::collection($query->get());
        }

        return MemberResource::collection(
            $query->where('is_active', true)
                ->whereIn('role', [Role::Transcriber, Role::LinguisticReviewer, Role::ShariaChecker])
                ->get()
        );
    }

    public function store(Request $request): UserResource
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', new Enum(Role::class)],
            'password' => ['required', 'string', 'min:10'],
        ]);
        $data['email'] = mb_strtolower($data['email']);

        return UserResource::make(User::create($data + ['is_active' => true]));
    }

    public function update(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'string', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['sometimes', new Enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:10'],
        ]);

        if ($request->user()->is($user)
            && ((isset($data['role']) && $data['role'] !== Role::Admin->value) || ($data['is_active'] ?? true) === false)) {
            throw ValidationException::withMessages(['role' => 'لا يمكنك إزالة صلاحية الإدارة أو تعطيل حسابك بنفسك.']);
        }

        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return UserResource::make($user);
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->noContent();
    }
}
