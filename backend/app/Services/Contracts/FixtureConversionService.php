<?php

namespace App\Services\Contracts;

use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\ContractRate;
use App\Models\Fixture;
use App\Models\User;
use App\Models\Vessel;
use App\Models\Voyage;
use App\Models\VoyageSnapshot;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Approved fixture → draft contract, and approved fixture → voyage.
 * Both idempotent (unique fixture_id) and transactional.
 */
class FixtureConversionService
{
    /** Assumption A-CT-1: a cargo relet is formalised as a voyage charter contract. */
    private const CONTRACT_TYPE = ['voyage_charter' => 'voyage_charter', 'time_charter' => 'time_charter', 'offshore_charter' => 'offshore_charter',
        'cargo_relet' => 'voyage_charter', 'service' => 'service'];

    private const OPERATION_TYPE = ['voyage_charter' => 'voyage', 'cargo_relet' => 'voyage', 'time_charter' => 'time_charter', 'offshore_charter' => 'offshore', 'service' => 'offshore'];

    public function __construct(private readonly ContractService $contracts, private readonly SequenceService $sequences) {}

    /** @return array{0: Contract, 1: bool} */
    public function toContract(Fixture $fixture, User $actor): array
    {
        try {
            return DB::transaction(function () use ($fixture, $actor) {
                $locked = Fixture::query()->lockForUpdate()->findOrFail($fixture->id);
                if ($existing = Contract::query()->where('fixture_id', $locked->id)->first()) {
                    return [$existing, false];
                }
                $this->assertApproved($locked);
                if (! $locked->getAttribute('charterer_company_id')) {
                    throw new BusinessRuleException('Set the charterer on the fixture before creating a contract.', 'charterer_required');
                }
                $type = self::CONTRACT_TYPE[$locked->business_type];
                $rateType = match ($locked->rate_basis) {
                    'per_mt' => 'freight_per_mt',
                    'per_hour' => 'hourly',
                    'lump_sum' => 'lump_sum',
                    default => in_array($type, ['offshore_charter', 'service'], true) ? 'day_rate' : 'hire_per_day',
                };
                $contract = $this->contracts->create([
                    'contract_type' => $type,
                    'title' => "{$locked->fixture_number} — ".(Vessel::query()->find($locked->vessel_id)?->name ?? 'vessel'),
                    'customer_company_id' => $locked->getAttribute('charterer_company_id'),
                    'vessel_id' => $locked->vessel_id,
                    'start_date' => $locked->laycan_from?->toDateString(),
                    'end_date' => null,
                    'currency' => $locked->currency,
                    'commissions' => $locked->commissions,
                    'terms' => $locked->getAttribute('terms'),
                ], [[
                    'rate_type' => $rateType, 'amount' => $locked->rate, 'currency' => $locked->currency,
                    'unit' => ContractRate::UNIT_OF[$rateType], 'description' => "As fixed ({$locked->fixture_number})",
                ]], [], $actor, $locked->id);

                activity('fixtures')->performedOn($locked)->causedBy($actor)->event('contract_created')
                    ->withProperties(['attributes' => ['contract' => $contract->contract_number]])->log("Contract {$contract->contract_number} created");

                return [$contract, true];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && ($existing = Contract::query()->where('fixture_id', $fixture->id)->first())) {
                return [$existing, false];
            }
            throw $e;
        }
    }

    /** @return array{0: Voyage, 1: bool} */
    public function toVoyage(Fixture $fixture, User $actor): array
    {
        try {
            return DB::transaction(function () use ($fixture, $actor) {
                $locked = Fixture::query()->with(['scenario.result', 'scenario.estimation'])->lockForUpdate()->findOrFail($fixture->id);
                if ($existing = Voyage::query()->where('fixture_id', $locked->id)->first()) {
                    return [$existing, false];
                }
                $this->assertApproved($locked);
                $contract = Contract::query()->where('fixture_id', $locked->id)->whereNotIn('status', ['cancelled'])->first();
                $vessel = Vessel::query()->findOrFail($locked->vessel_id);
                $scenario = $locked->scenario;
                $yy = now()->format('y');

                $voyage = Voyage::query()->create([
                    'voyage_number' => $this->sequences->next("voyage:{$vessel->id}:{$yy}", "{$vessel->code}-{$yy}-", 3),
                    'vessel_id' => $vessel->id,
                    'fixture_id' => $locked->id,
                    'contract_id' => $contract?->id,
                    'estimation_id' => $scenario?->estimation_id,
                    'estimation_scenario_id' => $scenario?->id,
                    'conversion_type' => 'fixture',
                    'operation_type' => self::OPERATION_TYPE[$locked->business_type],
                    'charterer_company_id' => $locked->getAttribute('charterer_company_id'),
                    'currency' => $locked->currency,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                VoyageSnapshot::query()->create([
                    'voyage_id' => $voyage->id,
                    'type' => 'initial',
                    'name' => 'Initial (from fixture)',
                    'calculation_version' => $scenario?->calculation_version,
                    'inputs_hash' => $scenario?->inputs_hash,
                    'payload' => [
                        'source' => 'fixture',
                        'fixture' => ['id' => $locked->id, 'number' => $locked->fixture_number, 'rate' => $locked->rate, 'rate_basis' => $locked->rate_basis,
                            'currency' => $locked->currency, 'commissions' => $locked->commissions],
                        'contract' => $contract ? ['id' => $contract->id, 'number' => $contract->contract_number, 'version' => $contract->current_version] : null,
                        'recap' => $locked->recap_snapshot,
                        'results' => $scenario?->result ? collect($scenario->result->getAttributes())->except(['id', 'scenario_id', 'created_at', 'updated_at'])
                            ->map(fn ($v, $k) => in_array($k, ['breakdown', 'trace', 'warnings'], true) ? json_decode((string) $v, true) : $v)->all() : null,
                    ],
                    'created_by' => $actor->id,
                ]);

                activity('voyages')->performedOn($voyage)->causedBy($actor)->event('created_from_fixture')
                    ->withProperties(['attributes' => ['fixture' => $locked->fixture_number, 'contract' => $contract?->contract_number]])
                    ->log("Voyage {$voyage->voyage_number} created from {$locked->fixture_number}");

                return [$voyage, true];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && ($existing = Voyage::query()->where('fixture_id', $fixture->id)->first())) {
                return [$existing, false];
            }
            throw $e;
        }
    }

    private function assertApproved(Fixture $fixture): void
    {
        if ($fixture->status !== 'approved') {
            throw new BusinessRuleException("Only an approved fixture can be converted (this one is {$fixture->status}).", 'fixture_not_approved');
        }
    }
}
