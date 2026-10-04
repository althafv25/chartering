<?php

namespace App\Services\Operations;

use App\Exceptions\BusinessRuleException;
use App\Models\OffHireEvent;
use App\Models\Vessel;
use App\Models\Voyage;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fleet report bodies (03 M17): vessel utilization and vessel performance. Each returns [columns, rows] in the
 * shape ReportService expects. Pure read-side; permissions are checked by ReportService.
 */
class FleetReports
{
    public const MAX_UTILIZATION_DAYS = 366;

    /**
     * Utilization = (days on a commenced, non-cancelled voyage − non-disputed off-hire days) / days in the period.
     * Overlapping voyages of one vessel are merged so a day is never counted twice; open voyages run to "now".
     * Period defaults to the last 90 days; longer than 366 days is refused.
     *
     * @param  array<string, mixed>  $f  from, to, vessel_id
     * @return array{0: list<array{key: string, label: string, align: string}>, 1: list<array<string, string|null>>}
     */
    public function utilization(array $f): array
    {
        $now = CarbonImmutable::now();
        $to = ! empty($f['to']) ? CarbonImmutable::parse($f['to'])->endOfDay() : $now;
        $from = ! empty($f['from']) ? CarbonImmutable::parse($f['from'])->startOfDay() : $to->subDays(89)->startOfDay();
        if ($to->lessThan($from)) {
            throw new BusinessRuleException('The end date must not be before the start date.', 'validation_failed', ['to' => ['Before "from".']], 422);
        }
        if ($from->diffInDays($to) > self::MAX_UTILIZATION_DAYS) {
            throw new BusinessRuleException('The period is limited to '.self::MAX_UTILIZATION_DAYS.' days.', 'validation_failed', ['from' => ['Maximum '.self::MAX_UTILIZATION_DAYS.' days.']], 422);
        }
        $periodSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());

