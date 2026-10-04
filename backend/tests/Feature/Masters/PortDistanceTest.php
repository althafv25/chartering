<?php

namespace Tests\Feature\Masters;

use App\Enums\UserRole;

class PortDistanceTest extends MastersTestCase
{
    public function test_port_validation_unlocode_and_agents(): void
    {
        $this->actingAsRole(UserRole::Operations);

        $this->postJson('/api/v1/ports', ['name' => 'Jebel Ali', 'unlocode' => 'ae jea', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'latitude' => '25.0112', 'longitude' => '55.0612'])
            ->assertCreated()->assertJsonPath('data.unlocode', 'AEJEA')->assertJsonPath('data.label', 'Jebel Ali (AEJEA)');
        $this->postJson('/api/v1/ports', ['name' => 'Dup', 'unlocode' => 'AEJEA', 'country' => 'AE', 'timezone' => 'UTC'])
            ->assertStatus(422)->assertJsonValidationErrors('unlocode');
        $this->postJson('/api/v1/ports', ['name' => 'Mismatch', 'unlocode' => 'SADMM', 'country' => 'AE', 'timezone' => 'UTC'])
            ->assertStatus(422)->assertJsonValidationErrors('unlocode');
        $this->postJson('/api/v1/ports', ['name' => 'Half coords', 'country' => 'AE', 'timezone' => 'UTC', 'latitude' => '25'])
            ->assertStatus(422)->assertJsonValidationErrors('longitude');

        $port = $this->port('Khalifa', 'AEKHL');
        $owner = $this->company(['owner']);
        $agent = $this->company(['agent']);
        $this->postJson("/api/v1/ports/{$port->id}/agents", ['company_id' => $owner->id])->assertStatus(422)->assertJsonPath('error_code', 'company_not_agent');
        $this->postJson("/api/v1/ports/{$port->id}/agents", ['company_id' => $agent->id, 'is_default' => true])
            ->assertOk()->assertJsonPath('data.agents.0.company.id', $agent->id)->assertJsonPath('data.agents.0.is_default', true);
    }

    public function test_distance_falls_back_to_flagged_great_circle_estimate(): void
    {
        $this->actingAsRole(UserRole::Chartering);
        $a = $this->port('Jebel Ali', 'AEJEA', '25.0112', '55.0612');
        $b = $this->port('Ras Tanura', 'SARTA', '26.6439', '50.1597');

        $this->postJson('/api/v1/distances/calculate', ['from_type' => 'port', 'from_id' => $a->id, 'to_type' => 'port', 'to_id' => $b->id])
            ->assertOk()->assertJsonPath('data.provider', 'great_circle_estimate')->assertJsonPath('data.is_estimate', true);

        $this->assertDatabaseCount('port_distances', 0); // estimates are never cached
    }

    public function test_manual_distance_is_used_in_both_directions_and_audited(): void
    {
        $this->actingAsRole(UserRole::Operations);
        $a = $this->port('Jebel Ali', 'AEJEA');
        $b = $this->port('Ras Tanura', 'SARTA', null, null);

        $this->postJson('/api/v1/distances/calculate', ['from_type' => 'port', 'from_id' => $a->id, 'to_type' => 'port', 'to_id' => $b->id])
            ->assertStatus(409)->assertJsonPath('error_code', 'distance_unavailable');

        $this->postJson('/api/v1/distances', ['from_type' => 'port', 'from_id' => $a->id, 'to_type' => 'port', 'to_id' => $b->id, 'distance_nm' => '318.40', 'eca_distance_nm' => '0'])
            ->assertCreated()->assertJsonPath('data.provider', 'manual');

        $this->postJson('/api/v1/distances/calculate', ['from_type' => 'port', 'from_id' => $b->id, 'to_type' => 'port', 'to_id' => $a->id])
            ->assertOk()->assertJsonPath('data.distance_nm', '318.40')->assertJsonPath('data.reversed', true)->assertJsonPath('data.is_estimate', false);

        // Override updates the same manual row rather than duplicating it.
        $this->postJson('/api/v1/distances', ['from_type' => 'port', 'from_id' => $a->id, 'to_type' => 'port', 'to_id' => $b->id, 'distance_nm' => '320.00'])->assertCreated();
        $this->assertDatabaseCount('port_distances', 1);
        $this->getJson('/api/v1/distances')->assertOk()->assertJsonPath('data.0.from.label', 'Jebel Ali (AEJEA)')->assertJsonPath('data.0.distance_nm', '320.00');

        $this->postJson('/api/v1/distances', ['from_type' => 'port', 'from_id' => $a->id, 'to_type' => 'port', 'to_id' => $b->id, 'distance_nm' => '10', 'eca_distance_nm' => '11'])
            ->assertStatus(422)->assertJsonValidationErrors('eca_distance_nm');
    }

    public function test_distance_to_offshore_location_and_permissions(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $port = $this->port();
        $loc = $this->postJson('/api/v1/offshore-locations', ['code' => 'umm-shaif', 'name' => 'Umm Shaif Field', 'latitude' => '25.2', 'longitude' => '53.2'])
            ->assertCreated()->assertJsonPath('data.code', 'UMM-SHAIF')->json('data.id');

        $this->postJson('/api/v1/distances/calculate', ['from_type' => 'port', 'from_id' => $port->id, 'to_type' => 'location', 'to_id' => $loc])->assertOk();
        $this->getJson('/api/v1/route-points?search=Umm')->assertOk()->assertJsonPath('data.0.type', 'location');

        $this->actingAsRole(UserRole::Commercial);
        $this->postJson('/api/v1/distances', ['from_type' => 'port', 'from_id' => $port->id, 'to_type' => 'location', 'to_id' => $loc, 'distance_nm' => '100'])->assertForbidden();
        $this->postJson('/api/v1/ports', ['name' => 'X', 'country' => 'AE', 'timezone' => 'UTC'])->assertForbidden();
    }
}
