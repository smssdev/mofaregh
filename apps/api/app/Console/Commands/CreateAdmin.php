<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates the first admin on a fresh server (or resets an admin's password) and prints a generated password
 * once. Nothing else stores it.
 */
class CreateAdmin extends Command
{
    protected $signature = 'mofaregh:create-admin {email} {--name=مدير النظام} {--reset : Give an existing admin a new password}';

    protected $description = 'Create an admin account with a generated password';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));
        $existing = User::where('email', $email)->first();

        if ($existing && ! $this->option('reset')) {
            $this->error("{$email} already exists. Pass --reset to give it a new password.");

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);

        User::updateOrCreate(['email' => $email], [
            'name' => $existing?->name ?? $this->option('name'),
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
        ]);

        $this->info("Admin: {$email}");
        $this->line("Password: {$password}");
        $this->comment('Change it after the first sign-in.');

        return self::SUCCESS;
    }
}
