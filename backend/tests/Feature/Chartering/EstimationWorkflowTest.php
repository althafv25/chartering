<?php

namespace Tests\Feature\Chartering;

use App\Domain\Estimation\EstimationCalculator;
use App\Enums\UserRole;
use App\Models\EstimationScenario;
use App\Models\VesselConsumptionRate;
use Spatie\Activitylog\Models\Activity;

class EstimationWorkflowTest extends CharteringTestCase
{
    public function test_creation_snapshots_defaults_from_enquiry_vessel_and_distance(): void
    {
        [$est, $sc] = $this->estimation();
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->assertOk()->json('data');

        $this->assertSame('A', $s['code']);
        $this->assertSame('incomplete', $s['calc_status']);                    // bunker price missing
        $this->assertStringContainsString('Bunker price missing', $s['calc_issues'][0]);
        $this->assertSame('330.00', $s['inputs']['legs'][0]['distance_nm']);    // stored distance only
        $this->assertSame('laden', $s['inputs']['legs'][0]['condition']);       // after the load port
        $this->assertSame('12.00', $s['inputs']['legs'][0]['speed_kn']);
        $this->assertCount(6, $s['inputs']['consumption']);
        $this->assertSame('FREIGHT', $s['inputs']['revenue_items'][0]['category_code']);
        $this->assertTrue($s['inputs']['revenue_items'][0]['commissionable']);  // category default (BR-EST-03)
        $this->assertSame('55.0000', $s['inputs']['revenue_items'][0]['rate']); // from enquiry rate idea
        $this->assertSame('2000.000', $s['inputs']['revenue_items'][0]['quantity']);
        $this->assertSame('GMS Endeavour', $s['vessel_snapshot']['name']);
        $this->assertStringStartsWith('EST-', $this->getJson("/api/v1/estimations/{$est}")->json('data.estimation_number'));
    }

    public function test_calculation_persists_version_hash_and_trace_and_is_idempotent(): void
    {
        [$est, $sc] = $this->estimation();
        $data = $this->completeScenario($est, $sc);

        $this->assertSame('calculated', $data['calc_status']);
        $this->assertSame(EstimationCalculator::VERSION, $data['calculation_version']);
        $this->assertSame($data['inputs_hash'], $data['result']['inputs_hash']);
        $this->assertSame('110000.00', $data['result']['gross_revenue']);       // 2000 × 55
        $this->assertNotEmpty($data['result']['trace']);
        $this->assertIsString($data['result']['profit']);

        $calculatedAt = $data['result']['calculated_at'];
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/calculate")->assertOk()->assertJsonPath('data.result.calculated_at', $calculatedAt);
    }

    public function test_recalculation_never_reads_master_data_and_refresh_defaults_is_explicit_and_audited(): void
    {
        [$est, $sc] = $this->estimation();
        $before = $this->completeScenario($est, $sc);

        // Master data changes after the snapshot…
        VesselConsumptionRate::query()->where('mode', 'sea_laden')->update(['consumption_mt_per_day' => '99']);
        $this->vessel->forceFill(['name' => 'Renamed Vessel'])->saveQuietly();

        // …saving unchanged inputs keeps the snapshot.
        $again = $this->completeScenario($est, $sc);
        $this->assertSame($before['result']['profit'], $again['result']['profit']);
        $this->assertSame('GMS Endeavour', $again['vessel_snapshot']['name']);

        // Explicit refresh pulls the new values and is audited.
        $refreshed = $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/refresh-defaults", ['vessel' => true, 'consumption' => true])->assertOk()->json('data');
        $this->assertSame('Renamed Vessel', $refreshed['vessel_snapshot']['name']);
        $this->assertNotSame($before['result']['profit'], $refreshed['result']['profit']);
        $this->assertTrue(Activity::query()->where('event', 'defaults_refreshed')->exists());
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/refresh-defaults", [])->assertStatus(422)->assertJsonPath('error_code', 'nothing_to_refresh');
    }

