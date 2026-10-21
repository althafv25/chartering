<?php

namespace App\Services\Operations;

use App\Domain\Cii\CiiCalculator;
use App\Exceptions\BusinessRuleException;
use App\Models\CiiFormulaSet;
use App\Models\CiiVesselYear;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Decimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * CII (Carbon Intensity Indicator) vessel-year lifecycle.
 *   draft (inputs editable) → calculated (CiiCalculator ran with no issues)
 *     → verified (a different user confirms it, APR-02 self-approval guard)
 * Only draft/calculated records are editable; a verified record is read-only — correct
 * data requires re-opening it back to draft first (explicit, audited), never a silent edit.
 */
class CiiService
{
    private const EDITABLE = ['formula_set_id', 'capacity_metric', 'capacity_value', 'fuel_consumption', 'distance_sailed_nm', 'scope_status', 'remarks'];

    /** @param array<string, mixed> $data */
    public function save(?CiiVesselYear $year, array $data, User $actor): CiiVesselYear
    {
        return DB::transaction(function () use ($year, $data, $actor) {
            if ($year) {
                $y = CiiVesselYear::query()->lockForUpdate()->findOrFail($year->id);
                $y->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
                if ($y->status === 'verified') {
                    throw new BusinessRuleException('A verified CII record is read-only; reopen it first.', 'cii_read_only');
                }
            } else {
                $vesselId = (int) $data['vessel_id'];
                $year = (int) $data['reporting_year'];
                if (CiiVesselYear::query()->where('vessel_id', $vesselId)->where('reporting_year', $year)->exists()) {
                    throw new BusinessRuleException("A CII record for this vessel and {$year} already exists.", 'cii_duplicate_year');
                }
                $y = new CiiVesselYear(['created_by' => $actor->id, 'vessel_id' => $vesselId, 'reporting_year' => $year]);
            }

            $y->fill(Arr::only($data, self::EDITABLE));
            if (isset($data['capacity_value'])) {
                $y->capacity_value = Decimal::round((string) $data['capacity_value'], 3);
            }
            if (isset($data['distance_sailed_nm'])) {
                $y->distance_sailed_nm = Decimal::round((string) $data['distance_sailed_nm'], 2);
            }
            if (isset($data['fuel_consumption'])) {
                $y->fuel_consumption = array_map(fn ($v) => Decimal::round((string) $v, 3), $data['fuel_consumption']);
            }
            // Any input change invalidates a previous calculation.
            if ($y->status === 'calculated') {
                $y->status = 'draft';
                $y->attained_cii = null;
                $y->required_cii = null;
                $y->rating = null;
                $y->calculation_trace = null;
                $y->calculation_version = null;
            }
            $y->setAttribute('updated_by', $actor->id);
            $y->save();

            return $y;
        });
    }

    public function calculate(CiiVesselYear $year, User $actor): CiiVesselYear
    {
        return DB::transaction(function () use ($year, $actor) {
            $y = CiiVesselYear::query()->lockForUpdate()->with('vessel.vesselType', 'formulaSet')->findOrFail($year->id);
            if ($y->status === 'verified') {
                throw new BusinessRuleException('A verified CII record is read-only; reopen it first.', 'cii_read_only');
            }
            $formulaSet = $y->formulaSet;
            if (! $formulaSet) {
                throw new BusinessRuleException('No formula set is linked to this record.', 'cii_no_formula_set');
            }
            if ($formulaSet->status === 'retired') {
                throw new BusinessRuleException('This formula set is retired; select an active one.', 'cii_formula_set_retired');
            }

            $shipTypeKey = $y->vessel->vesselType?->code ?? '';
            $result = CiiCalculator::calculate(
                $shipTypeKey,
                (string) $y->capacity_value,
                (string) $y->distance_sailed_nm,
                $y->fuel_consumption ?? [],
                $formulaSet->parameters ?? [],
            );

            $y->fill([
                'attained_cii' => $result->attainedCii,
                'required_cii' => $result->requiredCii,
                'rating' => $result->rating,
                'calculation_trace' => $result->trace,
                'calculation_version' => $result->calculationVersion,
                'status' => $result->isComplete() ? 'calculated' : 'draft',
                'updated_by' => $actor->id,
            ])->save();

            if (! $result->isComplete()) {
                throw new BusinessRuleException(
                    'The CII calculation is incomplete: '.implode(' ', $result->issues),
                    'cii_calculation_incomplete',
                    ['issues' => $result->issues],
                    422,
                );
            }

            return $y;
        });
    }

