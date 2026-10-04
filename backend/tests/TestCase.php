<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function seedCore(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, DocumentTypeSeeder::class]);
    }

    protected function userWithRole(UserRole|string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role instanceof UserRole ? $role->value : $role);

        return $user;
    }

    protected function actingAsRole(UserRole|string $role): User
    {
        $user = $this->userWithRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
