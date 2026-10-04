<?php

namespace Tests\Feature\Masters;

use App\Enums\UserRole;
use App\Models\FuelType;
use App\Models\Vessel;

class VesselTest extends MastersTestCase
{
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'gms1', 'name' => 'GMS Endeavour', 'imo_number' => '9074729', 'mmsi' => '470123456',
            'vessel_type_id' => $this->psvTypeId(), 'flag_country' => 'AE', 'year_built' => 2012,
            'loa_m' => '75.400', 'lbp_m' => '70.200', 'dwt_mt' => '3500.500', 'service_speed_kn' => '12.50', 'max_speed_kn' => '14.00',
            'dp_class' => 'DP2', 'custom_attributes' => ['liquid_mud_m3' => '850.5', 'fifi_class' => 'FiFi 1'],
            ...$overrides,
        ];
    }

    public function test_marine_ops_can_create_vessel_and_decimals_round_trip_as_strings(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $owner = $this->company(['owner']);

        $res = $this->postJson('/api/v1/vessels', $this->payload(['owner_company_id' => $owner->id]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'GMS1')
            ->assertJsonPath('data.dwt_mt', '3500.500')
            ->assertJsonPath('data.service_speed_kn', '12.50')
            ->assertJsonPath('data.custom_attributes.liquid_mud_m3', '850.5')
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.lock_version', 0);

        $this->assertIsString($res->json('data.loa_m'));
    }

    public function test_validation_imo_check_digit_unique_and_speed_rules(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $this->postJson('/api/v1/vessels', $this->payload())->assertCreated();

        $this->postJson('/api/v1/vessels', $this->payload(['code' => 'X2', 'imo_number' => '9074728', 'mmsi' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('imo_number');
        $this->postJson('/api/v1/vessels', $this->payload(['code' => 'GMS1', 'imo_number' => null, 'mmsi' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/vessels', $this->payload(['code' => 'X3', 'imo_number' => null, 'mmsi' => null, 'eco_speed_kn' => '15.00']))
            ->assertStatus(422)->assertJsonValidationErrors('eco_speed_kn');
        $this->postJson('/api/v1/vessels', $this->payload(['code' => 'X4', 'imo_number' => null, 'mmsi' => null, 'dwt_mt' => '12.3456']))
            ->assertStatus(422)->assertJsonValidationErrors('dwt_mt');
    }

    public function test_custom_attributes_are_validated_against_vessel_type_schema(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->postJson('/api/v1/vessels', $this->payload(['custom_attributes' => ['unknown_field' => '1']]))
            ->assertStatus(422)->assertJsonValidationErrors('custom_attributes.unknown_field');
        $this->postJson('/api/v1/vessels', $this->payload(['custom_attributes' => ['fifi_class' => 'FiFi 9']]))
            ->assertStatus(422)->assertJsonValidationErrors('custom_attributes.fifi_class');
    }

    public function test_update_requires_current_lock_version_and_records_former_name(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $id = $this->postJson('/api/v1/vessels', $this->payload())->json('data.id');

        $this->putJson("/api/v1/vessels/{$id}", ['name' => 'Renamed'])->assertStatus(422)->assertJsonValidationErrors('lock_version');

        $this->putJson("/api/v1/vessels/{$id}", ['name' => 'GMS Pioneer', 'lock_version' => 0])
            ->assertOk()->assertJsonPath('data.lock_version', 1)->assertJsonPath('data.former_names.0.name', 'GMS Endeavour');

        // A second user still holding version 0 gets a conflict, not a silent overwrite.
        $this->putJson("/api/v1/vessels/{$id}", ['name' => 'Other', 'lock_version' => 0])
            ->assertStatus(409)->assertJsonPath('error_code', 'stale_record');

        // Former names are searchable.
        $this->getJson('/api/v1/vessels?search=Endeavour')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_permissions(): void
    {
        $this->actingAsRole(UserRole::Chartering);
        $this->getJson('/api/v1/vessels')->assertOk();
        $this->postJson('/api/v1/vessels', $this->payload())->assertForbidden();

        $this->actingAsRole(UserRole::Operations);
        $this->postJson('/api/v1/vessels', $this->payload())->assertForbidden();

        $vessel = Vessel::query()->create(['code' => 'V9', 'name' => 'Existing', 'vessel_type_id' => $this->psvTypeId()]);
        $this->putJson("/api/v1/vessels/{$vessel->id}", ['remarks' => 'ok', 'lock_version' => 0])->assertOk();
        $this->deleteJson("/api/v1/vessels/{$vessel->id}")->assertForbidden();
    }

    public function test_consumption_profile_rules(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $vessel = Vessel::query()->create(['code' => 'V1', 'name' => 'V1', 'vessel_type_id' => $this->psvTypeId()]);
        $mgo = FuelType::query()->where('code', 'MGO')->value('id');
        $url = "/api/v1/vessels/{$vessel->id}/consumption-profiles";

        $first = $this->postJson($url, ['name' => 'Design', 'effective_from' => '2026-01-01', 'rates' => [
            ['mode' => 'sea_laden', 'speed_kn' => '12', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '8.5'],
            ['mode' => 'port_idle', 'speed_kn' => '5', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '1.2'],
        ]])->assertCreated()->assertJsonPath('data.is_default', true);
        // Non-sea modes are stored with speed 0.
        $this->assertContains('0.00', collect($first->json('data.rates'))->pluck('speed_kn')->all());

        // Same name, overlapping open-ended period → rejected.
        $this->postJson($url, ['name' => 'Design', 'effective_from' => '2026-06-01', 'rates' => [
            ['mode' => 'standby', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '2'],
        ]])->assertStatus(409)->assertJsonPath('error_code', 'profile_period_overlap');

        // Sea mode without speed → rejected; duplicate combination → rejected.
        $this->postJson($url, ['name' => 'CP', 'effective_from' => '2026-01-01', 'rates' => [
            ['mode' => 'sea_ballast', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '7'],
        ]])->assertStatus(422)->assertJsonValidationErrors('rates.0.speed_kn');
        $this->postJson($url, ['name' => 'CP', 'effective_from' => '2026-01-01', 'rates' => [
            ['mode' => 'standby', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '2'],
            ['mode' => 'standby', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '3'],
        ]])->assertStatus(422)->assertJsonValidationErrors('rates.1.mode');

        // A new default profile demotes the previous one.
        $cp = $this->postJson($url, ['name' => 'CP 2026', 'effective_from' => '2026-01-01', 'is_default' => true, 'rates' => [
            ['mode' => 'dp_operation', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '6.25'],
        ]])->assertCreated()->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $cp)->assertJsonPath('data.1.is_default', false);

        // Rate edits are audited.
        $this->putJson("{$url}/{$cp}", ['rates' => [['mode' => 'dp_operation', 'fuel_type_id' => $mgo, 'consumption_mt_per_day' => '6.5']]])->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'rates_changed']);
    }

    public function test_vessel_documents_use_parent_permissions(): void
    {
        $vessel = Vessel::query()->create(['code' => 'V1', 'name' => 'V1', 'vessel_type_id' => $this->psvTypeId()]);

        $this->actingAsRole(UserRole::Chartering); // vessels.view yes, vessels.update no
        $this->getJson("/api/v1/vessels/{$vessel->id}/documents")->assertOk();
        $this->post("/api/v1/vessels/{$vessel->id}/documents", ['document_type_id' => 1], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
