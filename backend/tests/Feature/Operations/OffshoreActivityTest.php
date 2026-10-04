<?php

namespace Tests\Feature\Operations;

use App\Enums\UserRole;
use App\Models\ContractAmendment;
use App\Models\OffshoreActivityType;
use App\Models\OffshoreLocation;
use App\Models\User;
use App\Services\SettingsService;

class OffshoreActivityTest extends OperationsTestCase
{
    private int $contract;

    private User $ops2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops2 = $this->userWithRole(UserRole::Operations);
        $this->contract = $this->offshoreContract();
        $this->as($this->ops);
    }

    /** Active offshore charter with day, standby, ROV hourly and mob/demob fees. */
    private function offshoreContract(): int
    {
        $rov = $this->type('ROV');
        $c = $this->as($this->charterer)->postJson('/api/v1/contracts', [
            'contract_type' => 'offshore_charter', 'title' => 'Field support', 'customer_company_id' => $this->client->id, 'vessel_id' => $this->vessel->id,
            'currency' => 'USD', 'start_date' => '2026-10-01', 'end_date' => '2027-03-31',
            'rates' => [
                ['rate_type' => 'day_rate', 'amount' => '14500', 'currency' => 'USD', 'unit' => 'per_day'],
                ['rate_type' => 'standby_day_rate', 'amount' => '9000', 'currency' => 'USD', 'unit' => 'per_day'],
                ['rate_type' => 'hourly', 'amount' => '800', 'currency' => 'USD', 'unit' => 'per_hour', 'offshore_activity_type_id' => $rov],
                ['rate_type' => 'mobilization_fee', 'amount' => '25000', 'currency' => 'USD', 'unit' => 'lump_sum'],
            ],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/contracts/{$c}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/contracts/{$c}/approve")->assertOk();
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$c}/activate")->assertOk();

        return $c;
    }

    private function type(string $code): int
    {
        return (int) OffshoreActivityType::query()->where('code', $code)->value('id');
    }

    /** @return array<string, mixed> */
    private function activity(array $data, int $status = 201): array
    {
        return $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $this->vessel->id, 'contract_id' => $this->contract,
            'offshore_activity_type_id' => $this->type('SUPPLY'), ...$data])->assertStatus($status)->json($status === 201 ? 'data' : null);
    }

    public function test_rate_snapshot_and_hourly_proration_with_default_split(): void
    {
        // 06:00 → 12:00 next day Dubai = 30 h, SUPPLY is billable by default → 1.25 d × 14 500
        $a = $this->activity(['start_at' => '2026-10-10T06:00', 'end_at' => '2026-10-11T12:00']);
        $this->assertSame('30.0000', $a['billable_hours']);
        $this->assertSame('18125.00', $a['revenue_amount']);
        $this->assertSame('USD', $a['currency']);
        $this->assertSame(1, $a['contract_version_no']);
        $this->assertSame('day_rate', $a['rate_snapshot'][0]['rate_type']);
        $this->assertNotNull($a['rate_snapshot'][0]['contract_rate_id']);
        $this->assertSame('hourly|standby_rate', $a['calculation_basis']);
        $this->assertSame($this->client->id, $a['client']['id']);
        $this->assertSame('2026-10-10T02:00:00+00:00', $a['start_at']);
        $this->assertMatchesRegularExpression('/^OA-2026-\d{5}$/', $a['activity_number']);
    }

    public function test_hours_overlap_and_time_validation(): void
    {
        $this->activity(['start_at' => '2026-10-10T06:00', 'end_at' => '2026-10-10T18:00', 'billable_hours' => '10', 'standby_hours' => '3'], 422);
        $this->activity(['start_at' => '2026-10-10T06:00', 'end_at' => '2026-10-10T05:00'], 422);
        $this->activity(['start_at' => '2026-10-20T06:00', 'end_at' => '2026-10-22T05:00'], 422); // future
        $this->activity(['start_at' => '2026-10-10T06:00', 'end_at' => '2026-10-10T18:00']);
        $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $this->vessel->id, 'offshore_activity_type_id' => $this->type('SUPPLY'),
            'start_at' => '2026-10-10T17:00', 'end_at' => '2026-10-10T20:00'])->assertStatus(422)->assertJsonPath('error_code', 'activity_overlap');
    }

    public function test_standby_specific_hourly_rate_and_setting_change(): void
    {
        // 20 h billable + 6 h standby in 26 h: 20/24 × 14 500 + 6/24 × 9 000
        $a = $this->activity(['start_at' => '2026-10-10T00:00', 'end_at' => '2026-10-11T02:00', 'billable_hours' => '20', 'standby_hours' => '6']);
        $this->assertSame('14333.33', $a['revenue_amount']);
        $this->assertSame('standby_day_rate', $a['rate_snapshot'][1]['rate_type']);

        // Activity-specific hourly rate wins for ROV.
        $rov = $this->activity(['offshore_activity_type_id' => $this->type('ROV'), 'start_at' => '2026-10-12T08:00', 'end_at' => '2026-10-12T16:00']);
        $this->assertSame('6400.00', $rov['revenue_amount']);
        $this->assertSame('per_hour', $rov['rate_snapshot'][0]['unit']);

        // BR-OA-01 switch: per started half day, re-priced on submit (26 h billable+standby priced separately).
        app(SettingsService::class)->update(['offshore.day_rate_proration' => 'half_day'], $this->manager);
        $s = $this->postJson("/api/v1/offshore-activities/{$a['id']}/submit")->assertOk()->json('data');
        $this->assertSame('half_day|standby_rate', $s['calculation_basis']);
        $this->assertSame('19000.00', $s['revenue_amount']); // 20 h → 2 half days = 1 d × 14 500; 6 h standby → 1 half day = 0.5 d × 9 000
    }

    public function test_mobilization_fee_charged_once_per_contract(): void
    {
        $mob = $this->activity(['offshore_activity_type_id' => $this->type('MOB'), 'start_at' => '2026-10-05T06:00', 'end_at' => '2026-10-05T18:00']);
        $this->assertSame('32250.00', $mob['revenue_amount']); // 0.5 d × 14 500 + 25 000
        $again = $this->activity(['offshore_activity_type_id' => $this->type('MOB'), 'start_at' => '2026-10-06T06:00', 'end_at' => '2026-10-06T18:00']);
        $this->assertSame('7250.00', $again['revenue_amount']);
        $this->assertCount(1, $again['rate_snapshot']);
    }

    public function test_verification_requires_another_user_and_freezes_the_activity(): void
    {
        $a = $this->activity(['start_at' => '2026-10-10T06:00', 'end_at' => '2026-10-10T18:00']);
        $url = "/api/v1/offshore-activities/{$a['id']}";
        $this->postJson("{$url}/verify")->assertStatus(409); // not submitted
        $this->postJson("{$url}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->putJson($url, ['lock_version' => $a['lock_version'] + 1, 'description' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'activity_read_only');
        $this->postJson("{$url}/verify")->assertStatus(403)->assertJsonPath('error_code', 'self_approval_not_allowed');

        $this->as($this->ops2)->postJson("{$url}/reject", ['reason' => 'Hours do not match log'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->as($this->ops)->postJson("{$url}/submit")->assertOk();
        $this->as($this->ops2)->postJson("{$url}/verify")->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.verified_by.id', $this->ops2->id);
        $this->deleteJson($url)->assertStatus(409);

        $list = $this->getJson("/api/v1/offshore-activities?contract_id={$this->contract}")->assertOk()->json();
        $this->assertSame('12.0000', $list['summary']['billable_hours']);
        $this->assertSame([['currency' => 'USD', 'amount' => '7250.00']], $list['summary']['revenue']);
    }

    public function test_unpriced_activity_cannot_be_verified_and_rates_are_hidden_without_permission(): void
    {
        $a = $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $this->vessel->id, 'offshore_activity_type_id' => $this->type('SUPPLY'),
            'start_at' => '2026-10-10T06:00', 'end_at' => '2026-10-10T18:00'])->assertCreated()->json('data');
        $this->assertSame(['no_contract'], $a['warnings']);
        $this->assertNull($a['revenue_amount']);
        $this->postJson("/api/v1/offshore-activities/{$a['id']}/submit")->assertOk();
        $this->as($this->ops2)->postJson("/api/v1/offshore-activities/{$a['id']}/verify")->assertStatus(409)->assertJsonPath('error_code', 'activity_not_priced');

        $this->as($this->ops)->activity(['start_at' => '2026-10-11T06:00', 'end_at' => '2026-10-11T18:00']);
        $marine = $this->userWithRole(UserRole::MarineOperations);
        $res = $this->as($marine)->getJson('/api/v1/offshore-activities')->assertOk()->json();
        $this->assertNull($res['data'][0]['revenue_amount']);
        $this->assertNull($res['data'][0]['rate_snapshot']);
        $this->assertNull($res['summary']['revenue']);
        $this->as($this->userWithRole(UserRole::ReadOnly))->postJson('/api/v1/offshore-activities', [])->assertForbidden();
    }

    public function test_amended_rates_apply_from_their_effective_date(): void
    {
        $this->as($this->charterer)->postJson("/api/v1/contracts/{$this->contract}/amendments", [
            'effective_date' => '2026-10-15', 'summary' => 'Day rate escalation',
            'rates' => [['rate_type' => 'day_rate', 'amount' => '16000', 'currency' => 'USD', 'unit' => 'per_day']],
        ])->assertCreated();
        $aid = ContractAmendment::query()->value('id');
        $this->postJson("/api/v1/contracts/{$this->contract}/amendments/{$aid}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/contracts/{$this->contract}/amendments/{$aid}/approve")->assertOk();

        $this->as($this->ops);
        $before = $this->activity(['start_at' => '2026-10-14T00:00', 'end_at' => '2026-10-15T00:00']);
        $after = $this->activity(['start_at' => '2026-10-16T00:00', 'end_at' => '2026-10-17T00:00']);
        $this->assertSame(['14500.00', 1], [$before['revenue_amount'], $before['contract_version_no']]);
        $this->assertSame(['16000.00', 2], [$after['revenue_amount'], $after['contract_version_no']]);
    }

    public function test_projects_link_contract_and_client_and_feed_activities(): void
    {
        $loc = OffshoreLocation::query()->create(['code' => 'FLDA', 'name' => 'Field A', 'latitude' => '25.1', 'longitude' => '53.2', 'timezone' => 'Asia/Dubai']);
        $this->postJson('/api/v1/offshore-projects', ['code' => 'P1', 'name' => 'x'])->assertStatus(422);
        $p = $this->postJson('/api/v1/offshore-projects', ['code' => 'FA-2026', 'name' => 'Field A campaign', 'contract_id' => $this->contract,
            'offshore_location_id' => $loc->id, 'start_date' => '2026-10-01'])->assertCreated()->json('data');
        $this->assertSame($this->client->id, $p['client']['id']);

        $a = $this->postJson('/api/v1/offshore-activities', ['vessel_id' => $this->vessel->id, 'offshore_project_id' => $p['id'],
            'offshore_activity_type_id' => $this->type('SUPPLY'), 'start_at' => '2026-10-10T06:00', 'end_at' => '2026-10-10T18:00'])->assertCreated()->json('data');
        $this->assertSame($this->contract, $a['contract']['id']);
        $this->assertSame('Field A', $a['location']['name']);
        $this->assertSame('7250.00', $a['revenue_amount']);

        $this->getJson("/api/v1/offshore-projects/{$p['id']}")->assertOk()->assertJsonPath('data.activities_count', 1)->assertJsonPath('data.summary.billable_hours', '12.0000');
        $this->putJson("/api/v1/offshore-projects/{$p['id']}", ['lock_version' => $p['lock_version'], 'contract_id' => null])->assertStatus(409);
    }
}
