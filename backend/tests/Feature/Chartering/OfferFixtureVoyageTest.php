<?php

namespace Tests\Feature\Chartering;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\EstimationScenario;
use App\Models\Fixture;
use App\Models\OfferRevision;
use App\Models\Voyage;
use Spatie\Activitylog\Models\Activity;

class OfferFixtureVoyageTest extends CharteringTestCase
{
    /** @return array{0:int,1:int,2:int} [enquiry, offer, scenario] */
    private function offer(bool $approved = true): array
    {
        $enq = $this->enquiry();
        [$est, $sc] = $approved ? $this->approvedEstimation($enq) : $this->estimation($enq);
        $offer = $this->postJson('/api/v1/offers', ['enquiry_id' => $enq, 'vessel_id' => $this->vessel->id, 'estimation_scenario_id' => $sc])
            ->assertCreated()->assertJsonPath('data.latest_revision.revision_no', 1)->assertJsonPath('data.latest_revision.status', 'draft')
            ->assertJsonPath('data.latest_revision.rate', '55.0000')->assertJsonPath('data.latest_revision.scenario.id', $sc)
            ->json('data.id');

        return [$enq, $offer, $sc];
    }

    public function test_revision_history_is_sequential_and_immutable(): void
    {
        [$enq, $offer] = $this->offer();
        $r1 = OfferRevision::query()->where('offer_id', $offer)->value('id');

        $this->putJson("/api/v1/offers/{$offer}/revisions/{$r1}", ['rate' => '56', 'terms' => 'WOG'])->assertOk()->assertJsonPath('data.rate', '56.0000');
        $this->postJson("/api/v1/offers/{$offer}/revisions", ['rate' => '57'])->assertStatus(409)->assertJsonPath('error_code', 'draft_exists');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/receive")->assertStatus(409)->assertJsonPath('error_code', 'invalid_direction');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/send")->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.is_immutable', true);
        $this->getJson("/api/v1/enquiries/{$enq}")->assertJsonPath('data.status', 'offered');

        // Immutable: API and model guard both refuse changes.
        $this->putJson("/api/v1/offers/{$offer}/revisions/{$r1}", ['rate' => '99'])->assertStatus(409)->assertJsonPath('error_code', 'revision_immutable');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/send")->assertStatus(409);
        try {
            OfferRevision::query()->find($r1)->forceFill(['rate' => '1'])->save();
            $this->fail('Model guard must block edits');
        } catch (BusinessRuleException $e) {
            $this->assertSame('revision_immutable', $e->errorCode);
        }
        $this->assertSame('56.0000', OfferRevision::query()->find($r1)->rate);