    public function test_scenario_clone_lineage_selection_and_comparison(): void
    {
        [$est, $sc] = $this->estimation();
        $this->completeScenario($est, $sc);

        $b = $this->postJson("/api/v1/estimations/{$est}/scenarios", ['clone_from_id' => $sc, 'name' => 'Higher bunker'])->assertCreated()
            ->assertJsonPath('data.code', 'B')->assertJsonPath('data.cloned_from_id', $sc)->assertJsonPath('data.calc_status', 'calculated')->json('data.id');
        $this->completeScenario($est, $b, ['fuel_prices' => [0 => ['price_per_mt' => '950']]]);
        $this->postJson("/api/v1/estimations/{$est}/scenarios", [])->assertCreated()->assertJsonPath('data.code', 'C');

        $cmp = $this->getJson("/api/v1/estimations/{$est}/compare")->assertOk()->json('data.scenarios');
        $this->assertCount(3, $cmp);
        $this->assertTrue((float) $cmp[0]['result']['profit'] > (float) $cmp[1]['result']['profit']);

        // C is incomplete → cannot be selected
        $c = $cmp[2]['id'];
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$c}/select")->assertStatus(409)->assertJsonPath('error_code', 'scenario_not_calculated');
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$b}/select")->assertOk();
        $this->assertSame(1, EstimationScenario::query()->where('estimation_id', $est)->where('is_selected', true)->count());
        $this->assertTrue(EstimationScenario::query()->find($b)->is_selected);
    }

    public function test_scenario_optimistic_lock(): void
    {
        [$est, $sc] = $this->estimation();
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');
        $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'name' => 'First'])->assertOk();
        $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'name' => 'Second'])->assertStatus(409)->assertJsonPath('error_code', 'stale_record');
        $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => 9, 'inputs' => ['legs' => [['condition' => 'sideways']]]])->assertStatus(422);
    }

    public function test_submit_approve_reject_lifecycle_and_self_approval_guard(): void
    {
        [$est, $sc] = $this->estimation();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertStatus(409)->assertJsonPath('error_code', 'no_selected_scenario');
        $this->completeScenario($est, $sc);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

        // Chartering cannot approve; submitted estimations are read-only.
        $this->postJson("/api/v1/estimations/{$est}/approve")->assertForbidden();
        $this->postJson("/api/v1/estimations/{$est}/scenarios")->assertStatus(409)->assertJsonPath('error_code', 'estimation_read_only');

        // Manager rejects with a reason; author reopens and resubmits; manager approves.
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/reject")->assertStatus(422);
        $this->postJson("/api/v1/estimations/{$est}/reject", ['reason' => 'Use eco speed'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->as($this->charterer)->postJson("/api/v1/estimations/{$est}/reopen")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve", ['comment' => 'Go'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by.id', $this->manager->id);

        // Approving twice is a 409, not a silent success.
        $this->postJson("/api/v1/estimations/{$est}/approve")->assertStatus(409)->assertJsonPath('error_code', 'invalid_status_transition');
    }

    public function test_self_approval_is_blocked_even_for_super_admin(): void
    {
        $admin = $this->userWithRole(UserRole::SuperAdmin);
        $this->as($admin);
        $enq = $this->postJson('/api/v1/enquiries', ['business_type' => 'voyage_charter', 'currency' => 'USD', 'rate_idea' => '55', 'rate_basis' => 'per_mt', 'quantity' => '2000',
            'ports' => [['port_id' => $this->ports[0], 'purpose' => 'load'], ['port_id' => $this->ports[1], 'purpose' => 'discharge']]])->json('data.id');
        $res = $this->postJson('/api/v1/estimations', ['enquiry_id' => $enq, 'vessel_id' => $this->vessel->id])->json('data');
        $this->completeScenario($res['id'], $res['scenarios'][0]['id']);
        $this->postJson("/api/v1/estimations/{$res['id']}/scenarios/{$res['scenarios'][0]['id']}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$res['id']}/submit")->assertOk();
        $this->postJson("/api/v1/estimations/{$res['id']}/approve")->assertForbidden()->assertJsonPath('error_code', 'self_approval_not_allowed');
    }

    public function test_approved_estimation_is_read_only_and_clone_preserves_lineage(): void
    {
        [$est, $sc] = $this->approvedEstimation();
        $s = $this->getJson("/api/v1/estimations/{$est}/scenarios/{$sc}")->json('data');

        $this->putJson("/api/v1/estimations/{$est}/scenarios/{$sc}", ['lock_version' => $s['lock_version'], 'name' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'estimation_read_only');
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/refresh-defaults", ['vessel' => true])->assertStatus(409);
        $this->putJson("/api/v1/estimations/{$est}", ['title' => 'x', 'lock_version' => 99])->assertStatus(409);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/calculate")->assertOk()->assertJsonPath('data.result.calculated_at', $s['result']['calculated_at']);

        $copy = $this->postJson("/api/v1/estimations/{$est}/clone")->assertCreated()->json('data');
        $this->assertSame('draft', $copy['status']);
        $this->assertSame($est, $copy['cloned_from']['id']);
        $this->assertSame($sc, $copy['scenarios'][0]['cloned_from_id']);
        $this->assertTrue($copy['scenarios'][0]['is_selected']);
        $this->assertSame($s['result']['profit'], $copy['scenarios'][0]['result']['profit']);
        $this->assertSame('approved', $this->getJson("/api/v1/estimations/{$est}")->json('data.status'));
    }

    public function test_estimation_permissions_and_404(): void
    {
        [$est] = $this->estimation();
        $this->getJson('/api/v1/estimations/999999')->assertNotFound();
        $this->getJson("/api/v1/estimations/{$est}/scenarios/999999")->assertNotFound();

        $this->actingAsRole(UserRole::ReadOnly);
        $this->getJson("/api/v1/estimations/{$est}")->assertOk();
        $this->postJson('/api/v1/estimations', ['vessel_id' => $this->vessel->id, 'estimation_type' => 'voyage_charter'])->assertForbidden();
        $this->postJson("/api/v1/estimations/{$est}/clone")->assertForbidden();
    }
}
