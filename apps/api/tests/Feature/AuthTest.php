<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_signs_in_with_the_session_cookie(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.com', 'password' => 'correct-horse-1']);

        $this->postJson('/api/auth/login', ['email' => 'Reader@Example.com', 'password' => 'correct-horse-1'])
            ->assertOk()
            ->assertJson(['id' => $user->id, 'role' => 'transcriber', 'role_label' => 'مفرِّغ']);

        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'reader@example.com');
    }

    public function test_a_wrong_password_is_refused(): void
    {
        User::factory()->create(['email' => 'reader@example.com', 'password' => 'correct-horse-1']);

        $this->postJson('/api/auth/login', ['email' => 'reader@example.com', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
        $this->assertGuest('web');
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        User::factory()->inactive()->create(['email' => 'gone@example.com', 'password' => 'correct-horse-1']);

        $this->postJson('/api/auth/login', ['email' => 'gone@example.com', 'password' => 'correct-horse-1'])
            ->assertStatus(422);
    }

    public function test_a_disabled_account_loses_its_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/auth/me')->assertOk();

        $user->update(['is_active' => false]);

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_guests_cannot_reach_the_api(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'reader@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/auth/login', ['email' => 'reader@example.com', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'reader@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_a_user_changes_their_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);

        $this->actingAs($user, 'web')->putJson('/api/auth/password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-22',
            'password_confirmation' => 'new-password-22',
        ])->assertOk();

        $this->assertTrue(password_verify('new-password-22', $user->fresh()->password));
    }

    public function test_the_spa_shell_is_served_for_app_routes(): void
    {
        $index = public_path('spa/index.html');
        $existed = is_file($index);
        if (! $existed) {
            @mkdir(dirname($index), 0775, true);
            file_put_contents($index, '<!doctype html><div id="app"></div>');
        }

        try {
            $this->get('/projects/12')->assertOk()->assertSee('id="app"', false);
            $this->getJson('/api/nope')->assertNotFound();
        } finally {
            if (! $existed) {
                unlink($index);
            }
        }
    }
}
