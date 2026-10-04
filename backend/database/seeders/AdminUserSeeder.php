<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the first Super Admin from ADMIN_EMAIL / ADMIN_PASSWORD.
 * In production both variables are mandatory; locally a dev default is used.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@offshore.local');
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            if (app()->isProduction()) {
                throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD before seeding production.');
            }
            $password = 'Admin@12345';
        }

        $user = User::query()->firstOrCreate(
            ['email' => mb_strtolower($email)],
            [
                'name' => 'System Administrator',
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'password' => $password,
                'status' => UserStatus::Active,
                'password_changed_at' => now(),
            ],
        );

        $user->assignRole(UserRole::SuperAdmin->value);
    }
}
