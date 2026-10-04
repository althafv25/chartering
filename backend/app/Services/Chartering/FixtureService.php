<?php

namespace App\Services\Chartering;

use App\Exceptions\BusinessRuleException;
use App\Models\Enquiry;
use App\Models\Fixture;
use App\Models\OfferRevision;
use App\Models\User;
use App\Models\Vessel;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Accepted offer revision + selected scenario of an approved estimation → fixture (snapshot). */
class FixtureService
{
    public function __construct(private readonly SequenceService $sequences, private readonly EnquiryWorkflow $workflow) {}

    /**
     * Idempotent: a second call returns the existing fixture.
     *
     * @return array{0: Fixture, 1: bool} [fixture, created]
     */
    public function fromRevision(OfferRevision $rev, User $actor): array
    {
        try {
            return DB::transaction(function () use ($rev, $actor) {
                $locked = OfferRevision::query()->with(['offer', 'scenario.estimation', 'scenario.result'])->lockForUpdate()->findOrFail($rev->id);
                if ($existing = Fixture::query()->where('offer_revision_id', $locked->id)->first()) {
                    return [$existing, false];
                }
                if ($locked->status !== 'accepted') {
                    throw new BusinessRuleException('A fixture can only be created from an accepted offer revision.', 'revision_not_accepted');
                }
                $scenario = $locked->scenario;
                if (! $scenario) {
                    throw new BusinessRuleException('Link the accepted revision to an estimation scenario before fixing.', 'scenario_required');
                }
                if (! $scenario->is_selected || $scenario->estimation->status !== 'approved') {
                    throw new BusinessRuleException("Scenario {$scenario->code} must be the selected scenario of an approved estimation.", 'scenario_not_approved');
                }

                $offer = $locked->offer;
                $enquiry = Enquiry::query()->lockForUpdate()->findOrFail($offer->enquiry_id);
                $vessel = Vessel::query()->findOrFail($offer->vessel_id);
                $year = now()->format('Y');

                $fixture = Fixture::query()->create([
                    'fixture_number' => $this->sequences->next("fixture:{$year}", "FX-{$year}-"),
                    'offer_revision_id' => $locked->id,
                    'estimation_scenario_id' => $scenario->id,
                    'enquiry_id' => $enquiry->id,
                    'vessel_id' => $vessel->id,
                    'charterer_company_id' => $enquiry->getAttribute('charterer_company_id') ?? $offer->getAttribute('counterparty_company_id'),
                    'owner_company_id' => $vessel->getAttribute('owner_company_id'),
                    'broker_company_id' => $locked->commissions['broker_company_id'] ?? $enquiry->getAttribute('broker_company_id'),
                    'fixture_date' => now()->toDateString(),
                    'business_type' => $enquiry->business_type,
                    'cargo_description' => $enquiry->getAttribute('cargo_description'),
                    'quantity' => $locked->quantity,
                    'quantity_unit' => $locked->getAttribute('quantity_unit'),
                    'laycan_from' => $locked->laycan_from,
                    'laycan_to' => $locked->laycan_to,
                    'rate' => $locked->rate,
                    'rate_basis' => $locked->rate_basis,
                    'currency' => $locked->currency,
                    'ports' => $locked->ports,
                    'commissions' => $locked->commissions,
                    'terms' => $locked->getAttribute('terms'),
                    'recap_snapshot' => [
                        'captured_at' => now()->toIso8601String(),
                        'offer' => ['number' => $offer->offer_number, 'revision_no' => $locked->revision_no, 'direction' => $locked->direction,
                            'terms' => $locked->only(OfferRevision::COMMERCIAL_FIELDS)],
                        'estimation' => ['number' => $scenario->estimation->estimation_number, 'type' => $scenario->estimation->estimation_type,
                            'currency' => $scenario->estimation->currency, 'scenario_code' => $scenario->code, 'scenario_name' => $scenario->name,
                            'inputs_hash' => $scenario->inputs_hash, 'calculation_version' => $scenario->calculation_version,
                            'inputs' => $scenario->inputs, 'results' => $scenario->result?->only(['total_days', 'gross_revenue', 'net_revenue', 'voyage_costs', 'profit', 'tce_per_day', 'breakeven_rate'])],
                        'vessel' => $scenario->vessel_snapshot,
                        'enquiry' => ['number' => $enquiry->enquiry_number, 'business_type' => $enquiry->business_type],
                    ],
                    'created_by' => $actor->id,
                ]);

                $this->workflow->advance($enquiry, 'fixed', $actor, "fixture {$fixture->fixture_number}");
                activity('fixtures')->performedOn($fixture)->causedBy($actor)->event('created_from_offer')
                    ->withProperties(['attributes' => ['offer' => $offer->offer_number, 'revision_no' => $locked->revision_no, 'scenario' => $scenario->code]])
                    ->log("Fixture created from {$offer->offer_number} rev {$locked->revision_no}");

                return [$fixture, true];
            });
        } catch (QueryException $e) {
            // Concurrent double submission hit the unique offer_revision_id: return the winner's fixture.
            if (($e->errorInfo[1] ?? null) === 1062 && ($existing = Fixture::query()->where('offer_revision_id', $rev->id)->first())) {
                return [$existing, false];
            }
            throw $e;
        }
    }
}
