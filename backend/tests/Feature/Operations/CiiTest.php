<?php

namespace Tests\Feature\Operations;

use App\Enums\UserRole;
use App\Models\CiiFormulaSet;
use App\Models\User;
use App\Models\Vessel;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Masters\MastersTestCase;

class CiiTest extends MastersTestCase
{
    private Vessel $vessel;

    private CiiFormulaSet $formulaSet;

    private User $marineOps;

    private User $marineOps2;

    protected function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->marineOps = $this->userWithRole(UserRole::MarineOperations);
        $this->marineOps2 = $this->userWithRole(UserRole::MarineOperations);
        $this->vessel = Vessel::query()->create([
            'code' => 'PSV1', 'name' => 'Test PSV', 'vessel_type_id' => $this->psvTypeId(), 'dwt_mt' => '4200.000', 'gt' => '2800.00',
        ]);
        $this->formulaSet = CiiFormulaSet::query()->create([
            'code' => 'test-set', 'name' => 'Test formula set', 'status' => 'draft', 'effective_date' => '2023-01-01',
            'parameters' => [
                'reference_lines' => ['PSV' => ['a' => 69.72, 'c' => 0.195]],
                'rating_boundaries' => ['A' => 0.50, 'B' => 0.72, 'C' => 0.83, 'D' => 1.00],
                'emission_factors' => ['HFO' => 3.151, 'MGO' => 3.206],
            ],
        ]);
    }

    public function test_calculation_produces_rating_and_is_idempotent_until_inputs_change(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $year = $this->postJson('/api/v1/cii-vessel-years', [
            'vessel_id' => $this->vessel->id, 'reporting_year' => 2026, 'formula_set_id' => $this->formulaSet->id,
            'capacity_metric' => 'DWT', 'capacity_value' => '4200', 'distance_sailed_nm' => '12000',
            'fuel_consumption' => ['HFO' => '150.000', 'MGO' => '20.000'],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $year['status']);

        $calc = $this->postJson("/api/v1/cii-vessel-years/{$year['id']}/calculate")->assertOk()->json('data');
        $this->assertSame('calculated', $calc['status']);
        $this->assertNotNull($calc['attained_cii']);
        $this->assertNotNull($calc['rating']);
        $this->assertContains($calc['rating'], ['A', 'B', 'C', 'D', 'E']);
        $this->assertSame('1.0.0', $calc['calculation_version']);

        // Recalculating identical inputs reproduces the same result (deterministic, not random).
        $again = $this->postJson("/api/v1/cii-vessel-years/{$year['id']}/calculate")->assertOk()->json('data');
        $this->assertSame($calc['attained_cii'], $again['attained_cii']);
        $this->assertSame($calc['rating'], $again['rating']);

        // Changing an input invalidates the previous result back to draft.
        $updated = $this->putJson("/api/v1/cii-vessel-years/{$year['id']}", ['lock_version' => $again['lock_version'], 'distance_sailed_nm' => '13000'])
            ->assertOk()->json('data');
        $this->assertSame('draft', $updated['status']);
        $this->assertNull($updated['attained_cii']);
    }

    public function test_missing_emission_factor_or_reference_line_is_reported_as_incomplete(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $year = $this->postJson('/api/v1/cii-vessel-years', [
            'vessel_id' => $this->vessel->id, 'reporting_year' => 2026, 'formula_set_id' => $this->formulaSet->id,
            'capacity_metric' => 'DWT', 'capacity_value' => '4200', 'distance_sailed_nm' => '12000',
            'fuel_consumption' => ['LNG' => '50.000'], // no Cf configured for LNG in this test's formula set
        ])->assertCreated()->json('data');

        $res = $this->postJson("/api/v1/cii-vessel-years/{$year['id']}/calculate")->assertStatus(422);
        $this->assertSame('cii_calculation_incomplete', $res->json('error_code'));
        $this->assertStringContainsString('LNG', $res->json('errors.issues.0'));
    }

    public function test_verification_requires_a_different_user_and_freezes_the_record(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $year = $this->postJson('/api/v1/cii-vessel-years', [
            'vessel_id' => $this->vessel->id, 'reporting_year' => 2026, 'formula_set_id' => $this->formulaSet->id,
            'capacity_metric' => 'DWT', 'capacity_value' => '4200', 'distance_sailed_nm' => '12000',
            'fuel_consumption' => ['HFO' => '150.000'],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/cii-vessel-years/{$year['id']}/calculate")->assertOk();

        // Same user cannot verify their own entry.
        $this->postJson("/api/v1/cii-vessel-years/{$year['id']}/verify")->assertStatus(409)->assertJsonPath('error_code', 'self_approval_not_allowed');

        $verified = $this->as($this->marineOps2)->postJson("/api/v1/cii-vessel-years/{$year['id']}/verify")->assertOk()->assertJsonPath('data.status', 'verified')->json('data');

        // A verified record is read-only; must be reopened first.
        $this->as($this->marineOps2)->putJson("/api/v1/cii-vessel-years/{$year['id']}", ['lock_version' => $verified['lock_version'], 'distance_sailed_nm' => '9000'])
            ->assertStatus(409)->assertJsonPath('error_code', 'cii_read_only');

        $this->as($this->marineOps2)->postJson("/api/v1/cii-vessel-years/{$year['id']}/reopen", ['reason' => 'Correcting fuel figures'])
            ->assertOk()->assertJsonPath('data.status', 'calculated');
    }

    public function test_duplicate_vessel_year_is_refused(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $payload = ['vessel_id' => $this->vessel->id, 'reporting_year' => 2026, 'formula_set_id' => $this->formulaSet->id,
            'capacity_metric' => 'DWT', 'capacity_value' => '4200', 'distance_sailed_nm' => '12000', 'fuel_consumption' => ['HFO' => '100']];
        $this->postJson('/api/v1/cii-vessel-years', $payload)->assertCreated();
        $this->postJson('/api/v1/cii-vessel-years', $payload)->assertStatus(409)->assertJsonPath('error_code', 'cii_duplicate_year');
    }

    public function test_fleet_summary_counts_by_rating(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $year = $this->postJson('/api/v1/cii-vessel-years', [
            'vessel_id' => $this->vessel->id, 'reporting_year' => 2026, 'formula_set_id' => $this->formulaSet->id,
            'capacity_metric' => 'DWT', 'capacity_value' => '4200', 'distance_sailed_nm' => '12000', 'fuel_consumption' => ['HFO' => '150'],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/cii-vessel-years/{$year['id']}/calculate")->assertOk();

        $summary = $this->getJson('/api/v1/cii/fleet-summary?reporting_year=2026')->assertOk()->json('data');
        $this->assertSame(1, $summary['vessel_count']);
        $this->assertSame(2026, $summary['reporting_year']);
    }

    public function test_formula_set_lifecycle_and_retired_sets_are_read_only(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $fs = $this->postJson('/api/v1/cii/formula-sets', [
            'code' => 'imo-2024', 'name' => 'IMO 2024 set', 'effective_date' => '2024-01-01',
            'parameters' => ['reference_lines' => ['PSV' => ['a' => 70, 'c' => 0.2]], 'rating_boundaries' => ['A' => 0.5, 'B' => 0.72, 'C' => 0.83, 'D' => 1.0], 'emission_factors' => ['HFO' => 3.151]],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $fs['status']);

        $this->postJson("/api/v1/cii/formula-sets/{$fs['id']}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
        $this->postJson("/api/v1/cii/formula-sets/{$fs['id']}/verify")->assertStatus(409); // already verified, cannot verify again
        $this->postJson("/api/v1/cii/formula-sets/{$fs['id']}/retire")->assertOk()->assertJsonPath('data.status', 'retired');
        $this->putJson("/api/v1/cii/formula-sets/{$fs['id']}", [
            'name' => 'x', 'effective_date' => '2024-01-01',
            'parameters' => ['reference_lines' => ['PSV' => ['a' => 70, 'c' => 0.2]], 'rating_boundaries' => ['A' => 0.5, 'B' => 0.72, 'C' => 0.83, 'D' => 1.0], 'emission_factors' => ['HFO' => 3.151]],
        ])->assertStatus(409)->assertJsonPath('error_code', 'cii_formula_set_read_only');
    }

    public function test_permissions(): void
    {
        $readOnly = $this->userWithRole(UserRole::ReadOnly);
        $this->as($readOnly)->getJson('/api/v1/cii-vessel-years')->assertForbidden();
        $this->as($readOnly)->postJson('/api/v1/cii-vessel-years', [])->assertForbidden();

        $operations = $this->userWithRole(UserRole::Operations);
        $this->as($operations)->getJson('/api/v1/cii-vessel-years')->assertOk(); // view-only per the role matrix
        $this->as($operations)->postJson('/api/v1/cii-vessel-years', [])->assertForbidden();
    }
}
