<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_lists_roles_and_permission_catalogue(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(9, 'data');
        $this->getJson('/api/v1/roles/permissions')->assertOk()
            ->assertJsonFragment(['module' => 'users']);
    }

    public function test_create_update_delete_custom_role(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $id = $this->postJson('/api/v1/roles', ['name' => 'Desk Lead', 'permissions' => ['users.view']])
            ->assertCreated()->assertJsonPath('data.name', 'desk-lead')->json('data.id');

        $this->putJson("/api/v1/roles/{$id}", ['permissions' => ['users.view', 'roles.view']])
            ->assertOk()->assertJsonPath('data.permissions', ['roles.view', 'users.view']);

        $this->deleteJson("/api/v1/roles/{$id}")->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $id]);
    }

    public function test_unknown_permission_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->postJson('/api/v1/roles', ['name' => 'x-role', 'permissions' => ['nuke.everything']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.0');
    }

    public function test_system_roles_cannot_be_deleted_and_super_admin_locked(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $finance = Role::findByName('finance');
        $super = Role::findByName('super-admin');

        $this->deleteJson("/api/v1/roles/{$finance->id}")->assertStatus(409)->assertJsonPath('error_code', 'role_locked');
        $this->putJson("/api/v1/roles/{$super->id}", ['permissions' => []])->assertStatus(409);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $role = Role::create(['name' => 'temp', 'guard_name' => 'web']);
        $this->userWithRole('temp');

        $this->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(409)->assertJsonPath('error_code', 'role_in_use');
    }

    public function test_management_can_view_but_not_modify_roles(): void
    {
        $this->actingAsRole(UserRole::Management);

        $this->getJson('/api/v1/roles')->assertOk();
        $this->postJson('/api/v1/roles', ['name' => 'abc', 'permissions' => []])->assertForbidden();
    }
}
