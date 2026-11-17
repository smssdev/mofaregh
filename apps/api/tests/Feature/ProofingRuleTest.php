<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ProofingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProofingRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipped_dictionary_is_seeded_and_everyone_can_read_it(): void
    {
        $this->assertDatabaseHas('proofing_rules', ['type' => 'word', 'pattern' => 'الى', 'replacement' => 'إلى']);
        $this->assertSame(5, ProofingRule::where('type', 'builtin')->count());

        $this->actingAs(User::factory()->role(Role::Transcriber)->create())
            ->getJson('/api/proofing-rules')
            ->assertOk()
            ->assertJsonPath('can_edit', false)
            ->assertJsonFragment(['pattern' => 'هاذا', 'replacement' => 'هذا']);
    }

    public function test_a_transcriber_cannot_change_it(): void
    {
        $this->actingAs(User::factory()->role(Role::Transcriber)->create())
            ->postJson('/api/proofing-rules', ['type' => 'word', 'pattern' => 'مسئله', 'replacement' => 'مسألة'])
            ->assertForbidden();
    }

    public function test_a_reviewer_adds_edits_and_deletes_a_rule(): void
    {
        $reviewer = User::factory()->role(Role::LinguisticReviewer)->create();
        $this->actingAs($reviewer);

        $id = $this->postJson('/api/proofing-rules', ['type' => 'word', 'pattern' => '  مسئله ', 'replacement' => 'مسألة', 'message' => 'همزة'])
            ->assertCreated()
            ->assertJsonPath('data.pattern', 'مسئله')
            ->assertJsonPath('data.prefixes', true)
            ->assertJsonPath('data.updated_by', $reviewer->name)
            ->json('data.id');

        $this->postJson('/api/proofing-rules', ['type' => 'word', 'pattern' => 'مسئله', 'replacement' => 'مسألة'])
            ->assertUnprocessable()->assertJsonValidationErrors('pattern');
        $this->postJson('/api/proofing-rules', ['type' => 'word', 'pattern' => 'كلمتان هنا', 'replacement' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('pattern');
        $this->postJson('/api/proofing-rules', ['type' => 'phrase', 'pattern' => 'عين ه', 'replacement' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');

        // Switching it off keeps everything else.
        $this->putJson("/api/proofing-rules/{$id}", ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.replacement', 'مسألة');

        $this->deleteJson("/api/proofing-rules/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('proofing_rules', ['id' => $id]);
    }

    public function test_built_in_checks_can_be_switched_off_but_not_rewritten_or_deleted(): void
    {
        $this->actingAs(User::factory()->role(Role::Admin)->create());
        $rule = ProofingRule::where('type', 'builtin')->where('pattern', 'repeated_word')->firstOrFail();

        $this->putJson("/api/proofing-rules/{$rule->id}", ['enabled' => false, 'pattern' => 'hacked', 'exceptions' => ['لا', ' لا ', 'نعم']])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.pattern', 'repeated_word')
            ->assertJsonPath('data.exceptions', ['لا', 'نعم']);

        $this->deleteJson("/api/proofing-rules/{$rule->id}")->assertUnprocessable();
        $this->postJson('/api/proofing-rules', ['type' => 'builtin', 'pattern' => 'x'])->assertUnprocessable();
    }

    public function test_import_adds_updates_and_reports_bad_rows(): void
    {
        $this->actingAs(User::factory()->role(Role::ProjectManager)->create());

        $this->postJson('/api/proofing-rules/import', ['rules' => [
            ['type' => 'word', 'pattern' => 'الى', 'replacement' => 'إلى', 'message' => 'رسالة جديدة'],
            ['type' => 'phrase', 'pattern' => 'بسم اللة', 'replacement' => 'بسم الله'],
            ['type' => 'word', 'pattern' => 'خطأ', 'replacement' => ''],
            ['type' => 'builtin', 'pattern' => 'tanween', 'enabled' => false],
        ]])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 2)
            ->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('proofing_rules', ['pattern' => 'الى', 'message' => 'رسالة جديدة']);
        $this->assertDatabaseHas('proofing_rules', ['pattern' => 'tanween', 'enabled' => false]);
    }
}