        // Counter offer: revision 2 inherits terms, recorded as received, supersedes revision 1.
        $r2 = $this->postJson("/api/v1/offers/{$offer}/revisions", ['direction' => 'inbound', 'rate' => '52'])->assertCreated()
            ->assertJsonPath('data.revision_no', 2)->assertJsonPath('data.terms', 'WOG')->json('data.id');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r2}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame('superseded', OfferRevision::query()->find($r1)->status);
        $this->assertSame('56.0000', OfferRevision::query()->find($r1)->rate); // history never overwritten

        $r3 = $this->postJson("/api/v1/offers/{$offer}/revisions", ['rate' => '54'])->assertCreated()->assertJsonPath('data.revision_no', 3)->json('data.id');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r3}/send")->assertOk();

        $this->getJson("/api/v1/offers/{$offer}/revisions/{$r1}/diff/{$r3}")->assertOk()->assertJsonFragment(['field' => 'rate', 'from' => '56.0000', 'to' => '54.0000']);
        $this->getJson("/api/v1/offers/{$offer}/activity")->assertOk();
    }

    public function test_only_one_revision_can_be_accepted_and_acceptance_closes_the_offer(): void
    {
        [, $offer] = $this->offer();
        $r1 = OfferRevision::query()->where('offer_id', $offer)->value('id');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/accept")->assertStatus(409)->assertJsonPath('error_code', 'invalid_status_transition'); // draft
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/send")->assertOk();
        $r2 = $this->postJson("/api/v1/offers/{$offer}/revisions", ['direction' => 'inbound', 'rate' => '53'])->json('data.id');

        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame('superseded', OfferRevision::query()->find($r2)->status); // pending draft closed
        $this->getJson("/api/v1/offers/{$offer}")->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/v1/offers/{$offer}/revisions", ['rate' => '50'])->assertStatus(409)->assertJsonPath('error_code', 'offer_closed');
        $this->assertSame(1, OfferRevision::query()->where('offer_id', $offer)->where('status', 'accepted')->count());
    }

    public function test_reject_and_withdraw(): void
    {
        [, $offer] = $this->offer();
        $r1 = OfferRevision::query()->where('offer_id', $offer)->value('id');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/reject")->assertStatus(422);
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/reject", ['reason' => 'Charterer declined'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/offers/{$offer}/withdraw", ['reason' => 'Vessel no longer open'])->assertOk()->assertJsonPath('data.status', 'withdrawn');
    }

    public function test_pricing_scenario_must_match_enquiry_and_vessel(): void
    {
        [, , $sc] = $this->offer();
        $other = $this->enquiry();
        $this->postJson('/api/v1/offers', ['enquiry_id' => $other, 'vessel_id' => $this->vessel->id, 'estimation_scenario_id' => $sc])
            ->assertStatus(422)->assertJsonPath('error_code', 'invalid_scenario');
    }

    public function test_fixture_only_from_accepted_revision_and_idempotent(): void
    {
        [$enq, $offer, $sc] = $this->offer();
        $r1 = OfferRevision::query()->where('offer_id', $offer)->value('id');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/convert-to-fixture")->assertStatus(409)->assertJsonPath('error_code', 'revision_not_accepted');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/accept")->assertOk();

        $fx = $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/convert-to-fixture")->assertCreated()
            ->assertJsonPath('data.rate', '55.0000')->assertJsonPath('data.estimation_scenario_id', $sc)->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertStringStartsWith('FX-', $fx['fixture_number']);
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/convert-to-fixture")->assertOk()->assertJsonPath('data.id', $fx['id']);
        $this->assertSame(1, Fixture::query()->count());

        $snapshot = $this->getJson("/api/v1/fixtures/{$fx['id']}")->assertOk()->json('data.recap_snapshot');
        $this->assertSame('A', $snapshot['estimation']['scenario_code']);
        $this->assertSame('GMS Endeavour', $snapshot['vessel']['name']);
        $this->getJson("/api/v1/enquiries/{$enq}")->assertJsonPath('data.status', 'fixed');

        // Direct conversion is refused once a fixture exists for the scenario.
        $est = EstimationScenario::query()->find($sc)->estimation_id;
        $this->actingAsRole(UserRole::Operations);
        $this->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'Should not be allowed now'])->assertStatus(409)->assertJsonPath('error_code', 'fixture_exists');
    }

    public function test_fixture_requires_approved_selected_scenario(): void
    {
        [, $offer] = $this->offer(approved: false);
        $r1 = OfferRevision::query()->where('offer_id', $offer)->value('id');
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/accept")->assertOk();
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/convert-to-fixture")->assertStatus(409)->assertJsonPath('error_code', 'scenario_not_approved');
    }

    public function test_direct_estimation_to_voyage(): void
    {
        [$est, $sc] = $this->estimation(null, []);
        $this->actingAsRole(UserRole::Operations);
        $this->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'Internal positioning trip'])->assertStatus(409)->assertJsonPath('error_code', 'estimation_not_approved');

        $this->as($this->charterer);
        $this->completeScenario($est, $sc);
        $this->postJson("/api/v1/estimations/{$est}/scenarios/{$sc}/select")->assertOk();
        $this->postJson("/api/v1/estimations/{$est}/submit")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/estimations/{$est}/approve")->assertOk();

        $this->as($this->charterer)->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'Internal positioning trip'])->assertForbidden(); // needs operations.voyages.create

        $ops = $this->actingAsRole(UserRole::Operations);
        $this->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'short'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $v = $this->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'Internal positioning trip'])->assertCreated()
            ->assertJsonPath('data.conversion_type', 'direct_estimation')->assertJsonPath('data.fixture_id', null)
            ->assertJsonPath('data.estimation_scenario_id', $sc)->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.snapshots.0.type', 'initial')->json('data');
        $this->assertSame('GMS1-'.now()->format('y').'-001', $v['voyage_number']);
        $this->assertSame('Internal positioning trip', $v['snapshots'][0]['payload']['direct_reason']);
        $this->assertArrayHasKey('profit', $v['snapshots'][0]['payload']['results']);

        // Idempotent: same voyage, no duplicate.
        $this->postJson("/api/v1/estimations/{$est}/convert-to-voyage", ['reason' => 'Internal positioning trip'])->assertOk()->assertJsonPath('data.id', $v['id']);
        $this->assertSame(1, Voyage::query()->count());
        $this->getJson("/api/v1/voyages/{$v['id']}")->assertOk()->assertJsonPath('data.voyage_number', $v['voyage_number'])->assertJsonPath('data.snapshots.0.inputs_hash', $v['snapshots'][0]['inputs_hash']);
        $this->assertTrue(Activity::query()->where('event', 'created_direct')->where('causer_id', $ops->id)->exists());
    }

    public function test_offer_permissions(): void
    {
        [, $offer] = $this->offer();
        $r1 = OfferRevision::query()->where('offer_id', $offer)->value('id');
        $this->actingAsRole(UserRole::Finance);
        $this->getJson("/api/v1/offers/{$offer}")->assertOk();
        $this->postJson("/api/v1/offers/{$offer}/revisions/{$r1}/send")->assertForbidden();
        $this->postJson("/api/v1/offers/{$offer}/revisions", ['rate' => '1'])->assertForbidden();
        $this->getJson("/api/v1/offers/{$offer}/revisions/999999/diff/1")->assertNotFound();
    }
}
