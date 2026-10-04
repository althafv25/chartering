<?php

namespace App\Domain\Laytime;

use App\Support\Decimal;
use App\Support\LocalTime;
use Carbon\CarbonInterface;

/**
 * Pure laytime calculator (L1–L6, 08-VOYAGE-CALCULATIONS.md).
 * All times in UTC. Exceptions are overlays with pct_counted (0=excluded, 50=half, 100=full).
 * Once-on-demurrage rule: after cumulative used reaches allowed, exceptions may or may not apply.
 */
class LaytimeCalculator
{
    /** @param array{fixed_hours?: float|string|null, cargo_quantity?: float|string|null, rate_per_day?: float|string|null, rate_unit?: string|null, laytime_commenced_at?: CarbonInterface|null, laytime_completed_at?: CarbonInterface|null, demurrage_rate_per_day?: float|string|null, despatch_rate_per_day?: float|string|null, once_on_demurrage_rule?: string, exceptions?: array<int, array{from_at: CarbonInterface, to_at: CarbonInterface, exception_type: string, pct_counted: float|string}>} $inputs */
    public static function calculate(array $inputs): LaytimeResult
    {
        $version = '1.0.1';
        $trace = ['version' => $version];
        $issues = [];

        // L1: Allowed hours
        $allowed = null;
        if (isset($inputs['fixed_hours'])) {
            $allowed = (string) $inputs['fixed_hours'];
            $trace['allowed_basis'] = 'fixed';
            $trace['fixed_hours'] = Decimal::round($allowed, 4);
        } elseif (isset($inputs['cargo_quantity'], $inputs['rate_per_day']) && Decimal::cmp((string) $inputs['rate_per_day'], '0') > 0) {
            $qty = (string) $inputs['cargo_quantity'];
            $rate = (string) $inputs['rate_per_day'];
            $allowed = Decimal::mul(Decimal::div($qty, $rate), '24');
            $trace['allowed_basis'] = 'cargo_quantity / rate_per_day * 24';
            $trace['cargo_quantity'] = Decimal::round($qty, 3);
            $trace['rate_per_day'] = Decimal::round($rate, 4);
            $trace['rate_unit'] = $inputs['rate_unit'] ?? 'MT';
            $trace['allowed_hours_calc'] = Decimal::round($allowed, 4);
        } else {
            $issues[] = 'Allowed time not specified (fixed_hours or cargo_quantity + rate_per_day required).';
        }

        // L2: Time window
        $commenced = $inputs['laytime_commenced_at'] ?? null;
        $completed = $inputs['laytime_completed_at'] ?? null;
        if (! $commenced || ! $completed) {
            $issues[] = 'Laytime commencement and completion times are required.';

            return new LaytimeResult(null, null, null, null, null, $version, $trace, $issues);
        }
        if ($completed->lessThanOrEqualTo($commenced)) {
            $issues[] = 'Completion time must be after commencement.';

            return new LaytimeResult(null, null, null, null, null, $version, $trace, $issues);
        }
        $trace['window'] = ['commenced' => $commenced->toIso8601String(), 'completed' => $completed->toIso8601String()];

        // L3: Used hours
        $exceptions = $inputs['exceptions'] ?? [];
        $usedResult = self::computeUsedHours($commenced, $completed, $exceptions);
        $used = $usedResult['used'];
        $trace['used_hours_detail'] = $usedResult['trace'];

        // L4: Once on demurrage
        $rule = $inputs['once_on_demurrage_rule'] ?? 'always_on_demurrage';
        if ($rule === 'always_on_demurrage' && $allowed !== null && Decimal::cmp($used, $allowed) > 0) {
            // Re-count exceptions as full time after the point where cumulative time reaches allowed
            $recounted = self::applyOnceOnDemurrage($commenced, $completed, $exceptions, $allowed);
            if (Decimal::cmp($recounted['used'], $used) !== 0) {
                $used = $recounted['used'];
                $trace['once_on_demurrage_applied'] = true;
                $trace['used_hours_detail'] = $recounted['trace'];
            }
        }

        if ($allowed === null) {
            return new LaytimeResult(null, null, null, null, null, $version, $trace, $issues);
        }

        // L5: Difference and dem/des
        $diff = Decimal::sub($allowed, $used);
        $demRate = isset($inputs['demurrage_rate_per_day']) ? (string) $inputs['demurrage_rate_per_day'] : null;
        $desRate = isset($inputs['despatch_rate_per_day']) ? (string) $inputs['despatch_rate_per_day'] : null;

        $demAmount = null;
        $desAmount = null;
        if (Decimal::cmp($diff, '0') < 0) {
            // Demurrage
            if ($demRate !== null && Decimal::cmp($demRate, '0') > 0) {
                $demDays = Decimal::div(Decimal::mul($diff, '-1'), '24');
                $demAmount = Decimal::mul($demDays, $demRate);
                $trace['demurrage'] = ['hours' => Decimal::round($diff, 4), 'days' => Decimal::round($demDays, 6), 'rate_per_day' => Decimal::round($demRate, 4), 'amount' => Decimal::round($demAmount, 2)];
            }
        } elseif (Decimal::cmp($diff, '0') > 0) {
            // Despatch
            if ($desRate !== null && Decimal::cmp($desRate, '0') > 0) {
                $desDays = Decimal::div($diff, '24');
                $desAmount = Decimal::mul($desDays, $desRate);
                $trace['despatch'] = ['hours' => Decimal::round($diff, 4), 'days' => Decimal::round($desDays, 6), 'rate_per_day' => Decimal::round($desRate, 4), 'amount' => Decimal::round($desAmount, 2)];
            }
        }

        return new LaytimeResult(
            Decimal::round($allowed, 4),
            Decimal::round($used, 4),
            Decimal::round($diff, 4),
            $demAmount !== null ? Decimal::round($demAmount, 2) : null,
            $desAmount !== null ? Decimal::round($desAmount, 2) : null,
            $version,
            $trace,
            $issues
        );
    }

