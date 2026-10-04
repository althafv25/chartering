<?php

namespace App\Services\Operations;

use App\Models\CaptainReportFuelLine;
use App\Models\Voyage;
use App\Models\VoyageSnapshot;
use App\Support\Decimal;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;

/**
 * Voyage figures for snapshots and the Initial / Milestone / Current / Final comparison.
 *
 * Operational actuals come from the voyage itself: duration (commenced → completed/now),
 * port time (ATA → ATD), verified captain reports (distance, fuel — REP-01) and
 * non-disputed off-hire. Financial lines (revenue, costs, profit, TCE) are carried from
 * the estimate until actual invoicing/expenses exist (phases 9–10); every payload marks
 * this with financials_source = "estimate" (assumption A-OP-2).
 */
class VoyageMetricsService
{
    /** metric key => [label, unit, scale] */
    public const METRICS = [
        'total_days' => ['Total days', 'days', 2],
        'sea_days' => ['Sea days', 'days', 2],
        'port_days' => ['Port days', 'days', 2],
        'off_hire_days' => ['Off-hire days', 'days', 2],
        'sea_distance_nm' => ['Sea distance', 'nm', 2],
        'fuel_total_mt' => ['Fuel consumed', 'mt', 3],
        'gross_revenue' => ['Gross revenue', 'money', 2],
        'total_costs' => ['Total costs', 'money', 2],
        'profit' => ['Profit', 'money', 2],
        'tce_per_day' => ['TCE / day', 'money', 2],
    ];

    private const FINANCIAL = ['gross_revenue', 'total_costs', 'profit', 'tce_per_day'];

    /** @return array<string, mixed> payload for a milestone/final snapshot */
    public function snapshotPayload(Voyage $voyage): array
    {
        $current = $this->current($voyage);

        return [
            'source' => 'actuals',
            'captured_at' => now()->toIso8601String(),
            'voyage_status' => $voyage->status,
            'metrics' => $current['metrics'],
            'fuel_by_type' => $current['fuel_by_type'],
            'counts' => $current['counts'],
            'financials_source' => 'estimate',
        ];
    }

    /**
     * @return array{metrics: array<string, string|null>, fuel_by_type: array<string, string>, counts: array<string, int>}
     */
    public function current(Voyage $voyage): array
    {
        $voyage->loadMissing(['portCalls', 'offHires']);
        $estimate = $this->initialMetrics($voyage);

        $end = $voyage->completed_at ?? Carbon::now();
        $totalDays = $voyage->commenced_at && $end->greaterThan($voyage->commenced_at)
            ? Decimal::div(LocalTime::hoursBetween($voyage->commenced_at, $end), '24') : null;

        $portHours = '0';
        $portCalls = 0;
        foreach ($voyage->portCalls as $call) {
            if ($call->ata && $call->atd && $call->status !== 'cancelled') {
                $portHours = Decimal::add($portHours, LocalTime::hoursBetween($call->ata, $call->atd));
                $portCalls++;
            }
        }
        $portDays = $portCalls > 0 ? Decimal::div($portHours, '24') : null;

        $offHireHours = '0';
        foreach ($voyage->offHires as $event) {
            if ($event->status !== 'disputed' && $event->hours !== null) {
                $offHireHours = Decimal::add($offHireHours, $event->hours);
            }
        }

        $verified = $voyage->captainReports()->where('status', 'verified')->get(['id', 'distance_since_last_nm']);
        $distance = $verified->isEmpty() ? null : $verified->reduce(fn (string $c, $r) => Decimal::add($c, $r->distance_since_last_nm ?? '0'), '0');

        $fuelByType = [];
        $fuelTotal = null;
        if ($verified->isNotEmpty()) {
            $fuelTotal = '0';
            CaptainReportFuelLine::query()->with('fuelType:id,code')->whereIn('captain_report_id', $verified->pluck('id'))->get()
                ->each(function (CaptainReportFuelLine $line) use (&$fuelByType, &$fuelTotal) {
                    $code = (string) $line->fuelType->getAttribute('code');
                    $fuelByType[$code] = Decimal::add($fuelByType[$code] ?? '0', $line->consumed_mt);
                    $fuelTotal = Decimal::add((string) $fuelTotal, $line->consumed_mt);
                });
        }

        $metrics = [
            'total_days' => $totalDays,
            'sea_days' => $totalDays !== null ? Decimal::sub($totalDays, $portDays ?? '0') : null,
            'port_days' => $portDays,
            'off_hire_days' => Decimal::div($offHireHours, '24'),
            'sea_distance_nm' => $distance,
            'fuel_total_mt' => $fuelTotal,
        ];
        foreach (self::FINANCIAL as $key) {
            $metrics[$key] = $estimate[$key] ?? null;
        }

        return [
            'metrics' => $this->rounded($metrics),
            'fuel_by_type' => array_map(fn ($v) => Decimal::round($v, 3), $fuelByType),
            'counts' => ['port_calls' => $voyage->portCalls->count(), 'reports_verified' => $verified->count(), 'off_hire_events' => $voyage->offHires->count()],
        ];
    }

