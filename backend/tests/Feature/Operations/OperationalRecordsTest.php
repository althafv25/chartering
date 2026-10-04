<?php

namespace Tests\Feature\Operations;

use App\Enums\UserRole;
use App\Models\MilestoneType;
use App\Models\OffshoreLocation;
use App\Models\Vessel;

class OperationalRecordsTest extends OperationsTestCase
{
    public function test_port_call_local_times_validation_and_status(): void
    {
        $id = $this->sailingVoyage();
        $this->postJson("/api/v1/voyages/{$id}/port-calls", ['purpose' => 'load'])->assertStatus(422);
        $loc = OffshoreLocation::query()->create(['code' => 'FLDA', 'name' => 'Field A', 'latitude' => '25.1', 'longitude' => '53.2', 'timezone' => 'Asia/Dubai']);
        $this->postJson("/api/v1/voyages/{$id}/port-calls", ['port_id' => $this->ports[0], 'offshore_location_id' => $loc->id, 'purpose' => 'load'])->assertStatus(422);

        // Ras Tanura is Asia/Riyadh (UTC+3).
        $call = $this->portCall($id, ['port_id' => $this->ports[1], 'purpose' => 'discharge', 'eta' => '2026-10-15T09:00', 'status' => 'nominated']);
        $this->assertSame('2026-10-15T06:00:00+00:00', $call['eta']);
        $this->assertSame('2026-10-15T09:00', $call['eta_local']);
        $this->assertSame('Asia/Riyadh', $call['timezone']);
        $this->assertSame('nominated', $call['status']);
        $this->assertSame(1, $call['sequence']);

        $url = "/api/v1/voyages/{$id}/port-calls/{$call['id']}";
        $this->putJson($url, ['lock_version' => $call['lock_version'], 'ata' => '2026-10-16T10:00', 'atd' => '2026-10-16T08:00'])->assertStatus(422)->assertJsonStructure(['errors' => ['atd']]);
        $this->putJson($url, ['lock_version' => $call['lock_version'], 'atb' => '2026-10-16T10:00'])->assertStatus(422)->assertJsonStructure(['errors' => ['ata']]);
        $this->putJson($url, ['lock_version' => $call['lock_version'], 'ata' => '2026-10-30T10:00'])->assertStatus(422);
        $c = $this->putJson($url, ['lock_version' => $call['lock_version'], 'ata' => '2026-10-16T10:00'])->assertOk()->assertJsonPath('data.status', 'arrived')->json('data');
        $this->putJson($url, ['lock_version' => $call['lock_version'], 'berth' => 'B2'])->assertStatus(409)->assertJsonPath('error_code', 'stale_record');

        $this->postJson("{$url}/cancel", ['reason' => 'Not needed'])->assertStatus(409)->assertJsonPath('error_code', 'port_call_in_progress');
        $this->deleteJson($url)->assertStatus(409)->assertJsonPath('error_code', 'port_call_in_use');

        $planned = $this->portCall($id, ['offshore_location_id' => $loc->id, 'port_id' => null, 'purpose' => 'offshore_ops']);
        $this->assertSame(2, $planned['sequence']);
        $this->postJson("/api/v1/voyages/{$id}/port-calls/{$planned['id']}/cancel", ['reason' => 'Scope changed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('arrived', $c['status']);
    }

    public function test_milestones_validate_type_scope_and_use_port_timezone(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $offshoreType = MilestoneType::query()->where('code', 'OPS_START')->value('id');
        $arrival = MilestoneType::query()->where('code', 'ARRIVAL')->value('id');

        $this->postJson("/api/v1/voyages/{$id}/milestones", ['milestone_type_id' => $offshoreType, 'actual_at' => '2026-10-15T08:00'])->assertStatus(422);
        $this->postJson("/api/v1/voyages/{$id}/milestones", ['milestone_type_id' => $arrival])->assertStatus(422);
        $m = $this->postJson("/api/v1/voyages/{$id}/milestones", ['milestone_type_id' => $arrival, 'port_call_id' => $call['id'], 'actual_at' => '2026-10-15T08:00'])
            ->assertCreated()->json('data');
        $this->assertSame('2026-10-15T04:00:00+00:00', $m['actual_at']); // Jebel Ali, Asia/Dubai
        $this->assertSame('manual', $m['source']);

        $this->postJson("/api/v1/voyages/{$id}/milestones/{$m['id']}/verify")->assertOk()->assertJsonPath('data.verified_by.id', $this->ops->id);
        // Changing the actual time clears verification.
        $this->putJson("/api/v1/voyages/{$id}/milestones/{$m['id']}", ['actual_at' => '2026-10-15T09:00'])->assertOk()->assertJsonPath('data.verified_at', null);
        $this->getJson("/api/v1/voyages/{$id}")->assertJsonCount(1, 'data.milestones')->assertJsonPath('data.status', 'sailing'); // BR-VS-02: no auto status
    }

    public function test_off_hire_hours_overlap_and_agreement(): void
    {
        $id = $this->sailingVoyage();
        $base = "/api/v1/voyages/{$id}/off-hire";
        $this->postJson($base, ['from_at' => '2026-10-13T00:00', 'to_at' => '2026-10-13T06:00', 'reason_code' => 'breakdown'])->assertStatus(422); // before commencement
        $e = $this->postJson($base, ['from_at' => '2026-10-16T06:00', 'to_at' => '2026-10-17T00:00', 'reason_code' => 'breakdown',
            'fuel_consumed' => [['fuel_type_id' => $this->mgo, 'mt' => '1.5']]])->assertCreated()->json('data');
        $this->assertSame('18.0000', $e['hours']);
        $this->assertSame('0.7500', $e['days']);
        $this->assertSame('1.500', $e['fuel_consumed'][0]['mt']);

        $this->postJson($base, ['from_at' => '2026-10-16T20:00', 'to_at' => '2026-10-17T02:00', 'reason_code' => 'repairs'])->assertStatus(422)
            ->assertJsonPath('error_code', 'off_hire_overlap');
        $this->postJson($base, ['from_at' => '2026-10-17T00:00', 'to_at' => '2026-10-16T00:00', 'reason_code' => 'repairs'])->assertStatus(422);
        $open = $this->postJson($base, ['from_at' => '2026-10-18T00:00', 'reason_code' => 'weather'])->assertCreated()->json('data');
        $this->postJson("{$base}/{$open['id']}/agree")->assertStatus(409)->assertJsonPath('error_code', 'off_hire_open_ended');

        $this->as($this->charterer)->postJson("{$base}/{$e['id']}/agree")->assertForbidden();
        $this->as($this->ops)->postJson("{$base}/{$e['id']}/agree", ['comment' => 'Per master statement'])->assertOk()->assertJsonPath('data.status', 'agreed');
        $this->putJson("{$base}/{$e['id']}", ['lock_version' => $e['lock_version'] + 1, 'description' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'off_hire_read_only');
        $this->deleteJson("{$base}/{$e['id']}")->assertStatus(409);

        $this->postJson("{$base}/{$open['id']}/dispute", ['comment' => 'Charterer disputes weather'])->assertOk()->assertJsonPath('data.status', 'disputed');
        $rows = collect($this->getJson("/api/v1/voyages/{$id}/comparison")->json('data.rows'))->keyBy('metric');
        $this->assertSame('0.75', $rows['off_hire_days']['values']['current']); // disputed excluded
    }

    public function test_captain_reports_chronology_verification_and_actuals(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $vessel = $this->vessel->id;
        $noon = $this->postJson('/api/v1/captain-reports', ['vessel_id' => $vessel, 'voyage_id' => $id, 'report_type' => 'noon', 'reported_at' => '2026-10-15T12:00:00+04:00',
            'distance_since_last_nm' => '210.5', 'speed_kn' => '11.2', 'fuel_lines' => [['fuel_type_id' => $this->mgo, 'rob_mt' => '180', 'consumed_mt' => '9.8']]])
            ->assertCreated()->assertJsonPath('data.reported_at', '2026-10-15T08:00:00+00:00')->assertJsonPath('data.fuel_lines.0.consumed_mt', '9.800')->json('data');

        // REP-02: out-of-order and duplicate fuel types are refused.
        $this->postJson('/api/v1/captain-reports', ['vessel_id' => $vessel, 'voyage_id' => $id, 'report_type' => 'noon', 'reported_at' => '2026-10-15T07:00:00Z'])
            ->assertStatus(422)->assertJsonPath('error_code', 'report_out_of_order');
        $this->putJson("/api/v1/captain-reports/{$noon['id']}", ['lock_version' => $noon['lock_version'],
            'fuel_lines' => [['fuel_type_id' => $this->mgo, 'consumed_mt' => '1'], ['fuel_type_id' => $this->mgo, 'consumed_mt' => '2']]])->assertStatus(422);

        $arrival = $this->postJson('/api/v1/captain-reports', ['vessel_id' => $vessel, 'voyage_id' => $id, 'port_call_id' => $call['id'], 'report_type' => 'arrival',
            'reported_at' => '2026-10-16T06:30:00+04:00', 'distance_since_last_nm' => '100'])->assertCreated()->json('data');

        // A draft report blocks completion (OP-03).
        $this->postJson("/api/v1/voyages/{$id}/complete")->assertStatus(409)->assertJsonStructure(['errors' => ['captain_reports']]);

        // Unverified reports do not count (REP-01).
        $rows = fn () => collect($this->getJson("/api/v1/voyages/{$id}/comparison")->json('data.rows'))->keyBy('metric');
        $this->assertNull($rows()['sea_distance_nm']['values']['current']);

        $this->postJson("/api/v1/captain-reports/{$noon['id']}/verify")->assertStatus(409); // not submitted yet
        $this->postJson("/api/v1/captain-reports/{$noon['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->putJson("/api/v1/captain-reports/{$noon['id']}", ['lock_version' => $noon['lock_version'] + 1, 'remarks' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'report_read_only');
        $this->postJson("/api/v1/captain-reports/{$noon['id']}/verify")->assertOk()->assertJsonPath('data.status', 'verified');

        $this->postJson("/api/v1/captain-reports/{$arrival['id']}/submit")->assertOk();
        $this->postJson("/api/v1/captain-reports/{$arrival['id']}/reject", ['reason' => 'Wrong time'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/captain-reports/{$arrival['id']}/submit")->assertOk();
        $this->postJson("/api/v1/captain-reports/{$arrival['id']}/verify", ['apply_to_port_call' => true])->assertOk();
        $this->getJson("/api/v1/voyages/{$id}")->assertJsonPath('data.port_calls.0.ata', '2026-10-16T02:30:00+00:00')->assertJsonPath('data.port_calls.0.status', 'arrived');

        $r = $rows();
        $this->assertSame('310.50', $r['sea_distance_nm']['values']['current']);
        $this->assertSame('9.800', $r['fuel_total_mt']['values']['current']);

        $this->getJson("/api/v1/captain-reports?voyage_id={$id}&status=verified")->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/captain-reports/{$noon['id']}")->assertStatus(409);
    }

    public function test_captain_report_permissions_and_vessel_match(): void
    {
        $id = $this->voyage();
        $other = Vessel::query()->create(['code' => 'GMS2', 'name' => 'GMS Two', 'vessel_type_id' => $this->vessel->vessel_type_id]);
        $this->postJson('/api/v1/captain-reports', ['vessel_id' => $other->id, 'voyage_id' => $id, 'report_type' => 'noon', 'reported_at' => '2026-10-15T12:00:00Z'])
            ->assertStatus(422);

        $this->as($this->userWithRole(UserRole::ReadOnly))->getJson('/api/v1/captain-reports')->assertOk();
        $this->postJson('/api/v1/captain-reports', ['vessel_id' => $this->vessel->id, 'report_type' => 'noon', 'reported_at' => '2026-10-15T12:00:00Z'])->assertForbidden();
        $this->as($this->userWithRole(UserRole::MarineOperations))
            ->postJson('/api/v1/captain-reports', ['vessel_id' => $this->vessel->id, 'report_type' => 'noon', 'reported_at' => '2026-10-15T12:00:00Z'])->assertCreated();
    }
}
