<?php

namespace Tests\Feature\Chartering;

use App\Enums\UserRole;

class EstimationDeleteTest extends CharteringTestCase
{
    public function test_draft_without_offer_fixture_or_voyage_is_soft_deleted_and_keeps_its_scenarios(): void
    {
        $enq = $this->enquiry();
        [$est, $sc] = $this->estimation($enq);

        $this->deleteJson("/api/v1/estimations/{$est}")->assertOk()->assertJsonPath('message', 'Estimation deleted.');

        $this->getJson("/api/v1/estimations/{$est}")->assertNotFound();
        $this->assertSoftDeleted('estimations', ['id' => $est]);
        $this->assertDatabaseHas('estimation_scenarios', ['id' => $sc]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $est, 'event' => 'deleted']);

        // The enquiry was blocked from deletion only because of this estimation.
        $this->deleteJson("/api/v1/enquiries/{$enq}")->assertOk();
    }

    public function test_submitted_or_approved_estimation_cannot_be_deleted(): void
    {
        [$est, $sc] = $this->estimation();
        $this->completeScenario($est, $sc);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();

        $this->deleteJson("/api/v1/estimations/{$est}")->assertStatus(409)->assertJsonPath('error_code', 'invalid_status_transition');

        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();
        $this->as($this->charterer)->deleteJson("/api/v1/estimations/{$est}")->assertStatus(409)->assertJsonPath('error_code', 'invalid_status_transition');
        $this->assertDatabaseHas('estimations', ['id' => $est, 'deleted_at' => null]);
    }

    public function test_draft_used_by_an_offer_cannot_be_deleted(): void
    {
        $enq = $this->enquiry();
        [$est, $sc] = $this->estimation($enq);
        $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $this->vessel->id, 'estimation_scenario_id' => $sc])->assertCreated();

        $this->deleteJson("/api/v1/estimations/{$est}")->assertStatus(409)->assertJsonPath('error_code', 'estimation_in_use');
        $this->assertDatabaseHas('estimations', ['id' => $est, 'deleted_at' => null]);
    }

    public function test_rejected_estimation_can_be_reopened_as_draft_and_then_deleted(): void
    {
        [$est, $sc] = $this->estimation();
        $this->completeScenario($est, $sc);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/reject", ['reason' => 'Bunker price too low'])->assertOk();

        $this->as($this->charterer)->deleteJson("/api/v1/estimations/{$est}")->assertStatus(409);

        $this->postJson("/api/v1/estimations/{$est}/reopen")->assertOk();
        $this->deleteJson("/api/v1/estimations/{$est}")->assertOk();
        $this->assertSoftDeleted('estimations', ['id' => $est]);
    }

    public function test_delete_permission_is_required(): void
    {
        [$est] = $this->estimation();

        $this->actingAsRole(UserRole::ReadOnly);
        $this->deleteJson("/api/v1/estimations/{$est}")->assertForbidden();
        $this->assertDatabaseHas('estimations', ['id' => $est, 'deleted_at' => null]);
    }
}