    /** @return array<string, string|null> */
    public function initialMetrics(Voyage $voyage): array
    {
        $initial = $voyage->snapshots()->where('type', 'initial')->first();

        return $initial ? $this->metricsOf($initial) : array_fill_keys(array_keys(self::METRICS), null);
    }

    /** @return array<string, string|null> */
    public function metricsOf(VoyageSnapshot $snapshot): array
    {
        $payload = $snapshot->payload;
        if (isset($payload['metrics']) && is_array($payload['metrics'])) {
            $source = $payload['metrics'];
        } else {
            $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
            $source = [...$results, 'off_hire_days' => $results === [] ? null : '0'];
        }
        $out = [];
        foreach (array_keys(self::METRICS) as $key) {
            $v = $source[$key] ?? null;
            $out[$key] = is_numeric($v) ? (string) $v : null;
        }

        return $this->rounded($out);
    }

    /**
     * Comparison grid: initial, every milestone, current (live) and final.
     *
     * @return array<string, mixed>
     */
    public function comparison(Voyage $voyage): array
    {
        $columns = [];
        $values = [];
        foreach ($voyage->snapshots()->get() as $s) {
            $key = $s->type === 'milestone' ? "milestone:{$s->id}" : $s->type;
            $columns[] = ['key' => $key, 'label' => $s->type === 'initial' ? 'Initial' : ($s->type === 'final' ? 'Final' : (string) $s->getAttribute('name')),
                'type' => $s->type, 'snapshot_id' => $s->id, 'created_at' => $s->created_at?->toIso8601String()];
            $values[$key] = $this->metricsOf($s);
        }
        if ($voyage->status !== 'finalized') {
            $finalIdx = array_search('final', array_column($columns, 'type'), true);
            $current = ['key' => 'current', 'label' => 'Current', 'type' => 'current', 'snapshot_id' => null, 'created_at' => now()->toIso8601String()];
            $finalIdx === false ? $columns[] = $current : array_splice($columns, (int) $finalIdx, 0, [$current]);
            $values['current'] = $this->current($voyage)['metrics'];
        }

        $base = $values['initial'] ?? null;
        $rows = [];
        foreach (self::METRICS as $key => [$label, $unit, $scale]) {
            $row = ['metric' => $key, 'label' => $label, 'unit' => $unit, 'financial' => in_array($key, self::FINANCIAL, true), 'values' => [], 'variance' => []];
            foreach ($columns as $col) {
                $v = $values[$col['key']][$key] ?? null;
                $row['values'][$col['key']] = $v;
                $b = $base[$key] ?? null;
                $row['variance'][$col['key']] = ($col['key'] !== 'initial' && $v !== null && $b !== null) ? Decimal::round(Decimal::sub($v, $b), $scale) : null;
            }
            $rows[] = $row;
        }

        return ['currency' => $voyage->currency, 'columns' => $columns, 'rows' => $rows, 'financials_source' => 'estimate'];
    }

    /**
     * @param  array<string, string|null>  $metrics
     * @return array<string, string|null>
     */
    private function rounded(array $metrics): array
    {
        foreach ($metrics as $k => $v) {
            $metrics[$k] = $v === null ? null : Decimal::round($v, self::METRICS[$k][2]);
        }

        return $metrics;
    }
}