    /** @param array<int, array{from_at: CarbonInterface, to_at: CarbonInterface, exception_type: string, pct_counted: float|string}> $exceptions */
    private static function computeUsedHours(CarbonInterface $start, CarbonInterface $end, array $exceptions): array
    {
        $segments = self::buildSegments($start, $end, $exceptions);
        $used = '0';
        $trace = [];
        foreach ($segments as $seg) {
            $h = LocalTime::hoursBetween($seg['from'], $seg['to']);
            $counted = Decimal::mul($h, Decimal::div((string) $seg['pct'], '100'));
            $used = Decimal::add($used, $counted);
            $trace[] = ['from' => $seg['from']->toIso8601String(), 'to' => $seg['to']->toIso8601String(), 'hours' => Decimal::round($h, 4),
                'pct_counted' => (string) $seg['pct'], 'counted_hours' => Decimal::round($counted, 4), 'exception_types' => $seg['types']];
        }

        return ['used' => $used, 'trace' => $trace];
    }

    /**
     * Once on demurrage, always on demurrage (LT-04): time counts at 100 % from the moment counted laytime
     * reaches the allowed hours, whatever exceptions follow. A segment in which laytime runs out is split
     * at that moment. Exceptions before expiry keep their percentage.
     *
     * @param  array<int, array{from_at: CarbonInterface, to_at: CarbonInterface, exception_type: string, pct_counted: float|string}>  $exceptions
     * @return array{used: string, trace: array<int, array<string, mixed>>}
     */
    private static function applyOnceOnDemurrage(CarbonInterface $start, CarbonInterface $end, array $exceptions, string $allowed): array
    {
        $cumulative = '0';
        $used = '0';
        $onDemurrage = Decimal::cmp($allowed, '0') <= 0;
        $trace = [];

        foreach (self::buildSegments($start, $end, $exceptions) as $seg) {
            $hours = LocalTime::hoursBetween($seg['from'], $seg['to']);
            $pct = (string) $seg['pct'];
            /** @var list<array{0: CarbonInterface, 1: CarbonInterface, 2: string, 3: string, 4: bool}> $parts from, to, clock hours, pct, on demurrage */
            $parts = [];

            if ($onDemurrage) {
                $parts[] = [$seg['from'], $seg['to'], $hours, '100', true];
            } else {
                $counted = Decimal::mul($hours, Decimal::div($pct, '100'));
                $remaining = Decimal::sub($allowed, $cumulative);
                if (Decimal::cmp($counted, $remaining) > 0) {
                    // Laytime expires inside this segment (so pct > 0): the rest of it counts in full.
                    $clockToExpiry = Decimal::div($remaining, Decimal::div($pct, '100'));
                    $expiresAt = $seg['from']->copy()->addSeconds((int) Decimal::round(Decimal::mul($clockToExpiry, '3600'), 0));
                    $parts[] = [$seg['from'], $expiresAt, $clockToExpiry, $pct, false];
                    $parts[] = [$expiresAt, $seg['to'], Decimal::sub($hours, $clockToExpiry), '100', true];
                    $onDemurrage = true;
                } else {
                    $parts[] = [$seg['from'], $seg['to'], $hours, $pct, false];
                    $cumulative = Decimal::add($cumulative, $counted);
                    $onDemurrage = Decimal::cmp($cumulative, $allowed) >= 0;
                }
            }

            foreach ($parts as [$from, $to, $clockHours, $partPct, $dem]) {
                $counted = Decimal::mul($clockHours, Decimal::div($partPct, '100'));
                $used = Decimal::add($used, $counted);
                $trace[] = ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'hours' => Decimal::round($clockHours, 4),
                    'pct_counted' => $partPct, 'counted_hours' => Decimal::round($counted, 4), 'exception_types' => $seg['types'], 'once_on_demurrage' => $dem];
            }
        }

