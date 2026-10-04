<?php

namespace Tests\Feature\Contracts;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\OffshoreActivityType;
use App\Models\UserNotification;
use App\Services\Contracts\ContractRateResolver;
use Illuminate\Support\Facades\Artisan;

class ContractLifecycleTest extends ContractTestCase
{
    public function test_contract_from_fixture_snapshots_rate_and_requires_dates_before_review(): void
    {
        $fx = $this->approvedFixture();
        $c = $this->postJson("/api/v1/fixtures/{$fx}/convert-to-contract")->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.contract_type', 'voyage_charter')
            ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.customer.id', $this->client->id)->json('data');
        $this->assertStringStartsWith('CON-', $c['contract_number']);

        $detail = $this->getJson("/api/v1/contracts/{$c['id']}")->assertOk()->json('data');
        $this->assertSame('freight_per_mt', $detail['rates'][0]['rate_type']);
        $this->assertSame('55.0000', $detail['rates'][0]['amount']);

        $this->postJson("/api/v1/contracts/{$c['id']}/submit")->assertStatus(409)->assertJsonPath('error_code', 'contract_incomplete');
        $this->putJson("/api/v1/contracts/{$c['id']}", ['lock_version' => $c['lock_version'], 'currency' => 'EUR'])->assertStatus(409)->assertJsonPath('error_code', 'fixture_terms_locked');
        $this->putJson("/api/v1/contracts/{$c['id']}", ['lock_version' => $c['lock_version'], 'start_date' => '2026-11-01', 'end_date' => '2026-10-01'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_full_lifecycle_versions_and_read_only_after_approval(): void
    {
        $id = $this->approvedContract();
        $c = $this->getJson("/api/v1/contracts/{$id}")->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.current_version', 1)->assertJsonPath('data.versions.0.effective_from', '2026-11-01')->json('data');

        $this->putJson("/api/v1/contracts/{$id}", ['lock_version' => $c['lock_version'], 'remarks' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'contract_read_only');
        $this->putJson("/api/v1/contracts/{$id}/rates", ['lock_version' => $c['lock_version'], 'rates' => [['rate_type' => 'freight_per_mt', 'amount' => '60']]])
            ->assertStatus(409)->assertJsonPath('error_code', 'contract_read_only');

        $this->postJson("/api/v1/contracts/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/contracts/{$id}/cancel", ['reason' => 'nope'])->assertStatus(409);
        $this->postJson("/api/v1/contracts/{$id}/complete", ['note' => 'Voyage done'])->assertOk()->assertJsonPath('data.status', 'completed');

        $v1 = ContractVersion::query()->where('contract_id', $id)->first();
        $this->expectException(BusinessRuleException::class);
        $v1->forceFill(['effective_from' => '2020-01-01'])->save();
    }

    public function test_manual_offshore_contract_with_rates_validation_and_self_approval_block(): void
    {
        $this->as($this->commercial);
        $standby = OffshoreActivityType::query()->where('code', 'STANDBY')->value('id');
        $base = ['contract_type' => 'offshore_charter', 'title' => 'Field support 2027', 'customer_company_id' => $this->client->id, 'currency' => 'USD',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31'];

        $this->postJson('/api/v1/contracts', [...$base, 'vessel_id' => null])->assertStatus(422)->assertJsonPath('error_code', 'vessel_required');
        $this->postJson('/api/v1/contracts', [...$base, 'vessel_id' => $this->vessel->id, 'rates' => [
            ['rate_type' => 'day_rate', 'amount' => '14500', 'unit' => 'per_hour'],
        ]])->assertStatus(422)->assertJsonValidationErrors('rates.0.unit');
        $this->postJson('/api/v1/contracts', [...$base, 'vessel_id' => $this->vessel->id, 'rates' => [
            ['rate_type' => 'day_rate', 'amount' => '14500', 'effective_to' => '2027-06-30'],
            ['rate_type' => 'day_rate', 'amount' => '15000', 'effective_from' => '2027-06-01'],
        ]])->assertStatus(422)->assertJsonValidationErrors('rates.1.effective_from');

        $id = $this->postJson('/api/v1/contracts', [...$base, 'vessel_id' => $this->vessel->id, 'rates' => [
            ['rate_type' => 'day_rate', 'amount' => '14500', 'effective_to' => '2027-06-30'],
            ['rate_type' => 'day_rate', 'amount' => '15000', 'effective_from' => '2027-07-01'],
            ['rate_type' => 'standby_day_rate', 'amount' => '9000'],
            ['rate_type' => 'standby_day_rate', 'amount' => '8000', 'offshore_activity_type_id' => $standby],
            ['rate_type' => 'mobilization_fee', 'amount' => 25000],
        ], 'clauses' => [['clause_ref' => '1', 'title' => 'Hire', 'body' => 'Payable monthly in arrears.']]])
            ->assertCreated()->json('data');
        $this->assertSame('25000.0000', collect($id['rates'])->firstWhere('rate_type', 'mobilization_fee')['amount']);
        $id = $id['id'];

        $this->postJson("/api/v1/contracts/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->postJson("/api/v1/contracts/{$id}/approve")->assertForbidden()->assertJsonPath('error_code', 'self_approval_not_allowed');
        $this->as($this->manager)->postJson("/api/v1/contracts/{$id}/approve")->assertOk();

        $this->as($this->commercial);
        $rates = fn ($date) => collect($this->getJson("/api/v1/contracts/{$id}/effective-rates?date={$date}")->assertOk()->json('data.rates'));
        $this->assertSame('14500.0000', $rates('2027-03-01')->firstWhere('rate_type', 'day_rate')['amount']);
        $this->assertSame('15000.0000', $rates('2027-08-01')->firstWhere('rate_type', 'day_rate')['amount']);
        $this->assertCount(0, $rates('2026-12-31'));  // before the contract
        $this->assertCount(0, $rates('2028-01-01'));  // after the end date

        $resolver = app(ContractRateResolver::class);
        $contract = Contract::query()->find($id);
        $this->assertSame('8000.0000', $resolver->rateFor($contract, '2027-03-01', 'standby_day_rate', $standby)->amount);
        $this->assertSame('9000.0000', $resolver->rateFor($contract, '2027-03-01', 'standby_day_rate')->amount);
    }

    public function test_rates_hidden_without_rates_permission(): void
    {
        $id = $this->approvedContract();
        $this->actingAsRole(UserRole::ReadOnly);
        $res = $this->getJson("/api/v1/contracts/{$id}")->assertOk()->assertJsonPath('data.rates_visible', false);
        $this->assertArrayNotHasKey('rates', $res->json('data'));
        $this->getJson("/api/v1/contracts/{$id}/effective-rates?date=2026-12-01")->assertForbidden();
        $this->postJson('/api/v1/contracts', ['contract_type' => 'service', 'title' => 'x', 'customer_company_id' => $this->client->id, 'currency' => 'USD'])->assertForbidden();
        $this->getJson('/api/v1/contracts/999999')->assertNotFound();
    }

    public function test_expiry_command_expires_and_notifies_once(): void
    {
        $ending = $this->approvedContract('2026-01-01', now()->addDays(10)->toDateString());
        $this->postJson("/api/v1/contracts/{$ending}/activate")->assertOk();
        $c2 = $this->as($this->commercial)->postJson('/api/v1/contracts', ['contract_type' => 'service', 'title' => 'Old service', 'customer_company_id' => $this->client->id,
            'currency' => 'USD', 'start_date' => '2025-01-01', 'end_date' => now()->subDay()->toDateString(), 'rates' => [['rate_type' => 'lump_sum', 'amount' => '1000']]])->json('data.id');
        $this->postJson("/api/v1/contracts/{$c2}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/contracts/{$c2}/approve")->assertOk();

        Artisan::call('contracts:check-expiry');
        Artisan::call('contracts:check-expiry'); // idempotent

        $this->getJson("/api/v1/contracts/{$c2}")->assertJsonPath('data.status', 'expired');
        $this->getJson("/api/v1/contracts/{$ending}")->assertJsonPath('data.status', 'active');
        $this->assertSame(1, UserNotification::query()->where('user_id', $this->charterer->id)->where('category', 'contract_expiry')->count());
        $this->getJson('/api/v1/contracts?expiring_within_days=30')->assertJsonPath('meta.total', 1);
    }
}
