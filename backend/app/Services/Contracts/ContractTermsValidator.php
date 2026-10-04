<?php

namespace App\Services\Contracts;

use App\Models\ContractRate;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Normalises and validates a full rate set / clause set for one contract version.
 * Rules: unit must match the rate type (except "other"); effective windows valid;
 * no overlapping windows for the same (rate_type, activity type); amounts ≥ 0.
 */
class ContractTermsValidator
{
    /**
     * @param  list<array<string, mixed>>  $rates
     * @return list<array<string, mixed>>
     */
    public function rates(array $rates, string $currency, string $prefix = 'rates'): array
    {
        $errors = [];
        $out = [];
        foreach (array_values($rates) as $i => $r) {
            $type = (string) $r['rate_type'];
            $unit = (string) ($r['unit'] ?? (ContractRate::UNIT_OF[$type] ?? ''));
            if (isset(ContractRate::UNIT_OF[$type]) && $unit !== ContractRate::UNIT_OF[$type]) {
                $errors["{$prefix}.{$i}.unit"] = ["A {$type} rate must use unit ".ContractRate::UNIT_OF[$type].'.'];
            }
            if (! in_array($unit, ContractRate::UNITS, true)) {
                $errors["{$prefix}.{$i}.unit"] = ['Unit is required.'];
            }
            $from = $r['effective_from'] ?? null;
            $to = $r['effective_to'] ?? null;
            if ($from && $to && $to < $from) {
                $errors["{$prefix}.{$i}.effective_to"] = ['The end date must be on or after the start date.'];
            }
            $out[] = [
                'rate_type' => $type,
                'offshore_activity_type_id' => isset($r['offshore_activity_type_id']) ? (int) $r['offshore_activity_type_id'] : null,
                'description' => $r['description'] ?? null,
                'amount' => Decimal::round((string) $r['amount'], 4),
                'currency' => strtoupper((string) ($r['currency'] ?? $currency)),
                'unit' => $unit,
                'effective_from' => $from,
                'effective_to' => $to,
                'notes' => $r['notes'] ?? null,
            ];
        }

        foreach ($out as $i => $a) {
            foreach ($out as $j => $b) {
                if ($j <= $i || $a['rate_type'] !== $b['rate_type'] || $a['offshore_activity_type_id'] !== $b['offshore_activity_type_id']) {
                    continue;
                }
                $aFrom = $a['effective_from'] ?? '0000-01-01';
                $aTo = $a['effective_to'] ?? '9999-12-31';
                $bFrom = $b['effective_from'] ?? '0000-01-01';
                $bTo = $b['effective_to'] ?? '9999-12-31';
                if ($aFrom <= $bTo && $bFrom <= $aTo) {
                    $errors["{$prefix}.{$j}.effective_from"] = ['Overlaps rate line '.($i + 1).' of the same type — split the periods.'];
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $clauses
     * @return list<array<string, mixed>>
     */
    public function clauses(array $clauses): array
    {
        return array_map(fn ($c, $i) => [
            'sequence' => $i + 1, 'clause_ref' => $c['clause_ref'] ?? null, 'title' => (string) $c['title'], 'body' => (string) $c['body'],
        ], array_values($clauses), array_keys(array_values($clauses)));
    }
}
