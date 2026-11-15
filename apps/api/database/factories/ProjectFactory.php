<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'مشروع '.fake()->unique()->numberBetween(1, 100000),
            'description' => null,
            'status' => 'active',
            'manager_id' => User::factory()->role(Role::ProjectManager),
        ];
    }
}
