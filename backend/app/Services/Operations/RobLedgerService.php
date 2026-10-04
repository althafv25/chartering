<?php

namespace App\Services\Operations;

use App\Models\BunkerStem;
use App\Models\CaptainReport;
use App\Models\FuelType;
use App\Models\Voyage;
use App\Services\SettingsService;
use App\Support\Decimal;
use App\Support\LocalTime;
use Carbon\CarbonInterface;

/**
 * ROB ledger per voyage × fuel, derived on demand from verified captain reports (REP-01).
 * Each report closes one period that starts at the previous report.
 *   BK-01 computed closing = opening + received − consumed (opening = previous reported ROB)
 *   BK-02 discontinuity when |reported ROB − computed closing| > 0.001 mt
 *   BK-03 flagged when |actual − estimated| / estimated > bunker.discrepancy_threshold_pct;
 *         estimated = initial-snapshot fuel per day × period days (assumption A-BK-1)
 * Delivered stems in the same period are compared with the reported "received" quantity.
 */
class RobLedgerService
{
    private const TOLERANCE = '0.001';

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function forVoyage(Voyage $voyage): array
    {
        $threshold = (string) $this->settings->get('bunker.discrepancy_threshold_pct', '5');
        $reports = CaptainReport::query()->with('fuelLines')->where('voyage_id', $voyage->id)->where('status', 'verified')->orderBy('reported_at')->get();
        $stems = BunkerStem::query()->where('voyage_id', $voyage->id)->whereIn('status', ['delivered', 'invoiced'])->get();
        $perDay = $this->estimatedPerDay($voyage);
        $fuelIds = $reports->flatMap(fn ($r) => $r->fuelLines->pluck('fuel_type_id'))->merge($stems->pluck('fuel_type_id'))->unique()->values();
        $fuels = FuelType::query()->whereIn('id', $fuelIds)->get(['id', 'code', 'name'])->keyBy('id');

        $ledgers = [];
        foreach ($fuelIds as $fuelId) {
            $rows = [];
            $prevRob = null;
            $prevAt = null;
            $tot = ['received_mt' => '0', 'consumed_mt' => '0', 'stems_mt' => '0', 'estimated_mt' => null];
            foreach ($reports as $report) {
                $line = $report->fuelLines->firstWhere('fuel_type_id', $fuelId);
                if (! $line) {
                    continue;
                }
                $received = (string) $line->received_mt;
                $consumed = (string) $line->consumed_mt;
                $reported = $line->rob_mt !== null ? (string) $line->rob_mt : null;
                $row = [
                    'report_id' => $report->id, 'report_type' => $report->report_type, 'period_start' => $prevAt?->toIso8601String(),
                    'period_end' => $report->reported_at->toIso8601String(), 'opening_mt' => $prevRob, 'received_mt' => Decimal::round($received, 3),
                    'consumed_mt' => Decimal::round($consumed, 3), 'closing_mt' => null, 'reported_rob_mt' => $reported !== null ? Decimal::round($reported, 3) : null,
                    'rob_difference_mt' => null, 'rob_discontinuity' => false, 'estimated_consumed_mt' => null, 'variance_mt' => null, 'variance_pct' => null,
                    'flagged' => false, 'stems_delivered_mt' => null, 'received_mismatch' => false,
                ];
                if ($prevAt !== null) {
                    $tot['received_mt'] = Decimal::add($tot['received_mt'], $received);
                    $tot['consumed_mt'] = Decimal::add($tot['consumed_mt'], $consumed);
                    if ($prevRob !== null) {
                        $closing = Decimal::sub(Decimal::add($prevRob, $received), $consumed);
                        $row['closing_mt'] = Decimal::round($closing, 3);
                        if ($reported !== null) {
                            $diff = Decimal::sub($reported, $closing);
                            $row['rob_difference_mt'] = Decimal::round($diff, 3);
                            $row['rob_discontinuity'] = Decimal::cmp($this->abs($diff), self::TOLERANCE) > 0;
                        }
                    }
                    $this->estimate($row, $perDay[$fuelId] ?? null, $prevAt, $report->reported_at, $consumed, $threshold, $tot);
                    $delivered = $stems->filter(fn ($s) => $s->fuel_type_id === $fuelId && $s->delivered_at && $s->delivered_at->greaterThan($prevAt)
                        && ! $s->delivered_at->greaterThan($report->reported_at))->reduce(fn (string $c, $s) => Decimal::add($c, (string) $s->delivered_mt), '0');
                    $row['stems_delivered_mt'] = Decimal::round($delivered, 3);
                    $row['received_mismatch'] = Decimal::cmp($this->abs(Decimal::sub($delivered, $received)), self::TOLERANCE) > 0;
                    $tot['stems_mt'] = Decimal::add($tot['stems_mt'], $delivered);
                }
                $rows[] = $row;
                $prevRob = $reported ?? $row['closing_mt'];
                $prevAt = $report->reported_at;
            }
            $fuel = $fuels->get($fuelId);
            $ledgers[] = [
                'fuel_type_id' => (int) $fuelId, 'fuel_code' => $fuel?->getAttribute('code'), 'fuel_name' => $fuel?->getAttribute('name'), 'rows' => $rows,
                'totals' => array_map(fn ($v) => $v === null ? null : Decimal::round($v, 3), $tot),
                'stems_outside_reports_mt' => Decimal::round(Decimal::sub($stems->where('fuel_type_id', $fuelId)->reduce(fn (string $c, $s) => Decimal::add($c, (string) $s->delivered_mt), '0'), $tot['stems_mt']), 3),
                'flags' => ['rob_discontinuity' => collect($rows)->where('rob_discontinuity', true)->count(), 'consumption' => collect($rows)->where('flagged', true)->count(),
                    'received_mismatch' => collect($rows)->where('received_mismatch', true)->count()],
            ];
        }

        return ['voyage_id' => $voyage->id, 'threshold_pct' => $threshold, 'estimate_basis' => $perDay === [] ? null : 'initial_snapshot_per_day', 'fuels' => $ledgers];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $tot
     */
    private function estimate(array &$row, ?string $perDay, CarbonInterface $from, CarbonInterface $to, string $consumed, string $threshold, array &$tot): void
    {
        if ($perDay === null) {
            return;
        }
        $days = Decimal::div(LocalTime::hoursBetween($from, $to), '24');
        $est = Decimal::mul($perDay, $days);
        $row['estimated_consumed_mt'] = Decimal::round($est, 3);
        $tot['estimated_mt'] = Decimal::add($tot['estimated_mt'] ?? '0', $est);
        $variance = Decimal::sub($consumed, $est);
        $row['variance_mt'] = Decimal::round($variance, 3);
        if (Decimal::cmp($est, '0') > 0) {
            $pct = Decimal::mul(Decimal::div($variance, $est), '100');
            $row['variance_pct'] = Decimal::round($pct, 2);
            $row['flagged'] = Decimal::cmp($this->abs($pct), $threshold) > 0;
        }
    }

    /** @return array<int, string> fuel_type_id => estimated mt per day */
    private function estimatedPerDay(Voyage $voyage): array
    {
        $payload = $voyage->snapshots()->where('type', 'initial')->first()?->payload ?? [];
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $days = $results['total_days'] ?? null;
        if (! is_numeric($days) || Decimal::cmp((string) $days, '0') <= 0) {
            return [];
        }
        $out = [];
        foreach ((array) ($results['breakdown']['fuel'] ?? []) as $f) {
            if (isset($f['fuel_type_id'], $f['total_mt']) && is_numeric($f['total_mt'])) {
                $out[(int) $f['fuel_type_id']] = Decimal::div((string) $f['total_mt'], (string) $days);
            }
        }

        return $out;
    }

    private function abs(string $v): string
    {
        return str_starts_with($v, '-') ? substr($v, 1) : $v;
    }
}
