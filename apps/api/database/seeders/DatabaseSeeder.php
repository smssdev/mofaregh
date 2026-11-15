<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data: one account per role (password "password") and a sample project.
 * Never runs in production: the first admin there comes from `php artisan mofaregh:create-admin`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipped: demo accounts are not created in production.');

            return;
        }

        $accounts = [
            ['admin@mofaregh.test', 'مدير النظام', Role::Admin],
            ['manager@mofaregh.test', 'مدير المشروع', Role::ProjectManager],
            ['transcriber@mofaregh.test', 'المفرِّغ', Role::Transcriber],
            ['linguist@mofaregh.test', 'المراجع اللغوي', Role::LinguisticReviewer],
            ['sharia@mofaregh.test', 'المدقق الشرعي', Role::ShariaChecker],
        ];

        $users = [];
        foreach ($accounts as [$email, $name, $role]) {
            $users[$role->value] = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'is_active' => true,
            ]);
        }

        $project = Project::firstOrCreate(['name' => 'دروس التفسير - تجريبي'], [
            'description' => 'مشروع تجريبي لمحاضرات مسجلة.',
            'manager_id' => $users[Role::ProjectManager->value]->id,
            'created_by' => $users[Role::Admin->value]->id,
        ]);
        $project->members()->syncWithoutDetaching([
            $users[Role::Transcriber->value]->id,
            $users[Role::LinguisticReviewer->value]->id,
            $users[Role::ShariaChecker->value]->id,
        ]);
    }
}
