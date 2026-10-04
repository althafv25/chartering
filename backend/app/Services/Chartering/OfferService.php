<?php

namespace App\Services\Chartering;

use App\DTO\Chartering\OfferTermsData;
use App\Exceptions\BusinessRuleException;
use App\Models\Enquiry;
use App\Models\EstimationScenario;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\User;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/**
 * Offers and their immutable revision history.
 *   draft → sent (outbound) | received (inbound)    — content frozen from here on
 *   sent/received → accepted | rejected | superseded (by a later sent/received revision)
 * At most one accepted revision per offer (service + unique generated column).
 */
class OfferService
{
    public function __construct(private readonly SequenceService $sequences, private readonly EnquiryWorkflow $workflow) {}

    public function create(Enquiry $enquiry, int $vesselId, ?int $counterpartyId, OfferTermsData $terms, User $actor): Offer
    {
        $this->workflow->assertAcceptsWork($enquiry, 'an offer');
        $scenario = $this->scenarioFor($enquiry->id, $vesselId, $terms->terms['estimation_scenario_id'] ?? null);

        return DB::transaction(function () use ($enquiry, $vesselId, $counterpartyId, $terms, $actor, $scenario) {
            $year = now()->format('Y');
            $offer = Offer::query()->create([
                'offer_number' => $this->sequences->next("offer:{$year}", "OFF-{$year}-"),
                'enquiry_id' => $enquiry->id,
                'vessel_id' => $vesselId,
                'counterparty_company_id' => $counterpartyId ?? $enquiry->getAttribute('charterer_company_id') ?? $enquiry->getAttribute('broker_company_id'),
                'created_by' => $actor->id,
            ]);
            $base = $this->initialTerms($enquiry, $scenario);
            $this->newRevision($offer, [...$base, ...$terms->terms], 1, $actor);

            return $offer;
        });
    }

    public function addRevision(Offer $offer, OfferTermsData $terms, User $actor): OfferRevision
    {
        return DB::transaction(function () use ($offer, $terms, $actor) {
            $locked = $this->lockOpen($offer);
            if ($locked->revisions()->where('status', 'draft')->exists()) {
                throw new BusinessRuleException('A draft revision already exists. Edit or send it before starting another.', 'draft_exists');
            }
            if ($terms->has('estimation_scenario_id')) {
                $this->scenarioFor($locked->enquiry_id, $locked->vessel_id, $terms->terms['estimation_scenario_id']);
            }
            $latest = $locked->revisions()->orderByDesc('revision_no')->first();
            $base = $latest ? $latest->only(OfferRevision::COMMERCIAL_FIELDS) : [];
            // Format dates for re-insertion.
            foreach (['laycan_from', 'laycan_to', 'valid_until'] as $d) {
                $base[$d] = $latest?->{$d}?->toDateString();
            }
            $next = (int) $locked->revisions()->max('revision_no') + 1;

            return $this->newRevision($locked, [...$base, 'direction' => 'outbound', ...$terms->terms], $next, $actor);
        });
    }

    public function updateDraft(OfferRevision $rev, OfferTermsData $terms): OfferRevision
    {
        return DB::transaction(function () use ($rev, $terms) {
            $this->lockOpen($rev->offer);
            $locked = OfferRevision::query()->lockForUpdate()->findOrFail($rev->id);
            if ($locked->isImmutable()) {
                throw new BusinessRuleException("Revision {$locked->revision_no} is {$locked->status} and can no longer be changed. Create a new revision.", 'revision_immutable');
            }
            if ($terms->has('estimation_scenario_id')) {
                $this->scenarioFor($rev->offer->enquiry_id, $rev->offer->vessel_id, $terms->terms['estimation_scenario_id']);
            }
            $locked->fill($terms->terms)->save();

            return $locked;
        });
    }

    public function send(OfferRevision $rev, User $actor): OfferRevision
    {
        return $this->release($rev, 'outbound', 'sent', $actor);
    }

    public function recordReceived(OfferRevision $rev, User $actor): OfferRevision
    {
        return $this->release($rev, 'inbound', 'received', $actor);
    }

