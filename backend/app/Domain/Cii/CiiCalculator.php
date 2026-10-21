<?php

namespace App\Domain\Cii;

use App\Support\Decimal;

/**
 * Pure CII (Carbon Intensity Indicator) calculator — IMO MARPOL Annex VI.
 * No Eloquent, no HTTP. Deterministic and versioned so a stored result can always
 * be reproduced from its inputs (docs/CII-REQUIREMENTS.md).
 *
 * CII_attained = (Σ fuel_tonnes[i] × Cf[i] × 1000) / (Capacity × Distance)
 * CII_reference = a × Capacity^(-c)
 * CII_ratio = CII_attained / CII_reference
 * Rating: ratio < A boundary → A, < B boundary → B, < C boundary → C, <= D boundary → D, else E.
 */
class CiiCalculator
{
    public const VERSION = '1.0.0';

    /**
     * @param  array<string, string|float>  $fuelConsumptionMt  fuel code (e.g. HFO, MGO, LNG) => metric tonnes
     * @param  array<string, mixed>  $parameters  formula set parameters (reference_lines, rating_boundaries, emission_factors), keyed as stored on CiiFormulaSet
     */
    public static function calculate(
        string $shipTypeKey,
        string $capacityValue,
        string $distanceSailedNm,
        array $fuelConsumptionMt,
        array $parameters,
    ): CiiResult {
        $trace = ['version' => self::VERSION, 'ship_type' => $shipTypeKey];
        $issues = [];

        $referenceLine = $parameters['reference_lines'][$shipTypeKey] ?? null;
        if ($referenceLine === null || ! isset($referenceLine['a'], $referenceLine['c'])) {
            $issues[] = "No reference line (a, c) configured for ship type \"{$shipTypeKey}\" in this formula set.";
        }

        $emissionFactors = $parameters['emission_factors'] ?? [];
        $totalCo2Kg = '0';
        $fuelTrace = [];
        foreach ($fuelConsumptionMt as $fuelCode => $tonnes) {
            $tonnes = (string) $tonnes;
            if (Decimal::cmp($tonnes, '0') <= 0) {
                continue;
            }
            $cf = $emissionFactors[$fuelCode] ?? null;
            if ($cf === null) {
                $issues[] = "No emission factor (Cf) configured for fuel \"{$fuelCode}\".";

                continue;
            }
            // tonnes × Cf (kg CO2 / kg fuel) × 1000 (kg fuel per tonne) = kg CO2
            $co2 = Decimal::mul(Decimal::mul($tonnes, (string) $cf), '1000');
            $totalCo2Kg = Decimal::add($totalCo2Kg, $co2);
            $fuelTrace[$fuelCode] = ['tonnes' => Decimal::round($tonnes, 3), 'cf' => (string) $cf, 'co2_kg' => Decimal::round($co2, 2)];
        }
        $trace['fuel'] = $fuelTrace;
        $trace['total_co2_kg'] = Decimal::round($totalCo2Kg, 2);

        if (Decimal::cmp($capacityValue, '0') <= 0) {
            $issues[] = 'Capacity (DWT/GT) must be greater than zero.';
        }
        if (Decimal::cmp($distanceSailedNm, '0') <= 0) {
            $issues[] = 'Distance sailed must be greater than zero.';
        }

        if ($issues !== []) {
            return new CiiResult(null, null, null, null, self::VERSION, $trace, $issues);
        }

        $denominator = Decimal::mul($capacityValue, $distanceSailedNm);
        $attained = Decimal::div($totalCo2Kg, $denominator);
        $trace['capacity'] = Decimal::round($capacityValue, 3);
        $trace['distance_nm'] = Decimal::round($distanceSailedNm, 2);
        $trace['attained_cii_calc'] = 'total_co2_kg / (capacity * distance)';

        // CII_reference = a * Capacity^(-c)
        $a = (string) $referenceLine['a'];
        $c = (float) $referenceLine['c'];
        $reference = Decimal::mul($a, (string) ((float) $capacityValue ** (-$c)));
        $trace['reference_line'] = ['a' => $a, 'c' => (string) $c];

        $ratio = Decimal::div($attained, $reference);
        $boundaries = $parameters['rating_boundaries'] ?? [];
        $rating = self::rate($ratio, $boundaries);
        $trace['rating_boundaries'] = $boundaries;
        $trace['ratio'] = Decimal::round($ratio, 6);

        return new CiiResult(
            Decimal::round($attained, 6),
            Decimal::round($reference, 6),
            $rating,
            Decimal::round($ratio, 6),
            self::VERSION,
            $trace,
            [],
        );
    }

    /** @param array<string, mixed> $boundaries */
    private static function rate(string $ratio, array $boundaries): string
    {
        $a = (string) ($boundaries['A'] ?? '0.50');
        $b = (string) ($boundaries['B'] ?? '0.72');
        $c = (string) ($boundaries['C'] ?? '0.83');
        $d = (string) ($boundaries['D'] ?? $boundaries['E'] ?? '1.00');

        if (Decimal::cmp($ratio, $a) < 0) {
            return 'A';
        }
        if (Decimal::cmp($ratio, $b) < 0) {
            return 'B';
        }
        if (Decimal::cmp($ratio, $c) < 0) {
            return 'C';
        }
        if (Decimal::cmp($ratio, $d) <= 0) {
            return 'D';
        }

        return 'E';
    }
}
