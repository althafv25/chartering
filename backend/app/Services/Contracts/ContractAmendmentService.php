<?php

namespace App\Services\Contracts;

use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractVersion;
use App\Models\User;
use App\Services\Chartering\ApprovalGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Amendments (CT-02): draft → submitted → approved | rejected; draft → withdrawn.
 * A proposal holds the FULL proposed rate set and/or clause set and/or amendable
 * header fields. Approval creates version N+1 effective from the amendment date;
 * previous versions are never changed. Extensions/options are amendments of
 * end_date (BR-CT-02 proposal).
 */
class ContractAmendmentService
{
    public function __construct(
        private readonly ApprovalGuard $approvals,
        private readonly ContractTermsValidator $terms,
        private readonly ContractService $contracts,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Contract $contract, array $data, User $actor): ContractAmendment
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $this->assertAmendable($locked);
            if ($locked->amendments()->whereIn('status', ['draft', 'submitted'])->exists()) {
                throw new BusinessRuleException('Another amendment is still open. Approve, reject or withdraw it first.', 'amendment_open');
            }
            $proposal = $this->proposal($locked, $data);
            $this->assertEffectiveDate($locked, $data['effective_date']);

            return ContractAmendment::query()->create([
                'contract_id' => $locked->id,
                'amendment_no' => (int) $locked->amendments()->max('amendment_no') + 1,
                'effective_date' => $data['effective_date'],
                'summary' => $data['summary'],
                'proposal' => $proposal,
                'created_by' => $actor->id,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ContractAmendment $amendment, array $data, User $actor): ContractAmendment
    {
        return DB::transaction(function () use ($amendment, $data) {
            $locked = ContractAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleException("A {$locked->status} amendment cannot be edited.", 'amendment_read_only');
            }
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            $contract = Contract::query()->findOrFail($locked->contract_id);
            $fields = Arr::only($data, ['effective_date', 'summary']);
            if (isset($fields['effective_date'])) {
                $this->assertEffectiveDate($contract, $fields['effective_date']);
            }
            if (array_intersect(array_keys($data), ['header', 'rates', 'clauses'])) {
                $fields['proposal'] = $this->proposal($contract, [...$locked->proposal, ...Arr::only($data, ['header', 'rates', 'clauses'])]);
            }
            $locked->fill($fields)->save();

            return $locked;
        });
    }

