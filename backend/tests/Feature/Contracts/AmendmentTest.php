<?php

namespace Tests\Feature\Contracts;

use App\Models\ContractRate;

class AmendmentTest extends ContractTestCase
{
    public function test_amendment_creates_new_version_and_preserves_history(): void
    {
        $id = $this->approvedContract();
        $this->postJson("/api/v1/contracts/{$id}/activate")->assertOk();

        $this->postJson("/api/v1/contracts/{$id}/amendments", ['effective_date' => '2027-02-01', 'summary' => 'Nothing'])->assertStatus(422)->assertJsonPath('error_code', 'empty_amendment');
        $this->postJson("/api/v1/contracts/{$id}/amendments", ['effective_date' => '2026-10-01', 'summary' => 'Backdated', 'header' => ['end_date' => '2027-06-30']])
            ->assertStatus(422)->assertJsonPath('error_code', 'amendment_backdated');
        $this->postJson("/api/v1/contracts/{$id}/amendments", ['effective_date' => '2027-02-01', 'summary' => 'Bad', 'header' => ['currency' => 'EUR']])->assertStatus(422);

        $a = $this->postJson("/api/v1/contracts/{$id}/amendments", [
            'effective_date' => '2027-02-01', 'summary' => 'Extension option 1 declared; rate escalation',
            'header' => ['end_date' => '2027-10-31', 'extension_options' => '1 × 6 months declared'],
            'rates' => [['rate_type' => 'freight_per_mt', 'amount' => '57.5']],
        ])->assertCreated()->assertJsonPath('data.amendment_no', 1)->assertJsonPath('data.status', 'draft')->json('data');
        $this->postJson("/api/v1/contracts/{$id}/amendments", ['effective_date' => '2027-03-01', 'summary' => 'Second', 'header' => ['title' => 'x']])
            ->assertStatus(409)->assertJsonPath('error_code', 'amendment_open');

        $this->putJson("/api/v1/contracts/{$id}/amendments/{$a['id']}", ['lock_version' => $a['lock_version'], 'summary' => 'Extension option 1 declared; escalation 4.5%'])->assertOk();
        $this->postJson("/api/v1/contracts/{$id}/amendments/{$a['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/v1/contracts/{$id}/amendments/{$a['id']}/approve")->assertForbidden(); // chartering lacks approve

        $this->as($this->manager)->postJson("/api/v1/contracts/{$id}/amendments/{$a['id']}/approve", ['comment' => 'OK'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.resulting_version', 2)
            ->assertJsonPath('data.changes.header.end_date.from', '2027-04-30')->assertJsonPath('data.changes.header.end_date.to', '2027-10-31');

        $c = $this->getJson("/api/v1/contracts/{$id}")->assertOk()->assertJsonPath('data.current_version', 2)
            ->assertJsonPath('data.end_date', '2027-10-31')->json('data');
        $this->assertCount(2, $c['versions']);
        $this->assertSame('2027-04-30', $c['versions'][0]['header']['end_date']); // v1 snapshot untouched
        $this->assertSame('55.0000', ContractRate::query()->where('contract_id', $id)->where('version_no', 1)->value('amount'));
        $this->assertSame('57.5000', ContractRate::query()->where('contract_id', $id)->where('version_no', 2)->value('amount'));

        // Effective rate follows the version effective on the date.
        $at = fn ($d) => $this->getJson("/api/v1/contracts/{$id}/effective-rates?date={$d}")->json('data');
        $this->assertSame(1, $at('2027-01-15')['version_no']);
        $this->assertSame('55.0000', $at('2027-01-15')['rates'][0]['amount']);
        $this->assertSame(2, $at('2027-02-01')['version_no']);
        $this->assertSame('57.5000', $at('2027-06-01')['rates'][0]['amount']);
        $this->assertSame([], $at('2027-11-01')['rates']);

        // Approved amendments cannot be edited; next amendment cannot predate v2.
        $this->as($this->charterer)->putJson("/api/v1/contracts/{$id}/amendments/{$a['id']}", ['lock_version' => 99, 'summary' => 'xxxxx'])
            ->assertStatus(409)->assertJsonPath('error_code', 'amendment_read_only');
        $this->postJson("/api/v1/contracts/{$id}/amendments", ['effective_date' => '2027-01-20', 'summary' => 'Too early', 'header' => ['title' => 'x']])
            ->assertStatus(422)->assertJsonPath('error_code', 'amendment_backdated');
    }

    public function test_reject_withdraw_and_draft_contracts_are_not_amendable(): void
    {
        $fx = $this->approvedFixture();
        $draft = $this->postJson("/api/v1/fixtures/{$fx}/convert-to-contract")->json('data.id');
        $this->postJson("/api/v1/contracts/{$draft}/amendments", ['effective_date' => '2027-01-01', 'summary' => 'Too soon', 'header' => ['title' => 'x']])
            ->assertStatus(409)->assertJsonPath('error_code', 'contract_not_amendable');

        $id = $this->approvedContract();
        $a = $this->postJson("/api/v1/contracts/{$id}/amendments", ['effective_date' => '2027-01-01', 'summary' => 'Payment terms', 'header' => ['payment_terms_days' => 15]])->json('data.id');
        $this->postJson("/api/v1/contracts/{$id}/amendments/{$a}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/contracts/{$id}/amendments/{$a}/reject", ['reason' => 'Charterer did not agree'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->getJson("/api/v1/contracts/{$id}")->assertJsonPath('data.current_version', 1)->assertJsonPath('data.payment_terms_days', 30);

        $b = $this->as($this->charterer)->postJson("/api/v1/contracts/{$id}/amendments", ['effective_date' => '2027-01-01', 'summary' => 'Retry', 'header' => ['payment_terms_days' => 20]])->json('data.id');
        $this->postJson("/api/v1/contracts/{$id}/amendments/{$b}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->getJson("/api/v1/contracts/{$id}")->assertJsonCount(2, 'data.amendments');
    }
}
