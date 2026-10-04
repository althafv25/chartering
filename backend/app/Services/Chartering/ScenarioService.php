<?php

namespace App\Services\Chartering;

use App\Domain\Estimation\Input\ScenarioInput;
use App\Exceptions\BusinessRuleException;
use App\Models\Estimation;
use App\Models\EstimationScenario;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Support\Facades\DB;

class ScenarioService
{
    public function __construct(
        private readonly ScenarioDefaults $defaults,
        private readonly ScenarioCalculationService $calc,
    ) {}

    public function createFromDefaults(Estimation $est, ?string $name, ?int $profileId, User $actor): EstimationScenario
    {
        $this->assertEditable($est);

        return DB::transaction(function () use ($est, $name, $profileId, $actor) {
            Estimation::query()->lockForUpdate()->findOrFail($est->id);
            $built = $this->defaults->build($est, $profileId);
            $code = $this->nextCode($est);
            $scenario = EstimationScenario::query()->create([
                ...$built, 'estimation_id' => $est->id, 'code' => $code, 'name' => $name ?: "Scenario {$code}",
                'defaults_refreshed_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $scenario->setRelation('estimation', $est);

            return $this->calc->calculate($scenario);
        });
    }

    public function clone(EstimationScenario $source, ?string $name, User $actor, ?Estimation $target = null): EstimationScenario
    {
        $target ??= $source->estimation;
        $this->assertEditable($target);

        return DB::transaction(function () use ($source, $name, $actor, $target) {
            Estimation::query()->lockForUpdate()->findOrFail($target->id);
            $code = $this->nextCode($target);
            $copy = EstimationScenario::query()->create([
                'estimation_id' => $target->id, 'code' => $code,
                'name' => $name ?: ($target->id === $source->estimation_id ? "{$source->name} (copy)" : $source->name),
                'is_selected' => $target->id !== $source->estimation_id && $source->is_selected,
                'vessel_snapshot' => $source->vessel_snapshot, 'consumption_profile_id' => $source->getAttribute('consumption_profile_id'),
                'inputs' => $source->inputs, 'defaults_refreshed_at' => $source->defaults_refreshed_at, 'notes' => $source->getAttribute('notes'),
                'cloned_from_id' => $source->id, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $copy->setRelation('estimation', $target);
            activity('estimation_scenarios')->performedOn($copy)->causedBy($actor)->event('cloned')
                ->withProperties(['attributes' => ['cloned_from_id' => $source->id, 'source_code' => $source->code]])->log("Scenario {$code} cloned from {$source->code}");

            return $this->calc->calculate($copy);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(EstimationScenario $scenario, array $data, User $actor): EstimationScenario
    {
        $est = $scenario->estimation;
        $this->assertEditable($est);

        return DB::transaction(function () use ($scenario, $data, $actor, $est) {
            /** @var EstimationScenario $locked */
            $locked = EstimationScenario::query()->lockForUpdate()->findOrFail($scenario->id);
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            $locked->setRelation('estimation', $est);

            $fields = array_intersect_key($data, array_flip(['name', 'notes']));
            if (array_key_exists('inputs', $data)) {
                $before = ScenarioInput::hashOf(['inputs' => $locked->inputs]);
                $fields['inputs'] = $this->defaults->snapshotItems($est, $locked->inputs, $data['inputs']);
                if ($before !== ScenarioInput::hashOf(['inputs' => $fields['inputs']])) {
                    $fields['calc_status'] = 'stale';
                }
            }
            $locked->fill([...$fields, 'updated_by' => $actor->id])->save();

            return $this->calc->calculate($locked);
        });
    }

    public function calculate(EstimationScenario $scenario): EstimationScenario
    {
        if (! $scenario->estimation->isEditable()) {
            // Read-only estimations are never recalculated (results stay as approved).
            return $scenario->load('result');
        }

        return $this->calc->calculate($scenario);
    }

    public function select(EstimationScenario $scenario, User $actor): EstimationScenario
    {
        $est = $scenario->estimation;
        $this->assertEditable($est);

        return DB::transaction(function () use ($scenario, $est, $actor) {
            Estimation::query()->lockForUpdate()->findOrFail($est->id);
            $scenario->refresh()->setRelation('estimation', $est);
            if (! $this->calc->isCurrent($scenario)) {
                throw new BusinessRuleException('Only a fully calculated scenario can be selected. Complete its inputs first.', 'scenario_not_calculated');
            }
            EstimationScenario::query()->where('estimation_id', $est->id)->whereKeyNot($scenario->id)->where('is_selected', true)
                ->update(['is_selected' => false]);
            $scenario->forceFill(['is_selected' => true])->saveQuietly();
            activity('estimations')->performedOn($est)->causedBy($actor)->event('scenario_selected')
                ->withProperties(['attributes' => ['scenario' => $scenario->code]])->log("Scenario {$scenario->code} selected");

            return $scenario;
        });
    }

    /**
     * Explicit, audited re-read of vessel particulars and/or consumption from master data.
     *
     * @param  array{vessel?:bool, consumption?:bool, profile_id?:int|null}  $options
     */
    public function refreshDefaults(EstimationScenario $scenario, array $options, User $actor): EstimationScenario
    {
        $est = $scenario->estimation;
        $this->assertEditable($est);

        return DB::transaction(function () use ($scenario, $options, $actor, $est) {
            /** @var EstimationScenario $locked */
            $locked = EstimationScenario::query()->lockForUpdate()->findOrFail($scenario->id);
            $locked->setRelation('estimation', $est);
            $vessel = Vessel::query()->findOrFail($est->vessel_id);
            $beforeHash = ScenarioInput::hashOf(['inputs' => $locked->inputs, 'vessel' => $locked->vessel_snapshot]);
            $changes = [];
            $inputs = $locked->inputs;

            if (! empty($options['vessel'])) {
                $locked->vessel_snapshot = $this->defaults->vesselSnapshot($vessel);
                $changes[] = 'vessel particulars';
            }
            if (! empty($options['consumption'])) {
                $profile = $this->defaults->profile($vessel, $options['profile_id'] ?? null);
                if (! $profile) {
                    throw new BusinessRuleException('The vessel has no consumption profile effective today.', 'no_consumption_profile');
                }
                $inputs['consumption'] = $this->defaults->consumptionRows($profile);
                // Keep entered prices; add rows for newly used fuels.
                $known = collect($inputs['fuel_prices'] ?? [])->pluck('fuel_type_id')->all();
                foreach (collect($inputs['consumption'])->unique('fuel_type_id') as $row) {
                    if (! in_array($row['fuel_type_id'], $known, true)) {
                        $inputs['fuel_prices'][] = ['fuel_type_id' => $row['fuel_type_id'], 'fuel_code' => $row['fuel_code'], 'price_per_mt' => null,
                            'currency' => $est->currency, 'fx_rate' => '1', 'account' => $inputs['fuel_prices'][0]['account'] ?? 'owner'];
                    }
                }
                $locked->setAttribute('consumption_profile_id', $profile->id);
                $changes[] = "consumption profile “{$profile->name}”";
            }
            if (! $changes) {
                throw new BusinessRuleException('Choose what to refresh (vessel particulars and/or consumption).', 'nothing_to_refresh', status: 422);
            }

            $locked->inputs = $inputs;
            $locked->defaults_refreshed_at = now();
            $locked->calc_status = 'stale';
            $locked->updated_by = $actor->id;
            $locked->save();

            activity('estimation_scenarios')->performedOn($locked)->causedBy($actor)->event('defaults_refreshed')
                ->withProperties(['old' => ['hash' => $beforeHash], 'attributes' => ['refreshed' => $changes,
                    'hash' => ScenarioInput::hashOf(['inputs' => $locked->inputs, 'vessel' => $locked->vessel_snapshot])]])
                ->log('Scenario defaults refreshed: '.implode(', ', $changes));

            return $this->calc->calculate($locked);
        });
    }

    public function assertEditable(Estimation $est): void
    {
        if (! $est->isEditable()) {
            throw new BusinessRuleException(
                $est->status === 'approved'
                    ? 'This estimation is approved and read-only. Clone it to make changes.'
                    : "This estimation is {$est->status} and cannot be changed.",
                'estimation_read_only',
            );
        }
    }

    /** A, B, … Z, AA, AB … (bijective base-26). */
    private function nextCode(Estimation $est): string
    {
        $n = EstimationScenario::query()->where('estimation_id', $est->id)->count() + 1;
        $code = '';
        while ($n > 0) {
            $n--;
            $code = chr(65 + $n % 26).$code;
            $n = intdiv($n, 26);
        }

        return $code;
    }
}