    public function accept(OfferRevision $rev, User $actor, ?string $note): OfferRevision
    {
        return DB::transaction(function () use ($rev, $actor, $note) {
            $offer = $this->lockOpen($rev->offer);
            $locked = OfferRevision::query()->lockForUpdate()->findOrFail($rev->id);
            if (! $locked->isOpen()) {
                throw new BusinessRuleException("Only a sent or received revision can be accepted (revision {$locked->revision_no} is {$locked->status}).", 'invalid_status_transition');
            }
            if (OfferRevision::query()->where('offer_id', $offer->id)->where('status', 'accepted')->exists()) {
                throw new BusinessRuleException('This offer already has an accepted revision.', 'already_accepted');
            }

            // Supersede every other open or draft revision so nothing can be sent after acceptance.
            OfferRevision::query()->where('offer_id', $offer->id)->whereKeyNot($locked->id)->whereIn('status', ['sent', 'received', 'draft'])
                ->get()->each(fn (OfferRevision $r) => $r->forceFill(['status' => 'superseded', 'decided_at' => now(), 'decided_by' => $actor->id])->save());
            $locked->forceFill(['status' => 'accepted', 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_reason' => $note])->save();
            $offer->forceFill(['status' => 'accepted'])->save();

            activity('offers')->performedOn($offer)->causedBy($actor)->event('accepted')
                ->withProperties(['attributes' => ['revision_no' => $locked->revision_no, 'rate' => $locked->rate, 'currency' => $locked->currency]])
                ->log("Revision {$locked->revision_no} accepted");

            return $locked;
        });
    }

    public function reject(OfferRevision $rev, User $actor, string $reason): OfferRevision
    {
        return DB::transaction(function () use ($rev, $actor, $reason) {
            $offer = $this->lockOpen($rev->offer);
            $locked = OfferRevision::query()->lockForUpdate()->findOrFail($rev->id);
            if (! $locked->isOpen()) {
                throw new BusinessRuleException("Only a sent or received revision can be rejected (revision {$locked->revision_no} is {$locked->status}).", 'invalid_status_transition');
            }
            $locked->forceFill(['status' => 'rejected', 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_reason' => $reason])->save();
            activity('offers')->performedOn($offer)->causedBy($actor)->event('revision_rejected')
                ->withProperties(['attributes' => ['revision_no' => $locked->revision_no, 'reason' => $reason]])->log("Revision {$locked->revision_no} rejected");

            return $locked;
        });
    }

    public function withdraw(Offer $offer, User $actor, string $reason): Offer
    {
        return DB::transaction(function () use ($offer, $actor, $reason) {
            $locked = $this->lockOpen($offer);
            $locked->forceFill(['status' => 'withdrawn'])->save();
            OfferRevision::query()->where('offer_id', $locked->id)->whereIn('status', ['draft', 'sent', 'received'])->get()
                ->each(fn (OfferRevision $r) => $r->forceFill(['status' => 'superseded', 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_reason' => 'Offer withdrawn'])->save());
            activity('offers')->performedOn($locked)->causedBy($actor)->event('withdrawn')->withProperties(['attributes' => ['reason' => $reason]])->log('Offer withdrawn');

            return $locked;
        });
    }

    /** @return list<array{field:string, from:mixed, to:mixed}> */
    public function diff(OfferRevision $a, OfferRevision $b): array
    {
        $norm = function (OfferRevision $r, string $f) {
            $v = $r->getAttribute($f);

            return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        };
        $out = [];
        foreach (OfferRevision::COMMERCIAL_FIELDS as $f) {
            [$x, $y] = [$norm($a, $f), $norm($b, $f)];
            if ($x != $y) {
                $out[] = ['field' => $f, 'from' => $x, 'to' => $y];
            }
        }

        return $out;
    }

    private function release(OfferRevision $rev, string $direction, string $to, User $actor): OfferRevision
    {
        return DB::transaction(function () use ($rev, $direction, $to, $actor) {
            $offer = $this->lockOpen($rev->offer);
            $locked = OfferRevision::query()->lockForUpdate()->findOrFail($rev->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleException("Revision {$locked->revision_no} is already {$locked->status}.", 'invalid_status_transition');
            }
            if ($locked->direction !== $direction) {
                throw new BusinessRuleException($direction === 'outbound' ? 'Only outbound revisions can be sent.' : 'Only inbound (counter) revisions can be recorded as received.', 'invalid_direction');
            }

            OfferRevision::query()->where('offer_id', $offer->id)->whereKeyNot($locked->id)->whereIn('status', ['sent', 'received'])->get()
                ->each(fn (OfferRevision $r) => $r->forceFill(['status' => 'superseded', 'decided_at' => now(), 'decided_by' => $actor->id])->save());
            $locked->forceFill(['status' => $to, $to === 'sent' ? 'sent_at' : 'received_at' => now()])->save();

            $enquiry = Enquiry::query()->lockForUpdate()->findOrFail($offer->enquiry_id);
            $this->workflow->advance($enquiry, 'offered', $actor, "offer {$offer->offer_number} rev {$locked->revision_no} {$to}");
            activity('offers')->performedOn($offer)->causedBy($actor)->event("revision_{$to}")
                ->withProperties(['attributes' => ['revision_no' => $locked->revision_no, 'rate' => $locked->rate, 'currency' => $locked->currency]])
                ->log("Revision {$locked->revision_no} {$to}");

            return $locked;
        });
    }

    /** @param array<string, mixed> $terms */
    private function newRevision(Offer $offer, array $terms, int $no, User $actor): OfferRevision
    {
        foreach (['rate', 'rate_basis', 'currency'] as $req) {
            if (! isset($terms[$req]) || $terms[$req] === '') {
                throw new BusinessRuleException("The {$req} is required for an offer revision.", 'validation_failed', [$req => ['Required.']], 422);
            }
        }

        return OfferRevision::query()->create([
            ...array_intersect_key($terms, array_flip(OfferRevision::COMMERCIAL_FIELDS)),
            'direction' => $terms['direction'] ?? 'outbound',
            'ports' => $terms['ports'] ?? [],
            'commissions' => $terms['commissions'] ?? ['address_pct' => '0', 'brokerage_pct' => '0', 'other_pct' => '0', 'broker_company_id' => null],
            'offer_id' => $offer->id,
            'revision_no' => $no,
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);
    }

    /** Defaults for revision 1 copied from the enquiry and the pricing scenario (snapshot). @return array<string, mixed> */
    private function initialTerms(Enquiry $enquiry, ?EstimationScenario $scenario): array
    {
        $enquiry->loadMissing(['ports.port', 'ports.offshoreLocation']);
        $terms = [
            'quantity' => $enquiry->quantity, 'quantity_unit' => $enquiry->getAttribute('quantity_unit'),
            'laycan_from' => $enquiry->laycan_from?->toDateString(), 'laycan_to' => $enquiry->laycan_to?->toDateString(),
            'period_days' => $enquiry->getAttribute('period_days'), 'terms' => $enquiry->getAttribute('terms'),
            'currency' => $enquiry->currency, 'rate' => $enquiry->rate_idea, 'rate_basis' => $enquiry->getAttribute('rate_basis'),
            'ports' => $enquiry->ports->map(fn ($p) => [...$p->point(), 'sequence' => $p->sequence, 'purpose' => $p->purpose])->all(),
        ];
        if ($scenario) {
            $primary = collect($scenario->inputs['revenue_items'] ?? [])->firstWhere('primary', true);
            if ($primary) {
                $terms = [...$terms, 'rate' => $primary['rate'] ?? $terms['rate'], 'rate_basis' => $primary['basis'], 'currency' => $primary['currency'] ?? $terms['currency'],
                    'quantity' => $primary['basis'] === 'per_mt' ? ($primary['quantity'] ?? $terms['quantity']) : $terms['quantity'],
                    'commissions' => ['address_pct' => (string) ($primary['address_pct'] ?? '0'), 'brokerage_pct' => (string) ($primary['brokerage_pct'] ?? '0'),
                        'other_pct' => (string) ($primary['other_pct'] ?? '0'), 'broker_company_id' => $enquiry->getAttribute('broker_company_id')]];
            }
            $terms['estimation_scenario_id'] = $scenario->id;
        }

        return $terms;
    }

    /** The pricing scenario must belong to an estimation for the same enquiry and vessel. */
    private function scenarioFor(int $enquiryId, int $vesselId, mixed $scenarioId): ?EstimationScenario
    {
        if (! $scenarioId) {
            return null;
        }
        $scenario = EstimationScenario::query()->with('estimation')->find($scenarioId);
        if (! $scenario || $scenario->estimation->enquiry_id !== $enquiryId || $scenario->estimation->vessel_id !== $vesselId) {
            throw new BusinessRuleException('The pricing scenario must belong to an estimation for this enquiry and vessel.', 'invalid_scenario', ['estimation_scenario_id' => ['Invalid scenario.']], 422);
        }

        return $scenario;
    }

    private function lockOpen(Offer $offer): Offer
    {
        $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);
        if ($locked->status !== 'open') {
            throw new BusinessRuleException("Offer {$locked->offer_number} is {$locked->status}.", 'offer_closed');
        }

        return $locked;
    }
}
