<?php

namespace App\Services\Chartering;

use App\Domain\Estimation\EstimationCalculator;
use App\Domain\Estimation\Input\ScenarioInput;
use App\Domain\Estimation\InvalidScenarioInput;
use App\Models\Currency;
use App\Models\EstimationScenario;
use App\Models\ScenarioResultRecord;
use Illuminate\Support\Facades\DB;

/**
 * Persists calculator output. Uses ONLY the stored scenario snapshot —
 * recalculation never reads vessel, fuel, FX or category master data.
 */
class ScenarioCalculationService
{
    public function __construct(private readonly EstimationCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function inputArray(EstimationScenario $scenario): array
    {
        $est = $scenario->estimation;

        return [
            'type' => $est->estimation_type,
            'currency' => $est->currency,
            'currency_decimals' => (int) (Currency::query()->where('code', $est->currency)->value('decimals') ?? 2),
            'inputs' => $scenario->inputs,
        ];
    }

    /** Idempotent: unchanged inputs + same calculation version → no recomputation. */
    public function calculate(EstimationScenario $scenario, bool $force = false): EstimationScenario
    {
        $array = $this->inputArray($scenario);
        $hash = ScenarioInput::hashOf($array);

        if (! $force && $scenario->calc_status === 'calculated' && $scenario->inputs_hash === $hash
            && $scenario->calculation_version === EstimationCalculator::VERSION && $scenario->result()->exists()) {
            return $scenario;
        }

        return DB::transaction(function () use ($scenario, $array, $hash) {
            try {
                $result = $this->calculator->calculate(ScenarioInput::fromArray($array), $hash)->toArray();
            } catch (InvalidScenarioInput $e) {
                ScenarioResultRecord::query()->where('scenario_id', $scenario->id)->delete();
                $scenario->forceFill(['calc_status' => 'incomplete', 'calc_issues' => $e->issues, 'inputs_hash' => $hash,
                    'calculation_version' => EstimationCalculator::VERSION, 'calculated_at' => null])->saveQuietly();

                return $scenario->load('result');
            }

            ScenarioResultRecord::query()->updateOrCreate(['scenario_id' => $scenario->id], [...$result, 'calculated_at' => now()]);
            $scenario->forceFill(['calc_status' => 'calculated', 'calc_issues' => null, 'inputs_hash' => $hash,
                'calculation_version' => EstimationCalculator::VERSION, 'calculated_at' => now()])->saveQuietly();

            activity('estimation_scenarios')->performedOn($scenario)->causedBy(auth()->user())->event('calculated')
                ->withProperties(['attributes' => ['inputs_hash' => $hash, 'calculation_version' => EstimationCalculator::VERSION,
                    'profit' => $result['profit'], 'tce_per_day' => $result['tce_per_day']]])->log("Scenario {$scenario->code} calculated");

            return $scenario->load('result');
        });
    }

    /** Is the stored result exactly the result of the stored inputs? */
    public function isCurrent(EstimationScenario $scenario): bool
    {
        return $scenario->calc_status === 'calculated'
            && $scenario->inputs_hash === ScenarioInput::hashOf($this->inputArray($scenario))
            && $scenario->result()->where('inputs_hash', $scenario->inputs_hash)->exists();
    }
}
