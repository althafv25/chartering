<?php

namespace App\Services\Contracts;

use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\User;
use App\Models\Voyage;
use App\Services\Chartering\ApprovalGuard;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Contract lifecycle (CT-01):
 *   draft → under_review → approved → active → completed
 *   under_review → draft (rejected, reason)
 *   approved|active → expired (scheduled, end_date passed)
 *   draft|under_review|approved → cancelled (reason)
 * Approval writes version 1 (immutable header snapshot). After approval the
 * terms change only through amendments (ContractAmendmentService).
 */
class ContractService
{
    public const HEADER = ['title', 'customer_company_id', 'vessel_id', 'start_date', 'end_date', 'extension_options', 'currency',
        'payment_terms_days', 'payment_terms_text', 'commissions', 'terms', 'remarks'];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly ApprovalGuard $approvals,
        private readonly ContractTermsValidator $terms,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $rates
     * @param  list<array<string, mixed>>  $clauses
     */
    public function create(array $data, array $rates, array $clauses, User $actor, ?int $fixtureId = null): Contract
    {
        $this->assertTypeVessel($data['contract_type'], $data['vessel_id'] ?? null);
        $rates = $this->terms->rates($rates, strtoupper($data['currency']));
        $clauses = $this->terms->clauses($clauses);

        return DB::transaction(function () use ($data, $rates, $clauses, $actor, $fixtureId) {
            $year = now()->format('Y');
            $contract = Contract::query()->create([
                ...Arr::only($data, self::HEADER),
                'contract_type' => $data['contract_type'],
                'currency' => strtoupper($data['currency']),
                'commissions' => $this->commissions($data['commissions'] ?? null),
                'contract_number' => $this->sequences->next("contract:{$year}", "CON-{$year}-"),
                'fixture_id' => $fixtureId,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->writeTerms($contract, 1, $rates, $clauses);

            return $contract;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Contract $contract, array $data, User $actor): Contract
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            $locked = $this->lockEditable($contract, isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            $fields = Arr::only($data, self::HEADER);
            if (array_key_exists('commissions', $fields)) {
                $fields['commissions'] = $this->commissions($fields['commissions']);
            }
            if ($locked->fixture_id && array_intersect(array_keys($fields), ['customer_company_id', 'vessel_id', 'currency'])) {
                foreach (['customer_company_id', 'vessel_id', 'currency'] as $k) {
                    if (array_key_exists($k, $fields) && (string) $fields[$k] !== (string) $locked->getAttribute($k)) {
                        throw new BusinessRuleException('Customer, vessel and currency come from the fixture and cannot be changed.', 'fixture_terms_locked');
                    }
                }
            }
            $locked->fill([...$fields, 'updated_by' => $actor->id])->save();
            $this->assertTypeVessel($locked->contract_type, $locked->vessel_id);

            return $locked;
        });
    }

    /** @param list<array<string, mixed>> $rates */
    public function replaceRates(Contract $contract, array $rates, int $lockVersion, User $actor): Contract
    {
        return DB::transaction(function () use ($contract, $rates, $lockVersion, $actor) {
            $locked = $this->lockEditable($contract, $lockVersion);
            $clean = $this->terms->rates($rates, $locked->currency);
            $before = $locked->rates()->where('version_no', 1)->get()->map->canonical()->all();
            $locked->rates()->where('version_no', 1)->delete();
            $locked->rates()->createMany(array_map(fn ($r) => [...$r, 'version_no' => 1], $clean));
            $locked->forceFill(['updated_by' => $actor->id])->save(); // bumps lock_version
            activity('contracts')->performedOn($locked)->causedBy($actor)->event('rates_changed')
                ->withProperties(['old' => ['rates' => $before], 'attributes' => ['rates' => $clean]])->log('Draft contract rates changed');

            return $locked;
        });
    }

    /** @param list<array<string, mixed>> $clauses */
    public function replaceClauses(Contract $contract, array $clauses, int $lockVersion, User $actor): Contract
    {
        return DB::transaction(function () use ($contract, $clauses, $lockVersion, $actor) {
            $locked = $this->lockEditable($contract, $lockVersion);
            $locked->clauses()->where('version_no', 1)->delete();
            $locked->clauses()->createMany(array_map(fn ($c) => [...$c, 'version_no' => 1], $this->terms->clauses($clauses)));
            $locked->forceFill(['updated_by' => $actor->id])->save();
            activity('contracts')->performedOn($locked)->causedBy($actor)->event('clauses_changed')->log('Draft contract clauses changed');

            return $locked;
        });
    }

    public function submit(Contract $contract, User $actor): Contract
    {
        return $this->move($contract, ['draft'], 'under_review', $actor, function (Contract $c) use ($actor) {
            $issues = [];
            if (! $c->start_date || ! $c->end_date) {
                $issues[] = 'Start and end dates are required.';
            } elseif ($c->end_date->lt($c->start_date)) {
                $issues[] = 'End date must be on or after the start date.';
            }
            if (! $c->rates()->where('version_no', 1)->exists()) {
                $issues[] = 'At least one rate is required.';
            }
            if ($issues) {
                throw new BusinessRuleException('The contract is not ready for review: '.implode(' ', $issues), 'contract_incomplete', ['contract' => $issues]);
            }

            return ['submitted_by' => $actor->id, 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_comment' => null];
        });
    }

    public function approve(Contract $contract, User $actor, ?string $comment): Contract
    {
        return $this->move($contract, ['under_review'], 'approved', $actor, function (Contract $c) use ($actor, $comment) {
            $this->approvals->assertNotSelfDecision($c->submitted_by, $actor);
            ContractVersion::query()->create([
                'contract_id' => $c->id, 'version_no' => 1, 'effective_from' => $c->start_date, 'header_snapshot' => $this->headerSnapshot($c),
                'created_by' => $actor->id,
            ]);

            return ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment, 'current_version' => 1];
        });
    }

    public function reject(Contract $contract, User $actor, string $reason): Contract
    {
        return $this->move($contract, ['under_review'], 'draft', $actor, function (Contract $c) use ($actor, $reason) {
            $this->approvals->assertNotSelfDecision($c->submitted_by, $actor);

            return ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $reason];
        }, 'rejected');
    }

    public function activate(Contract $contract, User $actor): Contract
    {
        return $this->move($contract, ['approved'], 'active', $actor, function (Contract $c) {
            if ($c->end_date && $c->end_date->lt(today())) {
                throw new BusinessRuleException('The contract end date has passed. Extend it by amendment before activating.', 'contract_ended');
            }

            return ['activated_at' => now()];
        });
    }

    public function complete(Contract $contract, User $actor, ?string $note): Contract
    {
        return $this->move($contract, ['active'], 'completed', $actor, fn () => ['closed_at' => now(), 'close_reason' => $note]);
    }

    public function cancel(Contract $contract, User $actor, string $reason): Contract
    {
        return $this->move($contract, ['draft', 'under_review', 'approved'], 'cancelled', $actor, function (Contract $c) {
            if (Voyage::query()->where('contract_id', $c->id)->exists()) {
                throw new BusinessRuleException('Voyages are linked to this contract; it cannot be cancelled.', 'contract_in_use');
            }

            return ['closed_at' => now()];
        }, extraReason: $reason);
    }

    /** Scheduled (CT-01 / BR-CT-03 proposal): approved/active contracts past end_date → expired. */
    public function expire(Contract $contract): bool
    {
        return DB::transaction(function () use ($contract) {
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            if (! in_array($locked->status, ['approved', 'active'], true) || ! $locked->end_date || ! $locked->end_date->lt(today())) {
                return false;
            }
            $prev = $locked->status;
            $locked->forceFill(['status' => 'expired', 'closed_at' => now(), 'close_reason' => 'End date passed'])->save();
            activity('contracts')->performedOn($locked)->event('expired')
                ->withProperties(['old' => ['status' => $prev], 'attributes' => ['status' => 'expired', 'end_date' => $locked->end_date->toDateString()]])
                ->log('Contract expired (end date passed)');

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function headerSnapshot(Contract $c): array
    {
        return [
            ...collect($c->only(array_diff(self::HEADER, ['start_date', 'end_date'])))->all(),
            'contract_type' => $c->contract_type,
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rates
     * @param  list<array<string, mixed>>  $clauses
     */
    public function writeTerms(Contract $contract, int $version, array $rates, array $clauses): void
    {
        $contract->rates()->createMany(array_map(fn ($r) => [...$r, 'version_no' => $version], $rates));
        $contract->clauses()->createMany(array_map(fn ($c) => [...$c, 'version_no' => $version], $clauses));
    }

    /** @return array<string, mixed> */
    public function commissions(mixed $c): array
    {
        $c = is_array($c) ? $c : [];

        return [
            'address_pct' => (string) ($c['address_pct'] ?? '0'), 'brokerage_pct' => (string) ($c['brokerage_pct'] ?? '0'),
            'other_pct' => (string) ($c['other_pct'] ?? '0'), 'broker_company_id' => isset($c['broker_company_id']) ? (int) $c['broker_company_id'] : null,
        ];
    }

    private function assertTypeVessel(string $type, mixed $vesselId): void
    {
        if ($type !== 'service' && $type !== 'other' && ! $vesselId) {
            throw new BusinessRuleException('A vessel is required for charter contracts.', 'vessel_required', ['vessel_id' => ['Required for this contract type.']], 422);
        }
    }

    private function lockEditable(Contract $contract, ?int $lockVersion): Contract
    {
        $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
        if (! $locked->isEditable()) {
            throw new BusinessRuleException(
                $locked->isAmendable() ? 'The contract is approved — change its terms through an amendment.' : "A {$locked->status} contract cannot be edited.",
                'contract_read_only',
            );
        }
        $locked->assertLockVersion($lockVersion);

        return $locked;
    }

    /**
     * @param  list<string>  $from
     * @param  callable(Contract): array<string, mixed>  $guard
     */
    private function move(Contract $contract, array $from, string $to, User $actor, callable $guard, ?string $event = null, ?string $extraReason = null): Contract
    {
        return DB::transaction(function () use ($contract, $from, $to, $actor, $guard, $event, $extraReason) {
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            if (! in_array($locked->status, $from, true)) {
                throw new BusinessRuleException("Contract cannot move from {$locked->status} to {$to}.", 'invalid_status_transition');
            }
            $extra = $guard($locked);
            if ($extraReason !== null) {
                $extra['close_reason'] = $extraReason;
            }
            $prev = $locked->status;
            $locked->forceFill([...$extra, 'status' => $to, 'updated_by' => $actor->id])->save();
            activity('contracts')->performedOn($locked)->causedBy($actor)->event($event ?? $to)
                ->withProperties(['old' => ['status' => $prev], 'attributes' => ['status' => $to, 'comment' => $extra['decision_comment'] ?? $extra['close_reason'] ?? null]])
                ->log("Contract {$prev} → {$to}");

            return $locked;
        });
    }
}
