<?php

namespace Tests\Feature\Masters;

use App\Enums\UserRole;
use App\Models\Vessel;

class VesselStatusTest extends MastersTestCase
{
    private Vessel $vessel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vessel = Vessel::query()->create(['code' => 'V1', 'name' => 'Vessel One', 'vessel_type_id' => $this->psvTypeId()]);
    }

    private function change(array $data)
    {
        return $this->postJson("/api/v1/vessels/{$this->vessel->id}/status", $data);
    }

    public function test_new_status_closes_previous_period_and_updates_current_status(): void
    {
        $this->actingAsRole(UserRole::Operations);
        $port = $this->port();

        $this->change(['track' => 'commercial', 'status' => 'available', 'effective_from' => '2026-09-01T00:00:00Z', 'port_id' => $port->id])->assertCreated();
        $this->change(['track' => 'commercial', 'status' => 'on_hire', 'effective_from' => '2026-09-10T06:00:00Z', 'reason' => 'Fixture'])
            ->assertCreated()->assertJsonPath('data.effective_to', null);
        $this->change(['track' => 'operational', 'status' => 'at_sea', 'effective_from' => '2026-09-10T07:00:00Z'])->assertCreated();

        $history = $this->getJson("/api/v1/vessels/{$this->vessel->id}/status-history?track=commercial")->assertOk()->json('data');
        $this->assertSame('on_hire', $history[0]['status']);
        $this->assertSame('2026-09-10T06:00:00+00:00', $history[1]['effective_to']);
        $this->assertSame('Jebel Ali (AEJEA)', $history[1]['location']);

        $this->vessel->refresh();
        $this->assertSame('on_hire', $this->vessel->commercial_status);
        $this->assertSame('at_sea', $this->vessel->operational_status);
        $this->assertSame(0, $this->vessel->lock_version, 'Status changes must not invalidate the particulars form.');

        $this->getJson('/api/v1/vessel-status/board')->assertOk()
            ->assertJsonPath('data.0.commercial.status', 'on_hire')->assertJsonPath('data.0.operational.status', 'at_sea');
    }

    public function test_rejects_backdated_future_invalid_and_unchanged_statuses(): void
    {
        $this->actingAsRole(UserRole::Operations);
        $this->change(['track' => 'commercial', 'status' => 'available', 'effective_from' => '2026-09-10T00:00:00Z'])->assertCreated();

        $this->change(['track' => 'commercial', 'status' => 'on_hire', 'effective_from' => '2026-09-05T00:00:00Z'])
            ->assertStatus(409)->assertJsonPath('error_code', 'status_backdated');
        $this->change(['track' => 'commercial', 'status' => 'on_hire', 'effective_from' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('error_code', 'status_in_future');
        $this->change(['track' => 'commercial', 'status' => 'at_sea', 'effective_from' => '2026-09-11T00:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors('status'); // operational status on commercial track
        $this->change(['track' => 'commercial', 'status' => 'available', 'effective_from' => '2026-09-11T00:00:00Z'])
            ->assertStatus(409)->assertJsonPath('error_code', 'status_unchanged');
    }

    public function test_undo_reopens_previous_period(): void
    {
        $this->actingAsRole(UserRole::Operations);
        $this->change(['track' => 'operational', 'status' => 'at_port', 'effective_from' => '2026-09-01T00:00:00Z'])->assertCreated();
        $this->change(['track' => 'operational', 'status' => 'at_sea', 'effective_from' => '2026-09-02T00:00:00Z'])->assertCreated();

        $this->postJson("/api/v1/vessels/{$this->vessel->id}/status/undo", ['track' => 'operational'])
            ->assertOk()->assertJsonPath('data.status', 'at_port')->assertJsonPath('data.effective_to', null);

        $this->assertSame('at_port', $this->vessel->fresh()->operational_status);
        $this->assertDatabaseHas('activity_log', ['event' => 'status_undone']);
    }

    public function test_status_permissions(): void
    {
        $this->actingAsRole(UserRole::Chartering);
        $this->getJson('/api/v1/vessel-status/board')->assertOk();
        $this->change(['track' => 'commercial', 'status' => 'available', 'effective_from' => '2026-09-01T00:00:00Z'])->assertForbidden();
    }
}
