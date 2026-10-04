<?php

namespace App\Services\Chartering;

use App\Exceptions\BusinessRuleException;
use App\Models\Estimation;
use App\Models\Fixture;
use App\Models\User;
use App\Models\Vessel;
use App\Models\Voyage;
use App\Models\VoyageSnapshot;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * BR-OP-01 (confirmed): an approved estimation may become a voyage without a
 * fixture (internal positioning, owner operation, spot offshore job). The
 * selected scenario is preserved and written as the immutable `initial` snapshot.
 */
class VoyageConversionService
{
    private const OPERATION_TYPE = ['voyage_charter' => 'voyage', 'cargo_relet' => 'voyage', 'time_charter' => 'time_charter', 'offshore_day_rate' => 'offshore'];

    public function __construct(private readonly SequenceService $sequences, private readonly ScenarioCalculationService $calc) {}

    /** @return array{0: Voyage, 1: bool} [voyage, created] */
    public function directFromEstimation(Estimation $est, string $reason, User $actor): array
    {
        try {
            return DB::transaction(function () use ($est, $reason, $actor) {
                $locked = Estimation::query()->lockForUpdate()->findOrFail($est->id);
                $scenario = $locked->selectedScenario()->with('result')->first();

                if ($scenario && ($existing = Voyage::query()->where('conversion_type', 'direct_estimation')->where('estimation_scenario_id', $scenario->id)->first())) {
                    return [$existing, false];
                }
                if ($locked->status !== 'approved') {
                    throw new BusinessRuleException('Only an approved estimation can be converted to a voyage.', 'estimation_not_approved');
                }
                if (! $scenario) {
                    throw new BusinessRuleException('The estimation has no selected scenario.', 'no_selected_scenario');
                }
                $scenario->setRelation('estimation', $locked);
                if (! $this->calc->isCurrent($scenario)) {
                    throw new BusinessRuleException('The selected scenario has no current calculation.', 'scenario_not_calculated');
                }
                if ($fixture = Fixture::query()->where('estimation_scenario_id', $scenario->id)->first()) {
                    throw new BusinessRuleException("Fixture {$fixture->fixture_number} exists for this scenario — convert the fixture instead of a direct operation.", 'fixture_exists');
                }

                $vessel = Vessel::query()->findOrFail($locked->vessel_id);
                $yy = now()->format('y');
                $voyage = Voyage::query()->create([
                    'voyage_number' => $this->sequences->next("voyage:{$vessel->id}:{$yy}", "{$vessel->code}-{$yy}-", 3),
                    'vessel_id' => $vessel->id,
                    'fixture_id' => null,
                    'estimation_id' => $locked->id,
                    'estimation_scenario_id' => $scenario->id,
                    'conversion_type' => 'direct_estimation',
                    'direct_reason' => $reason,
                    'operation_type' => self::OPERATION_TYPE[$locked->estimation_type],
                    'charterer_company_id' => $locked->enquiry?->getAttribute('charterer_company_id'),
                    'currency' => $locked->currency,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                VoyageSnapshot::query()->create([
                    'voyage_id' => $voyage->id,
                    'type' => 'initial',
                    'name' => 'Initial (from estimation)',
                    'calculation_version' => $scenario->calculation_version,
                    'inputs_hash' => $scenario->inputs_hash,
                    'payload' => [
                        'source' => 'direct_estimation',
                        'direct_reason' => $reason,
                        'estimation' => ['id' => $locked->id, 'number' => $locked->estimation_number, 'type' => $locked->estimation_type, 'currency' => $locked->currency],
                        'scenario' => ['id' => $scenario->id, 'code' => $scenario->code, 'name' => $scenario->name, 'inputs' => $scenario->inputs],
                        'vessel' => $scenario->vessel_snapshot,
                        'results' => collect($scenario->result->getAttributes())->except(['id', 'scenario_id', 'created_at', 'updated_at'])
                            ->map(fn ($v, $k) => in_array($k, ['breakdown', 'trace', 'warnings'], true) ? json_decode((string) $v, true) : $v)->all(),
                    ],
                    'created_by' => $actor->id,
                ]);

                activity('voyages')->performedOn($voyage)->causedBy($actor)->event('created_direct')
                    ->withProperties(['attributes' => ['estimation' => $locked->estimation_number, 'scenario' => $scenario->code, 'reason' => $reason]])
                    ->log("Voyage {$voyage->voyage_number} created directly from {$locked->estimation_number}");

                return [$voyage, true];
            });
        } catch (QueryException $e) {
            $scenarioId = $est->selectedScenario()->value('id');
            if (($e->errorInfo[1] ?? null) === 1062 && $scenarioId && ($existing = Voyage::query()->where('conversion_type', 'direct_estimation')->where('estimation_scenario_id', $scenarioId)->first())) {
                return [$existing, false];
            }
            throw $e;
        }
    }
}