        return ['used' => $used, 'trace' => $trace];
    }

    /**
     * Build timeline segments with overlapping exceptions merged (lowest pct wins).
     *
     * @param  array<int, array{from_at: CarbonInterface, to_at: CarbonInterface, exception_type: string, pct_counted: float|string}>  $exceptions
     * @return array<int, array{from: CarbonInterface, to: CarbonInterface, pct: string, types: array<string>}>
     */
    private static function buildSegments(CarbonInterface $start, CarbonInterface $end, array $exceptions): array
    {
        // Timeline events
        $events = [['time' => $start, 'type' => 'start'], ['time' => $end, 'type' => 'end']];
        foreach ($exceptions as $ex) {
            if ($ex['from_at']->greaterThanOrEqualTo($end) || $ex['to_at']->lessThanOrEqualTo($start)) {
                continue; // outside window
            }
            $from = $ex['from_at']->greaterThan($start) ? $ex['from_at'] : $start;
            $to = $ex['to_at']->lessThan($end) ? $ex['to_at'] : $end;
            $events[] = ['time' => $from, 'type' => 'ex_start', 'pct' => (string) $ex['pct_counted'], 'ex_type' => $ex['exception_type']];
            $events[] = ['time' => $to, 'type' => 'ex_end', 'pct' => (string) $ex['pct_counted'], 'ex_type' => $ex['exception_type']];
        }
        usort($events, fn ($a, $b) => $a['time']->timestamp <=> $b['time']->timestamp);

        $segments = [];
        $active = [];
        $prev = null;
        foreach ($events as $e) {
            if ($prev !== null && $prev->timestamp < $e['time']->timestamp) {
                $pct = empty($active) ? '100' : (string) min(array_column($active, 'pct'));
                $types = empty($active) ? [] : array_values(array_unique(array_column($active, 'ex_type')));
                $segments[] = ['from' => $prev, 'to' => $e['time'], 'pct' => $pct, 'types' => $types];
            }
            if ($e['type'] === 'ex_start') {
                $active[] = ['pct' => $e['pct'], 'ex_type' => $e['ex_type']];
            } elseif ($e['type'] === 'ex_end') {
                $active = array_values(array_filter($active, fn ($a) => $a['pct'] !== $e['pct'] || $a['ex_type'] !== $e['ex_type']));
            }
            $prev = $e['time'];
        }

        return $segments;
    }
}