    public function submit(ContractAmendment $amendment, User $actor): ContractAmendment
    {
        return $this->move($amendment, ['draft'], 'submitted', $actor, fn () => ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    public function reject(ContractAmendment $amendment, User $actor, string $reason): ContractAmendment
    {
        return $this->move($amendment, ['submitted'], 'rejected', $actor, function (ContractAmendment $a) use ($actor, $reason) {
            $this->approvals->assertNotSelfDecision($a->submitted_by, $actor);

            return ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $reason];
        });
    }

    public function withdraw(ContractAmendment $amendment, User $actor): ContractAmendment
    {
        return $this->move($amendment, ['draft', 'submitted'], 'withdrawn', $actor, fn () => ['decided_by' => $actor->id, 'decided_at' => now()]);
    }

    public function approve(ContractAmendment $amendment, User $actor, ?string $comment): ContractAmendment
    {
        return DB::transaction(function () use ($amendment, $actor, $comment) {
            $locked = ContractAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            if ($locked->status !== 'submitted') {
                throw new BusinessRuleException("Amendment cannot be approved while {$locked->status}.", 'invalid_status_transition');
            }
            $this->approvals->assertNotSelfDecision($locked->submitted_by, $actor);
            $contract = Contract::query()->lockForUpdate()->findOrFail($locked->contract_id);
            $this->assertAmendable($contract);
            $this->assertEffectiveDate($contract, $locked->effective_date->toDateString());

            $from = $contract->current_version;
            $to = $from + 1;
            $p = $locked->proposal;
            $beforeHeader = $this->contracts->headerSnapshot($contract);
            $beforeRates = $contract->rates()->where('version_no', $from)->get();
            $beforeClauses = $contract->clauses()->where('version_no', $from)->get();

            $rates = array_key_exists('rates', $p) ? $p['rates'] : $beforeRates->map->canonical()->all();
            $clauses = array_key_exists('clauses', $p) ? $p['clauses'] : $this->terms->clauses($beforeClauses->map->canonical()->all());

            $contract->fill([...($p['header'] ?? []), 'current_version' => $to, 'updated_by' => $actor->id])->save();
            $this->contracts->writeTerms($contract, $to, $rates, $clauses);
            $afterHeader = $this->contracts->headerSnapshot($contract);

            ContractVersion::query()->create([
                'contract_id' => $contract->id, 'version_no' => $to, 'effective_from' => $locked->effective_date, 'amendment_id' => $locked->id,
                'header_snapshot' => $afterHeader, 'created_by' => $actor->id,
            ]);

            $changes = [
                'header' => collect($afterHeader)->filter(fn ($v, $k) => ($beforeHeader[$k] ?? null) != $v)
                    ->map(fn ($v, $k) => ['from' => $beforeHeader[$k] ?? null, 'to' => $v])->all(),
                'rates' => array_key_exists('rates', $p) ? ['from' => $beforeRates->map->canonical()->all(), 'to' => $rates] : null,
                'clauses' => array_key_exists('clauses', $p) ? ['from' => $beforeClauses->map->canonical()->all(), 'to' => $clauses] : null,
            ];
            $locked->forceFill(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment,
                'resulting_version' => $to, 'changes' => $changes])->save();

            activity('contracts')->performedOn($contract)->causedBy($actor)->event('amended')
                ->withProperties(['old' => ['version' => $from], 'attributes' => ['version' => $to, 'amendment_no' => $locked->amendment_no,
                    'effective_date' => $locked->effective_date->toDateString(), 'changes' => $changes]])
                ->log("Amendment {$locked->amendment_no} approved → version {$to}");

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function proposal(Contract $contract, array $data): array
    {
        $p = [];
        if (! empty($data['header'])) {
            $header = Arr::only($data['header'], Contract::AMENDABLE);
            if (array_key_exists('commissions', $header)) {
                $header['commissions'] = $this->contracts->commissions($header['commissions']);
            }
            if (isset($header['end_date']) && $contract->start_date && $header['end_date'] < $contract->start_date->toDateString()) {
                throw new BusinessRuleException('The new end date is before the contract start.', 'validation_failed', ['header.end_date' => ['Must be on or after the start date.']], 422);
            }
            $p['header'] = $header;
        }
        if (array_key_exists('rates', $data) && $data['rates'] !== null) {
            if (! $data['rates']) {
                throw new BusinessRuleException('An amended rate set cannot be empty.', 'validation_failed', ['rates' => ['At least one rate.']], 422);
            }
            $p['rates'] = $this->terms->rates($data['rates'], $contract->currency);
        }
        if (array_key_exists('clauses', $data) && $data['clauses'] !== null) {
            $p['clauses'] = $this->terms->clauses($data['clauses']);
        }
        if (! $p) {
            throw new BusinessRuleException('The amendment changes nothing. Propose header fields, rates or clauses.', 'empty_amendment', status: 422);
        }

        return $p;
    }

    private function assertAmendable(Contract $contract): void
    {
        if (! $contract->isAmendable()) {
            throw new BusinessRuleException("Only approved or active contracts can be amended (this one is {$contract->status}).", 'contract_not_amendable');
        }
    }

    private function assertEffectiveDate(Contract $contract, string $date): void
    {
        $current = $contract->versions()->where('version_no', $contract->current_version)->first();
        $min = $current?->effective_from ?? $contract->start_date;
        if ($min && $date < $min->toDateString()) {
            throw new BusinessRuleException("The effective date cannot be before {$min->toDateString()} (start of the current version).", 'amendment_backdated',
                ['effective_date' => ['Too early.']], 422);
        }
    }

    /**
     * @param  list<string>  $from
     * @param  callable(ContractAmendment): array<string, mixed>  $fields
     */
    private function move(ContractAmendment $amendment, array $from, string $to, User $actor, callable $fields): ContractAmendment
    {
        return DB::transaction(function () use ($amendment, $from, $to, $actor, $fields) {
            $locked = ContractAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            if (! in_array($locked->status, $from, true)) {
                throw new BusinessRuleException("Amendment cannot move from {$locked->status} to {$to}.", 'invalid_status_transition');
            }
            $locked->forceFill([...$fields($locked), 'status' => $to])->save();
            activity('contracts')->performedOn($locked->contract)->causedBy($actor)->event("amendment_{$to}")
                ->withProperties(['attributes' => ['amendment_no' => $locked->amendment_no, 'status' => $to]])->log("Amendment {$locked->amendment_no} {$to}");

            return $locked;
        });
    }
}
