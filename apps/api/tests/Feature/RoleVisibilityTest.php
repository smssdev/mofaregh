<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 1 acceptance: every role signs in and sees only what belongs to it.
 */
class RoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $otherManager;

    private User $transcriber;

    private User $linguist;

    private User $sharia;

    private Project $mine;

    private Project $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->role(Role::ProjectManager)->create();
        $this->otherManager = User::factory()->role(Role::ProjectManager)->create();
        $this->transcriber = User::factory()->role(Role::Transcriber)->create();
        $this->linguist = User::factory()->role(Role::LinguisticReviewer)->create();
        $this->sharia = User::factory()->role(Role::ShariaChecker)->create();

        $this->mine = Project::factory()->create(['name' => 'mine', 'manager_id' => $this->manager->id]);
        $this->theirs = Project::factory()->create(['name' => 'theirs', 'manager_id' => $this->otherManager->id]);
        $this->mine->members()->attach([$this->transcriber->id, $this->linguist->id]);
        $this->theirs->members()->attach([$this->sharia->id]);
    }

    /** @return list<string> */
    private function projectNamesFor(User $user): array
    {
        return collect($this->actingAs($user, 'web')->getJson('/api/projects')->assertOk()->json())
            ->pluck('name')->sort()->values()->all();
    }

    public function test_each_role_sees_only_its_projects(): void
    {
        $this->assertSame(['mine', 'theirs'], $this->projectNamesFor($this->admin));
        $this->assertSame(['mine'], $this->projectNamesFor($this->manager));
        $this->assertSame(['theirs'], $this->projectNamesFor($this->otherManager));
        $this->assertSame(['mine'], $this->projectNamesFor($this->transcriber));
        $this->assertSame(['mine'], $this->projectNamesFor($this->linguist));
        $this->assertSame(['theirs'], $this->projectNamesFor($this->sharia));
    }

    public function test_opening_someone_elses_project_is_forbidden(): void
    {
        $this->actingAs($this->transcriber, 'web')->getJson("/api/projects/{$this->theirs->id}")->assertForbidden();
        $this->actingAs($this->manager, 'web')->getJson("/api/projects/{$this->theirs->id}")->assertForbidden();
        $this->actingAs($this->transcriber, 'web')->getJson("/api/projects/{$this->theirs->id}/audio-files")->assertForbidden();

        $this->actingAs($this->transcriber, 'web')->getJson("/api/projects/{$this->mine->id}")->assertOk()
            ->assertJsonPath('can_manage', false);
        $this->actingAs($this->manager, 'web')->getJson("/api/projects/{$this->mine->id}")->assertOk()
            ->assertJsonPath('can_manage', true);
    }

    public function test_only_admins_and_managers_create_projects(): void
    {
        $this->actingAs($this->transcriber, 'web')->postJson('/api/projects', ['name' => 'x'])->assertForbidden();

        // A manager always runs what they create, whatever manager_id says.
        $this->actingAs($this->manager, 'web')
            ->postJson('/api/projects', ['name' => 'new', 'manager_id' => $this->otherManager->id])
            ->assertCreated()
            ->assertJsonPath('manager.id', $this->manager->id);

        $this->actingAs($this->admin, 'web')
            ->postJson('/api/projects', ['name' => 'assigned', 'manager_id' => $this->otherManager->id])
            ->assertCreated()
            ->assertJsonPath('manager.id', $this->otherManager->id);

        // Only a project manager can be made manager.
        $this->actingAs($this->admin, 'web')
            ->postJson('/api/projects', ['name' => 'bad', 'manager_id' => $this->transcriber->id])
            ->assertStatus(422);
    }

    public function test_a_manager_edits_only_their_projects_and_only_admins_delete(): void
    {
        $this->actingAs($this->manager, 'web')->patchJson("/api/projects/{$this->mine->id}", ['name' => 'renamed'])->assertOk();
        $this->actingAs($this->manager, 'web')->patchJson("/api/projects/{$this->theirs->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->linguist, 'web')->patchJson("/api/projects/{$this->mine->id}", ['name' => 'x'])->assertForbidden();

        $this->actingAs($this->manager, 'web')->deleteJson("/api/projects/{$this->mine->id}")->assertForbidden();
        $this->actingAs($this->admin, 'web')->deleteJson("/api/projects/{$this->mine->id}")->assertNoContent();
    }

    public function test_members_are_limited_to_active_working_roles(): void
    {
        $inactive = User::factory()->role(Role::Transcriber)->inactive()->create();

        $this->actingAs($this->manager, 'web')
            ->putJson("/api/projects/{$this->mine->id}/members", ['user_ids' => [$this->sharia->id]])
            ->assertOk()
            ->assertJsonCount(1, 'members');

        foreach ([$this->otherManager->id, $this->admin->id, $inactive->id] as $invalid) {
            $this->actingAs($this->manager, 'web')
                ->putJson("/api/projects/{$this->mine->id}/members", ['user_ids' => [$invalid]])
                ->assertStatus(422);
        }

        $this->actingAs($this->transcriber, 'web')
            ->putJson("/api/projects/{$this->mine->id}/members", ['user_ids' => []])
            ->assertForbidden();
    }

    public function test_user_management_is_for_admins(): void
    {
        $this->actingAs($this->transcriber, 'web')->getJson('/api/users')->assertForbidden();
        $this->actingAs($this->manager, 'web')->postJson('/api/users', [])->assertForbidden();

        // Managers see the people they can add to projects, without their e-mail addresses.
        $list = $this->actingAs($this->manager, 'web')->getJson('/api/users')->assertOk()->json();
        $this->assertEqualsCanonicalizing(
            [$this->transcriber->id, $this->linguist->id, $this->sharia->id],
            array_column($list, 'id'),
        );
        $this->assertArrayNotHasKey('email', $list[0]);

        $this->actingAs($this->admin, 'web')->postJson('/api/users', [
            'name' => 'مراجع جديد',
            'email' => 'New@Example.com',
            'role' => 'linguistic_reviewer',
            'password' => 'long-enough-1',
        ])->assertCreated()->assertJsonPath('email', 'new@example.com');
    }

    public function test_an_admin_cannot_lock_themselves_out(): void
    {
        $this->actingAs($this->admin, 'web')->patchJson("/api/users/{$this->admin->id}", ['role' => 'transcriber'])->assertStatus(422);
        $this->actingAs($this->admin, 'web')->patchJson("/api/users/{$this->admin->id}", ['is_active' => false])->assertStatus(422);
        $this->actingAs($this->admin, 'web')->deleteJson("/api/users/{$this->admin->id}")->assertForbidden();
    }
}
