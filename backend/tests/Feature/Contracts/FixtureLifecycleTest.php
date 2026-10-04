<?php

namespace Tests\Feature\Contracts;

use App\Enums\UserRole;
use App\Models\Voyage;

class FixtureLifecycleTest extends ContractTestCase
{
    public function test_submit_approve_with_segregation_of_duties_and_commercial_terms_locked(): void
    {
        $id = $this->draftFixture();
        $f = $this->getJson("/api/v1/fixtures/{$id}")->assertOk()->assertJsonPath('data.status', 'draft')->json('data');

        // Draft: non-commercial edits only; rate is not accepted as input.
        $this->putJson("/api/v1/fixtures/{$id}", ['lock_version' => $f['lock_version'], 'terms' => 'GENCON 94 amended', 'rate' => '1'])->assertOk()
            ->assertJsonPath('data.terms', 'GENCON 94 amended')->assertJsonPath('data.rate', '55.0000');

        $this->postJson("/api/v1/fixtures/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->putJson("/api/v1/fixtures/{$id}", ['lock_version' => $f['lock_version'] + 1, 'terms' => 'x'])->assertStatus(409)->assertJsonPath('error_code', 'fixture_read_only');
        $this->postJson("/api/v1/fixtures/{$id}/approve")->assertForbidden(); // chartering cannot approve

        $this->as($this->manager)->postJson("/api/v1/fixtures/{$id}/reject")->assertStatus(422);
        $this->postJson("/api/v1/fixtures/{$id}/reject", ['reason' => 'Check laycan'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->as($this->charterer)->postJson("/api/v1/fixtures/{$id}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$id}/approve", ['comment' => 'Subjects lifted'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by.id', $this->manager->id);
        $this->getJson("/api/v1/fixtures/{$id}/activity")->assertOk();
    }

    public function test_failing_a_fixture_reopens_the_enquiry(): void
    {
        $id = $this->approvedFixture();
        $enq = $this->getJson("/api/v1/fixtures/{$id}")->json('data.enquiry_id');
        $this->getJson("/api/v1/enquiries/{$enq}")->assertJsonPath('data.status', 'fixed');

        $this->actingAsRole(UserRole::ReadOnly);
        $this->postJson("/api/v1/fixtures/{$id}/fail", ['reason' => 'Subjects failed'])->assertForbidden();

        $this->as($this->charterer)->postJson("/api/v1/fixtures/{$id}/fail", ['reason' => 'Subjects failed'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->getJson("/api/v1/enquiries/{$enq}")->assertJsonPath('data.status', 'evaluating');
        $this->postJson("/api/v1/fixtures/{$id}/convert-to-contract")->assertStatus(409)->assertJsonPath('error_code', 'fixture_not_approved');
    }

    public function test_fixture_to_voyage_is_idempotent_and_links_contract(): void
    {
        $id = $this->approvedFixture();
        $this->postJson("/api/v1/fixtures/{$id}/convert-to-voyage")->assertForbidden(); // needs operations.voyages.create

        $contract = $this->postJson("/api/v1/fixtures/{$id}/convert-to-contract")->assertCreated()->json('data.id');
        $this->postJson("/api/v1/fixtures/{$id}/convert-to-contract")->assertOk()->assertJsonPath('data.id', $contract);

        $this->actingAsRole(UserRole::Operations);
        $v = $this->postJson("/api/v1/fixtures/{$id}/convert-to-voyage")->assertCreated()
            ->assertJsonPath('data.conversion_type', 'fixture')->assertJsonPath('data.fixture_id', $id)->assertJsonPath('data.contract_id', $contract)
            ->assertJsonPath('data.snapshots.0.type', 'initial')->assertJsonPath('data.snapshots.0.payload.fixture.rate', '55.0000')->json('data');
        $this->assertNotNull($v['estimation_scenario_id']);
        $this->postJson("/api/v1/fixtures/{$id}/convert-to-voyage")->assertOk()->assertJsonPath('data.id', $v['id']);
        $this->assertSame(1, Voyage::query()->count());

        // Fixture with a voyage can no longer be cancelled.
        $this->as($this->charterer)->postJson("/api/v1/fixtures/{$id}/cancel", ['reason' => 'Changed mind'])->assertStatus(409)->assertJsonPath('error_code', 'fixture_in_use');
    }

    public function test_draft_fixture_cannot_be_converted(): void
    {
        $id = $this->draftFixture();
        $this->postJson("/api/v1/fixtures/{$id}/convert-to-contract")->assertStatus(409)->assertJsonPath('error_code', 'fixture_not_approved');
        $this->actingAsRole(UserRole::Operations);
        $this->postJson("/api/v1/fixtures/{$id}/convert-to-voyage")->assertStatus(409);
    }
}
