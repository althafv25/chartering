<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Ali', 'last_name' => 'Hassan', 'email' => 'Ali@Example.com',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
            'roles' => ['chartering'], ...$overrides,
        ];
    }

    public function test_super_admin_can_create_user_with_roles(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->postJson('/api/v1/users', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'ali@example.com')
            ->assertJsonPath('data.name', 'Ali Hassan')
            ->assertJsonPath('data.roles', ['chartering']);

        $this->assertDatabaseHas('activity_log', ['event' => 'roles_changed']);
    }

    public function test_user_list_is_paginated_and_filterable(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        User::factory()->count(3)->create();
        $this->userWithRole(UserRole::Finance, ['first_name' => 'Zed', 'name' => 'Zed Finance']);

        $this->getJson('/api/v1/users?per_page=2')->assertOk()
            ->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 5);

        $this->getJson('/api/v1/users?role=finance')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/users?search=Zed')->assertOk()->assertJsonPath('data.0.name', 'Zed Finance');
    }

    public function test_management_can_view_but_not_create_users(): void
    {
        $this->actingAsRole(UserRole::Management);

        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', $this->payload())->assertForbidden()->assertJsonPath('error_code', 'forbidden');
    }

    public function test_read_only_cannot_list_users(): void
    {
        $this->actingAsRole(UserRole::ReadOnly);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_duplicate_email_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        User::factory()->create(['email' => 'ali@example.com']);

        $this->postJson('/api/v1/users', $this->payload())->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_only_super_admin_can_grant_super_admin(): void
    {
        $role = Role::findByName('management');
        $role->givePermissionTo(['users.create', 'users.update']);
        $this->actingAsRole(UserRole::Management);

        $this->postJson('/api/v1/users', $this->payload(['roles' => ['super-admin']]))
            ->assertForbidden()->assertJsonPath('error_code', 'super_admin_required');
    }

    public function test_cannot_deactivate_or_delete_self(): void
    {
        $me = $this->actingAsRole(UserRole::SuperAdmin);

        $this->patchJson("/api/v1/users/{$me->id}/status", ['status' => 'inactive'])
            ->assertStatus(409)->assertJsonPath('error_code', 'cannot_deactivate_self');
        $this->deleteJson("/api/v1/users/{$me->id}")
            ->assertStatus(409)->assertJsonPath('error_code', 'cannot_delete_self');
    }

    public function test_last_super_admin_cannot_be_demoted(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $other = User::query()->first(); // the acting super admin
        $second = $this->userWithRole(UserRole::Chartering);

        // Promote second, then demote the first (allowed: one remains)
        $this->putJson("/api/v1/users/{$second->id}", ['roles' => ['super-admin']])->assertOk();
        $this->putJson("/api/v1/users/{$other->id}", ['roles' => ['management']])->assertOk();

        // Now $second is the only super admin; deleting it would leave none.
        $third = $this->userWithRole(UserRole::SuperAdmin);
        Sanctum::actingAs($third);
        $this->deleteJson("/api/v1/users/{$second->id}")->assertOk();
        $this->patchJson("/api/v1/users/{$third->id}/status", ['status' => 'inactive'])->assertStatus(409);
    }

    public function test_deactivation_revokes_tokens(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $target = $this->userWithRole(UserRole::Operations);
        $target->createToken('web');

        $this->patchJson("/api/v1/users/{$target->id}/status", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_delete_is_soft(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $target = $this->userWithRole(UserRole::Operations);

        $this->deleteJson("/api/v1/users/{$target->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_password_not_written_to_audit_log(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $this->postJson('/api/v1/users', $this->payload())->assertCreated();

        $this->assertDatabaseMissing('activity_log', ['properties' => '%password%']);
        foreach (Activity::all() as $a) {
            $this->assertArrayNotHasKey('password', (array) $a->properties->get('attributes'));
        }
    }
}
