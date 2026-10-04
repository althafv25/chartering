<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_settings_defaults_and_update_are_typed_and_audited(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $data = $this->getJson('/api/v1/settings')->assertOk()->json('data');
        $this->assertSame(60, $data['notifications']['notifications.contract_expiry_days']['value']);

        $saved = $this->putJson('/api/v1/settings', ['settings' => [
            'notifications.contract_expiry_days' => 45,
            'notifications.email_enabled' => true,
        ]])->assertOk()->json('data.notifications');
        $this->assertSame(45, $saved['notifications.contract_expiry_days']['value']);
        $this->assertTrue($saved['notifications.email_enabled']['value']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'settings', 'event' => 'updated']);
    }

    public function test_unknown_or_invalid_setting_rejected(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->putJson('/api/v1/settings', ['settings' => ['hack.me' => 1]])->assertStatus(422);
        $this->putJson('/api/v1/settings', ['settings' => ['notifications.contract_expiry_days' => 0]])->assertStatus(422);
    }

    public function test_settings_permissions(): void
    {
        $this->actingAsRole(UserRole::Management);
        $this->getJson('/api/v1/settings')->assertOk();
        $this->putJson('/api/v1/settings', ['settings' => ['company.name' => 'X']])->assertForbidden();
    }

    public function test_audit_log_access_and_filters(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $this->putJson('/api/v1/settings', ['settings' => ['company.name' => 'Gulf Offshore']])->assertOk();

        $this->getJson('/api/v1/audit-logs?log_name=settings')->assertOk()

            ->assertJsonStructure(['data' => [['ip', 'request_id', 'causer']], 'meta']);

        $this->actingAsRole(UserRole::Chartering);
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_audit_log_lists_entries_that_have_no_properties(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        DB::table('activity_log')->insert(['log_name' => 'legacy', 'description' => 'imported entry', 'event' => 'created', 'properties' => null, 'created_at' => now(), 'updated_at' => now()]);

        $row = collect($this->getJson('/api/v1/audit-logs?log_name=legacy')->assertOk()->json('data'))->firstWhere('description', 'imported entry');
        $this->assertNotNull($row);
        $this->assertNull($row['old']);
        $this->assertNull($row['ip']);
    }
}