    public function verify(CiiVesselYear $year, User $actor): CiiVesselYear
    {
        return DB::transaction(function () use ($year, $actor) {
            $y = CiiVesselYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($y->status !== 'calculated') {
                throw new BusinessRuleException('Only a fully calculated record can be verified.', 'invalid_status_transition');
            }
            if ($y->created_by === $actor->id || $y->updated_by === $actor->id) {
                throw new BusinessRuleException('A different user than the one who entered the data must verify it.', 'self_approval_not_allowed');
            }
            $y->fill(['status' => 'verified', 'verified_by_id' => $actor->id, 'verified_at' => now(), 'updated_by' => $actor->id])->save();
            activity('cii_vessel_years')->performedOn($y)->causedBy($actor)->event('verified')
                ->withProperties(['attributes' => ['rating' => $y->rating, 'attained_cii' => $y->attained_cii]])
                ->log("CII for vessel {$y->vessel_id} / {$y->reporting_year} verified");

            return $y;
        });
    }

    public function reopen(CiiVesselYear $year, User $actor, string $reason): CiiVesselYear
    {
        return DB::transaction(function () use ($year, $actor, $reason) {
            $y = CiiVesselYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($y->status !== 'verified') {
                throw new BusinessRuleException('Only a verified record can be reopened.', 'invalid_status_transition');
            }
            $y->fill([
                'status' => 'calculated', 'verified_by_id' => null, 'verified_at' => null, 'updated_by' => $actor->id,
                'remarks' => trim(($y->getAttribute('remarks') ?? '')."\nReopened: {$reason}"),
            ])->save();
            activity('cii_vessel_years')->performedOn($y)->causedBy($actor)->event('reopened')
                ->withProperties(['reason' => $reason])->log("CII for vessel {$y->vessel_id} / {$y->reporting_year} reopened");

            return $y;
        });
    }

    /** Fleet summary for a reporting year: counts by rating plus fleet average ratio. @return array<string, mixed> */
    public function fleetSummary(int $reportingYear): array
    {
        $years = CiiVesselYear::query()->with('vessel')->where('reporting_year', $reportingYear)
            ->where('scope_status', 'in_scope')->whereNotNull('rating')->get();

        $byRating = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
        $ratioSum = '0';
        $ratioCount = 0;
        foreach ($years as $y) {
            $byRating[$y->rating] = ($byRating[$y->rating] ?? 0) + 1;
            if ($y->attained_cii !== null && $y->required_cii !== null && Decimal::cmp((string) $y->required_cii, '0') > 0) {
                $ratioSum = Decimal::add($ratioSum, Decimal::div((string) $y->attained_cii, (string) $y->required_cii));
                $ratioCount++;
            }
        }

        return [
            'reporting_year' => $reportingYear,
            'vessel_count' => $years->count(),
            'by_rating' => $byRating,
            'fleet_average_ratio' => $ratioCount > 0 ? Decimal::round(Decimal::div($ratioSum, (string) $ratioCount), 4) : null,
            'at_risk_count' => $byRating['D'] + $byRating['E'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveFormulaSet(?CiiFormulaSet $set, array $data, User $actor): CiiFormulaSet
    {
        return DB::transaction(function () use ($set, $data, $actor) {
            if ($set && $set->status === 'retired') {
                throw new BusinessRuleException('A retired formula set cannot be edited.', 'cii_formula_set_read_only');
            }
            $fs = $set ?? new CiiFormulaSet(['created_by' => $actor->id]);
            $fs->fill(Arr::only($data, ['code', 'name', 'parameters', 'effective_date', 'expiration_date', 'remarks']));
            $fs->setAttribute('updated_by', $actor->id);
            $fs->save();

            return $fs;
        });
    }

    public function markFormulaSetStatus(CiiFormulaSet $set, string $status, User $actor): CiiFormulaSet
    {
        return DB::transaction(function () use ($set, $status, $actor) {
            $fs = CiiFormulaSet::query()->lockForUpdate()->findOrFail($set->id);
            $allowed = ['draft' => ['verified'], 'verified' => ['retired']];
            if (! in_array($status, $allowed[$fs->status] ?? [], true)) {
                throw new BusinessRuleException("A {$fs->status} formula set cannot become {$status}.", 'invalid_status_transition');
            }
            $fs->fill(['status' => $status, 'updated_by' => $actor->id])->save();
            activity('cii_formula_sets')->performedOn($fs)->causedBy($actor)->event($status)->log("Formula set {$fs->code} marked {$status}");

            return $fs;
        });
    }

    public static function resolveCapacity(Vessel $vessel, string $metric): string
    {
        return $metric === 'GT' ? (string) ($vessel->gt ?? '0') : (string) ($vessel->dwt_mt ?? '0');
    }
}
