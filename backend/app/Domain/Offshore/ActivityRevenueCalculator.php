<?php

namespace App\Domain\Offshore;

use App\Support\Decimal;

/**
 * Offshore activity revenue (08 §O2). Pure: no DB, no settings lookups.
 *
 *  billable  hourly rate  : billable_h × rate
 *            day rate     : billable days × rate, days by proration
 *                           hourly   = h / 24                 (BR-OA-01 proposal, default)
 *                           half_day = ceil(h / 12) / 2       (per started half day)
 *                           full_day = ceil(h / 24)           (per started day)
 *  standby   standby_rate : standby days × standby day rate   (BR-OA-02 proposal, default)
 *            full_rate    : standby hours priced like billable hours
 *  lump sums mobilization / demobilization fee, once per contract (BR-OA-03 proposal)
 *
 * Revenue is null when a needed rate is missing or currencies differ; the reason is in warnings.
 */
final class ActivityRevenueCalculator
{
    /**
     * @param  array{rate_type: string, amount: string, currency: string, unit: string, contract_rate_id?: int|null}|null  $billableRate
     * @param  array{rate_type: string, amount: string, currency: string, unit: string, contract_rate_id?: int|null}|null  $standbyRate
     * @param  list<array{rate_type: string, amount: string, currency: string, unit: string, contract_rate_id?: int|null}>  $lumpSums
     * @return array{lines: list<array<string, mixed>>, revenue: string|null, currency: string|null, warnings: list<string>}
     */
    public function calculate(string $billableHours, string $standbyHours, ?array $billableRate, ?array $standbyRate, array $lumpSums,
        string $proration = 'hourly', string $standbyBasis = 'standby_rate'): array
    {
        $lines = [];
        $warnings = [];

        if (Decimal::cmp($billableHours, '0') > 0) {
            $billableRate ? $lines[] = $this->timeLine('billable', $billableHours, $billableRate, $proration) : $warnings[] = 'no_billable_rate';
        }
        if (Decimal::cmp($standbyHours, '0') > 0) {
            $rate = $standbyBasis === 'full_rate' ? $billableRate : $standbyRate;
            $rate ? $lines[] = $this->timeLine('standby', $standbyHours, $rate, $proration) : $warnings[] = $standbyBasis === 'full_rate' ? 'no_billable_rate' : 'no_standby_rate';
        }
        foreach ($lumpSums as $fee) {
            $lines[] = ['kind' => 'lump_sum', 'rate_type' => $fee['rate_type'], 'contract_rate_id' => $fee['contract_rate_id'] ?? null, 'unit' => 'lump_sum',
                'quantity' => '1', 'rate' => $fee['amount'], 'currency' => $fee['currency'], 'amount' => Decimal::round($fee['amount'], 2)];
        }

        $currencies = array_values(array_unique(array_column($lines, 'currency')));
        if (count($currencies) > 1) {
            $warnings[] = 'mixed_currency';
        }
        $warnings = array_values(array_unique($warnings));

        $revenue = null;
        if ($warnings === [] && $lines !== []) {
            $revenue = '0';
            foreach ($lines as $l) {
                $revenue = Decimal::add($revenue, $l['amount']);
            }
            $revenue = Decimal::round($revenue, 2);
        } elseif ($warnings === []) {
            $revenue = '0.00'; // nothing billable (e.g. non-billable hours only)
        }

        return ['lines' => $lines, 'revenue' => $revenue, 'currency' => $currencies[0] ?? null, 'warnings' => $warnings];
    }

    /** Billing days for a number of hours under the proration rule (BR-OA-01). */
    public static function days(string $hours, string $proration): string
    {
        return match ($proration) {
            'half_day' => Decimal::div(self::ceil(Decimal::div($hours, '12')), '2'),
            'full_day' => self::ceil(Decimal::div($hours, '24')),
            default => Decimal::div($hours, '24'),
        };
    }

    /**
     * @param  array{rate_type: string, amount: string, currency: string, unit: string, contract_rate_id?: int|null}  $rate
     * @return array<string, mixed>
     */
    private function timeLine(string $kind, string $hours, array $rate, string $proration): array
    {
        $hourly = $rate['unit'] === 'per_hour';
        $quantity = $hourly ? $hours : self::days($hours, $proration);

        return [
            'kind' => $kind, 'rate_type' => $rate['rate_type'], 'contract_rate_id' => $rate['contract_rate_id'] ?? null,
            'unit' => $hourly ? 'per_hour' : 'per_day', 'hours' => Decimal::round($hours, 4), 'proration' => $hourly ? null : $proration,
            'quantity' => Decimal::round($quantity, 6), 'rate' => $rate['amount'], 'currency' => $rate['currency'],
            'amount' => Decimal::round(Decimal::mul($quantity, $rate['amount']), 2),
        ];
    }

    private static function ceil(string $v): string
    {
        $int = bcadd($v, '0', 0); // truncates toward zero; inputs are non-negative

        return Decimal::cmp($v, $int) > 0 ? bcadd($int, '1', 0) : $int;
    }
}
