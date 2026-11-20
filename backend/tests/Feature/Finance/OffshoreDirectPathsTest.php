<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\OffshoreActivityType;
use App\Models\OffshoreLocation;
use App\Models\Port;
use Illuminate\Support\Carbon;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * Pins the three ways offshore work can be run, as described in docs/offshore-data-flow-paths.md:
 *   A. full commercial chain (covered by OffshoreDataflowWalkthroughTest),
 *   B. voyage created directly from an approved estimation (no fixture), with a contract for pricing,
 *   C. contract and project only (no enquiry, estimation, fixture or voyage),
 * plus the limit that an activity cannot be priced, and so cannot be verified, without a contract.
 */
class OffshoreDirectPathsTest extends OperationsTestCase
{
    private OffshoreLocation $field;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $base = Port::query()->find($this->ports[0]);
        $this->field = OffshoreLocation::query()->create(['code' => 'ALPHA-1', 'name' => 'Alpha Field', 'field_name' => 'Alpha', 'latitude' => '25.5', 'longitude' => '55.2',
            'timezone' => 'Asia/Dubai', 'nearest_port_id' => $base->id]);
    }

    /** @return array<string, mixed> */
    private function directContract(): array
    {
        $type = fn (string $code) => OffshoreActivityType::query()->where('code', $code)->value('id');
        $this->as($this->charterer);
        $c = $this->postJson('/api/v1/contracts', [
            'contract_type' => 'offshore_charter', 'title' => 'Alpha Field supply support (direct)', 'customer_company_id' => $this->client->id, 'vessel_id' => $this->vessel->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'currency' => 'USD', 'payment_terms_days' => 30,
            'rates' => [
                ['rate_type' => 'day_rate', 'amount' => '14500', 'currency' => 'USD', 'unit' => 'per_day', 'description' => 'Day rate'],
                ['rate_type' => 'standby_day_rate', 'amount' => '9000', 'currency' => 'USD', 'unit' => 'per_day', 'description' => 'Standby'],
                ['rate_type' => 'hourly', 'amount' => '650', 'currency' => 'USD', 'unit' => 'per_hour', 'offshore_activity_type_id' => $type('ROV'), 'description' => 'ROV hourly'],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $c['status']);
        $this->postJson("/api/v1/contracts/{$c['id']}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/contracts/{$c['id']}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$c['id']}/activate")->assertOk();

        return $c;
    }

    /** @return array<string, mixed> */
    private function activity(array $over): array
    {
        $type = OffshoreActivityType::query()->where('code', $over['type'])->value('id');
        unset($over['type']);

        return $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $this->vessel->id, 'offshore_location_id' => $this->field->id,
            'offshore_activity_type_id' => $type, ...$over])->assertCreated()->json('data');
    }

    public function test_project_without_a_contract_cannot_price_or_verify_billable_hours(): void
    {
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);
        $project = $this->postJson('/api/v1/offshore-projects', ['code' => 'SPOT-1', 'name' => 'Spot job', 'client_company_id' => $this->client->id,
            'offshore_location_id' => $this->field->id, 'start_date' => '2026-10-01'])->assertCreated()->json('data');
        $this->assertNull($project['contract_id']);

        // Billable time with no contract: no price, and the checker cannot verify it.
        $billable = $this->activity(['type' => 'SUPPLY', 'offshore_project_id' => $project['id'], 'description' => 'Supply run',
            'start_at' => '2026-10-02T08:00', 'end_at' => '2026-10-04T08:00', 'billable_hours' => '48']);
        $this->assertNull($billable['revenue_amount']);
        $this->assertContains('no_contract', $billable['warnings']);
        $this->postJson("/api/v1/offshore-activities/{$billable['id']}/submit")->assertOk();
        $this->as($ops2)->postJson("/api/v1/offshore-activities/{$billable['id']}/verify", ['comment' => 'OK'])->assertStatus(409);

        // Non-billable time can be recorded and verified; it creates no revenue.
        $this->as($this->ops);
        $wait = $this->activity(['type' => 'WAIT', 'offshore_project_id' => $project['id'], 'description' => 'Waiting on weather',
            'start_at' => '2026-10-05T08:00', 'end_at' => '2026-10-05T14:00']);
        $this->assertEquals([6, 0], [$wait['non_billable_hours'], $wait['billable_hours']]);
        $this->postJson("/api/v1/offshore-activities/{$wait['id']}/submit")->assertOk();
        $this->as($ops2)->postJson("/api/v1/offshore-activities/{$wait['id']}/verify", ['comment' => 'OK'])->assertOk();
        $finance = $this->userWithRole(UserRole::Finance);
        $this->assertSame([], $this->as($finance)->getJson('/api/v1/voyage-revenues?per_page=50')->json('data'));
    }

    public function test_contract_and_project_only_prices_activities_without_a_voyage(): void
    {
        $contract = $this->directContract();
        $ops2 = $this->userWithRole(UserRole::Operations);
        $this->as($this->ops);

        // The project is created first without a contract; the contract is attached before any activity exists.
        $project = $this->postJson('/api/v1/offshore-projects', ['code' => 'DIR-1', 'name' => 'Direct project', 'client_company_id' => $this->client->id,
            'offshore_location_id' => $this->field->id])->assertCreated()->json('data');
        $project = $this->putJson("/api/v1/offshore-projects/{$project['id']}", ['lock_version' => $project['lock_version'], 'contract_id' => $contract['id']])->assertOk()->json('data');
        $this->assertSame($contract['id'], $project['contract_id']);

        $run = $this->activity(['type' => 'SUPPLY', 'offshore_project_id' => $project['id'], 'description' => 'Supply run 1',
            'start_at' => '2026-10-02T08:00', 'end_at' => '2026-10-04T08:00', 'billable_hours' => '48']);
        $rov = $this->activity(['type' => 'ROV', 'offshore_project_id' => $project['id'], 'description' => 'ROV inspection',
            'start_at' => '2026-10-04T08:00', 'end_at' => '2026-10-04T15:30', 'billable_hours' => '7.5']);
        $this->assertSame(['29000.00', '4875.00'], [$run['revenue_amount'], $rov['revenue_amount']]);
        foreach ([$run, $rov] as $a) {
            $this->postJson("/api/v1/offshore-activities/{$a['id']}/submit")->assertOk();
            $this->as($ops2)->postJson("/api/v1/offshore-activities/{$a['id']}/verify", ['comment' => 'OK'])->assertOk();
            $this->as($this->ops);
        }

        // Revenue lines exist and belong to the contract, but to no voyage.
        $finance = $this->userWithRole(UserRole::Finance);
        $lines = $this->as($finance)->getJson("/api/v1/voyage-revenues?contract_id={$contract['id']}&per_page=50")->json('data');
        $this->assertCount(2, $lines);
        $this->assertSame([null, null], array_column($lines, 'voyage_id'));
        $this->assertEqualsCanonicalizing(['29000.00', '4875.00'], array_column($lines, 'amount'));
        $this->assertSame(['confirmed', 'confirmed'], array_column($lines, 'status'));

        // The contract cannot be swapped once activities exist.
        $this->as($this->ops)->putJson("/api/v1/offshore-projects/{$project['id']}", ['lock_version' => $project['lock_version'], 'contract_id' => null])->assertStatus(409);

        // Invoice through the API: pick the revenue lines of the contract.
        $inv = $this->as($finance)->postJson('/api/v1/invoices', ['invoice_type' => 'offshore_service', 'customer_company_id' => $this->client->id, 'contract_id' => $contract['id'],
            'issue_date' => '2026-10-08', 'due_date' => '2026-11-07', 'currency' => 'USD', 'revenue_ids' => array_column($lines, 'id')])->assertCreated()->json('data');
        $this->assertSame(['33875.00', 2, null], [$inv['total'], count($inv['lines']), $inv['voyage_id']]);
    }

    public function test_voyage_created_directly_from_an_approved_estimation_with_a_contract_for_pricing(): void
    {
        $enq = $this->enquiry(['business_type' => 'offshore_charter', 'rate_basis' => 'per_day', 'rate_idea' => '14500', 'period_days' => '10']);
        [$est] = $this->approvedEstimation($enq);
        $contract = $this->directContract();
        $ops2 = $this->userWithRole(UserRole::Operations);

        // No fixture and no contract on the voyage.
        $this->as($this->ops);
        $this->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'x'])->assertStatus(422);
        $voyage = $this->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'Spot offshore job, no fixture'])->assertCreated()->json('data');
        $v = $voyage['id'];
        $this->assertSame(['offshore', 'direct_estimation', null, null], [$voyage['operation_type'], $voyage['conversion_type'], $voyage['fixture_id'], $voyage['contract_id']]);

        $project = $this->postJson('/api/v1/offshore-projects', ['code' => 'SPOT-2', 'name' => 'Spot job with contract', 'contract_id' => $contract['id'],
            'offshore_location_id' => $this->field->id])->assertCreated()->json('data');
        $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => 'nominated', 'at' => '2026-10-01T10:00'])->assertOk();
        $this->postJson("/api/v1/voyages/{$v}/transition", ['status' => 'offshore_operation', 'at' => '2026-10-02T08:00'])->assertOk();

        $a = $this->activity(['type' => 'SUPPLY', 'voyage_id' => $v, 'offshore_project_id' => $project['id'], 'description' => 'Supply run 1',
            'start_at' => '2026-10-02T08:00', 'end_at' => '2026-10-04T08:00', 'billable_hours' => '48']);
        $this->assertSame('29000.00', $a['revenue_amount']);
        $this->postJson("/api/v1/offshore-activities/{$a['id']}/submit")->assertOk();
        $this->as($ops2)->postJson("/api/v1/offshore-activities/{$a['id']}/verify", ['comment' => 'OK'])->assertOk();

        // The revenue line is on the voyage, so it shows in the voyage P&L against the estimate and the UI can invoice it.
        $finance = $this->userWithRole(UserRole::Finance);
        $lines = $this->as($finance)->getJson("/api/v1/voyage-revenues?voyage_id={$v}&per_page=50")->json('data');
        $this->assertCount(1, $lines);
        $this->assertSame([$v, '29000.00', 'confirmed'], [$lines[0]['voyage_id'], $lines[0]['amount'], $lines[0]['status']]);
        $pnl = $this->getJson("/api/v1/voyages/{$v}/financials")->assertOk()->json('data');
        $this->assertTrue($pnl['estimate_available']);
        $rows = collect($pnl['rows'])->keyBy('metric');
        $this->assertSame('29000.00', $rows['gross_revenue']['actual']);
        $this->assertNotNull($rows['gross_revenue']['estimate']);
    }
}
