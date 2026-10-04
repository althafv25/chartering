<?php

namespace Tests\Feature\Contracts;

use App\Enums\UserRole;
use App\Models\OfferRevision;
use App\Models\User;
use Tests\Feature\Chartering\CharteringTestCase;

abstract class ContractTestCase extends CharteringTestCase
{
    protected User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commercial = $this->userWithRole(UserRole::Commercial);
    }

    /** Enquiry → approved estimation → offer accepted → draft fixture. @return int fixture id */
    protected function draftFixture(): int
    {
        $enq = $this->enquiry();
        [, $sc] = $this->approvedEstimation($enq);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $this->vessel->id, 'estimation_scenario_id' => $sc])->json('data.id');
        $rev = OfferRevision::query()->where('offer_id', $offer)->value('id');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$rev}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$rev}/accept")->assertOk();

        return $this->postJson("/api/v1/offers/{$offer}/revisions/{$rev}/convert-to-fixture")->assertCreated()->json('data.id');
    }

    protected function approvedFixture(): int
    {
        $id = $this->draftFixture();
        $this->as($this->charterer)->postJson("/api/v1/fixtures/{$id}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/fixtures/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($this->charterer);

        return $id;
    }

    /** Fixture → contract with dates, submitted by chartering and approved by management. */
    protected function approvedContract(string $start = '2026-11-01', string $end = '2027-04-30'): int
    {
        $fx = $this->approvedFixture();
        $c = $this->postJson("/api/v1/fixtures/{$fx}/convert-to-contract")->assertCreated()->json('data');
        $this->putJson("/api/v1/contracts/{$c['id']}", ['lock_version' => $c['lock_version'], 'start_date' => $start, 'end_date' => $end, 'payment_terms_days' => 30])->assertOk();
        $this->postJson("/api/v1/contracts/{$c['id']}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/contracts/{$c['id']}/approve")->assertOk();
        $this->as($this->charterer);

        return $c['id'];
    }
}