        $voyages = Voyage::query()->whereNotNull('commenced_at')->where('status', '!=', 'cancelled')
            ->where('commenced_at', '<=', $to)->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $from))
            ->when(! empty($f['vessel_id']), fn ($q) => $q->where('vessel_id', (int) $f['vessel_id']))
            ->get(['id', 'vessel_id', 'commenced_at', 'completed_at']);

        /** @var array<int, list<array{0: int, 1: int}>> $intervals */
        $intervals = [];
        $voyageCount = [];
        foreach ($voyages as $v) {
            $start = max($v->commenced_at->getTimestamp(), $from->getTimestamp());
            $end = min(($v->completed_at ?? $now)->getTimestamp(), $to->getTimestamp());
            if ($end > $start) {
                $intervals[$v->vessel_id][] = [$start, $end];
                $voyageCount[$v->vessel_id] = ($voyageCount[$v->vessel_id] ?? 0) + 1;
            }
        }

        $vesselOf = $voyages->pluck('vessel_id', 'id');
        $offHire = [];
        foreach (OffHireEvent::query()->whereIn('voyage_id', $voyages->pluck('id'))->where('status', '!=', 'disputed')->get(['voyage_id', 'from_at', 'to_at']) as $e) {
            $start = max($e->from_at->getTimestamp(), $from->getTimestamp());
            $end = min(($e->to_at ?? $now)->getTimestamp(), $to->getTimestamp());
            if ($end > $start) {
                $vesselId = $vesselOf[$e->voyage_id] ?? null;
                $offHire[$vesselId] = ($offHire[$vesselId] ?? 0) + ($end - $start);
            }
        }

        $rows = [];
        $vessels = Vessel::query()->where('status', 'active')->when(! empty($f['vessel_id']), fn ($q) => $q->whereKey((int) $f['vessel_id']))->orderBy('name')->get(['id', 'name']);
        foreach ($vessels as $vessel) {
            $onVoyage = $this->mergedSeconds($intervals[$vessel->id] ?? []);
            $off = min($offHire[$vessel->id] ?? 0, $onVoyage);
            $util = Decimal::mul(Decimal::div((string) ($onVoyage - $off), (string) $periodSeconds), '100');
            $rows[] = [
                'vessel' => $vessel->name,
                'period_days' => Decimal::round(Decimal::div((string) $periodSeconds, '86400'), 2),
                'voyage_days' => Decimal::round(Decimal::div((string) $onVoyage, '86400'), 2),
                'off_hire_days' => Decimal::round(Decimal::div((string) $off, '86400'), 2),
                'utilization_pct' => Decimal::round($util, 2),
                'voyages' => (string) ($voyageCount[$vessel->id] ?? 0),
            ];
        }
        usort($rows, fn (array $a, array $b) => Decimal::cmp((string) $b['utilization_pct'], (string) $a['utilization_pct']));

        return [[
            $this->col('vessel', 'Vessel'), $this->col('period_days', 'Period (days)', 'right'), $this->col('voyage_days', 'On voyage (days)', 'right'),
            $this->col('off_hire_days', 'Off-hire (days)', 'right'), $this->col('utilization_pct', 'Utilization %', 'right'), $this->col('voyages', 'Voyages', 'right'),
        ], $rows];
    }

    /**
     * Performance from verified captain reports only (REP-01): distance, fuel, average reported speed and fuel per
     * nautical mile per vessel, next to the vessel's design service speed.
     *
     * @param  array<string, mixed>  $f  from, to, vessel_id
     * @return array{0: list<array{key: string, label: string, align: string}>, 1: list<array<string, string|null>>}
     */
    public function performance(array $f): array
    {
        $reports = DB::table('captain_reports as r')->where('r.status', 'verified')->whereNull('r.deleted_at')
            ->when(! empty($f['vessel_id']), fn ($q) => $q->where('r.vessel_id', (int) $f['vessel_id']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('r.reported_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('r.reported_at', '<=', $f['to']));

        $perVessel = (clone $reports)->groupBy('r.vessel_id')
            ->selectRaw('r.vessel_id, COUNT(*) AS reports, COALESCE(SUM(r.distance_since_last_nm), 0) AS distance, AVG(r.speed_kn) AS avg_speed')
            ->get()->keyBy('vessel_id');
        $fuel = (clone $reports)->join('captain_report_fuel_lines as l', 'l.captain_report_id', '=', 'r.id')->groupBy('r.vessel_id')
            ->selectRaw('r.vessel_id, COALESCE(SUM(l.consumed_mt), 0) AS consumed')->get()->keyBy('vessel_id');

        $rows = [];
        foreach (Vessel::query()->whereIn('id', $perVessel->keys())->orderBy('name')->get(['id', 'name', 'service_speed_kn']) as $vessel) {
            $r = $perVessel[$vessel->id];
            $distance = Decimal::round((string) $r->distance, 2);
            $consumed = Decimal::round((string) ($fuel[$vessel->id]->consumed ?? '0'), 3);
            $rows[] = [
                'vessel' => $vessel->name, 'reports' => (string) $r->reports, 'distance_nm' => $distance,
                'avg_speed_kn' => $r->avg_speed !== null ? Decimal::round((string) $r->avg_speed, 2) : null,
                'service_speed_kn' => $vessel->service_speed_kn !== null ? Decimal::round((string) $vessel->service_speed_kn, 2) : null,
                'fuel_mt' => $consumed,
                'mt_per_nm' => Decimal::cmp($distance, '0') > 0 ? Decimal::round(Decimal::div($consumed, $distance), 4) : null,
            ];
        }

        return [[
            $this->col('vessel', 'Vessel'), $this->col('reports', 'Verified reports', 'right'), $this->col('distance_nm', 'Distance (nm)', 'right'),
            $this->col('avg_speed_kn', 'Avg reported speed (kn)', 'right'), $this->col('service_speed_kn', 'Service speed (kn)', 'right'),
            $this->col('fuel_mt', 'Fuel consumed (mt)', 'right'), $this->col('mt_per_nm', 'Fuel per nm (mt)', 'right'),
        ], $rows];
    }

    /** @param list<array{0: int, 1: int}> $intervals */
    private function mergedSeconds(array $intervals): int
    {
        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $total = 0;
        $curStart = null;
        $curEnd = null;
        foreach ($intervals as [$start, $end]) {
            if ($curEnd === null || $start > $curEnd) {
                $total += $curEnd !== null ? $curEnd - $curStart : 0;
                [$curStart, $curEnd] = [$start, $end];
            } else {
                $curEnd = max($curEnd, $end);
            }
        }

        return $total + ($curEnd !== null ? $curEnd - $curStart : 0);
    }

    /** @return array{key: string, label: string, align: string} */
    private function col(string $key, string $label, string $align = 'left'): array
    {
        return ['key' => $key, 'label' => $label, 'align' => $align];
    }
}
