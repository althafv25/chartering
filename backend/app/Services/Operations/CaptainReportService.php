<?php

namespace App\Services\Operations;

use App\Exceptions\BusinessRuleException;
use App\Models\CaptainReport;
use App\Models\PortCall;
use App\Models\User;
use App\Models\Voyage;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Captain (noon / arrival / departure / …) reports.
 *   draft|rejected → submitted → verified | rejected (reason)
 * REP-01 only verified reports feed actuals. REP-02 report times per voyage (or per vessel
 * when no voyage) must strictly increase — an out-of-order report is refused.
 * Verifying an arrival/departure report may, on explicit request, copy its time to the
 * linked port call's ATA/ATD (nothing is changed automatically — AIS-01/BR-VS-02 spirit).
 */
class CaptainReportService
{
    public function __construct(private readonly PortCallService $portCalls) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?CaptainReport $report, array $data, User $actor): CaptainReport
    {
        return DB::transaction(function () use ($report, $data, $actor) {
            if ($report) {
                $report = CaptainReport::query()->lockForUpdate()->findOrFail($report->id);
                $report->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
                if (! $report->isEditable()) {
                    throw new BusinessRuleException("A {$report->status} report cannot be edited.", 'report_read_only');
                }
            }
            $r = $report ?? new CaptainReport(['created_by' => $actor->id, 'source' => 'manual']);
            if (! $r->exists) {
                $r->vessel_id = (int) $data['vessel_id'];
                $r->voyage_id = isset($data['voyage_id']) ? (int) $data['voyage_id'] : null;
            }
            $voyage = $r->voyage_id ? Voyage::query()->lockForUpdate()->findOrFail($r->voyage_id) : null;
            if ($voyage && $voyage->vessel_id !== $r->vessel_id) {
                throw new BusinessRuleException('The voyage belongs to a different vessel.', 'validation_failed', ['voyage_id' => ['Vessel mismatch.']], 422);
            }
            if ($voyage && ! $voyage->isOperationallyOpen()) {
                throw new BusinessRuleException("The voyage is {$voyage->status}; reports are read-only.", 'voyage_read_only');
            }
            $r->fill(Arr::only($data, array_diff(CaptainReport::DATA_FIELDS, ['reported_at'])));
            if (array_key_exists('reported_at', $data)) {
                $r->setAttribute('reported_at', CarbonImmutable::parse((string) $data['reported_at'])->utc());
            }
            if ($r->port_call_id && (! $voyage || ! PortCall::query()->where('voyage_id', $voyage->id)->whereKey($r->port_call_id)->exists())) {
                throw new BusinessRuleException('The port call does not belong to the report voyage.', 'validation_failed', ['port_call_id' => ['Invalid port call.']], 422);
            }
            if ($r->reported_at->greaterThan(now()->addHours(14))) {
                throw new BusinessRuleException('The report time is in the future.', 'validation_failed', ['reported_at' => ['Future time.']], 422);
            }
            $this->assertChronology($r);
            $r->setAttribute('updated_by', $actor->id);
            $r->save();

            if (array_key_exists('fuel_lines', $data)) {
                $r->fuelLines()->delete();
                $seen = [];
                foreach ((array) $data['fuel_lines'] as $line) {
                    $fuel = (int) $line['fuel_type_id'];
                    if (isset($seen[$fuel])) {
                        throw new BusinessRuleException('Each fuel type may appear only once.', 'validation_failed', ['fuel_lines' => ['Duplicate fuel type.']], 422);
                    }
                    $seen[$fuel] = true;
                    $r->fuelLines()->create([
                        'fuel_type_id' => $fuel,
                        'rob_mt' => isset($line['rob_mt']) && $line['rob_mt'] !== '' ? Decimal::round((string) $line['rob_mt'], 3) : null,
                        'consumed_mt' => Decimal::round((string) ($line['consumed_mt'] ?? '0'), 3),
                        'received_mt' => Decimal::round((string) ($line['received_mt'] ?? '0'), 3),
                    ]);
                }
            }

            return $r;
        });
    }

    public function submit(CaptainReport $report, User $actor): CaptainReport
    {
        return $this->move($report, ['draft', 'rejected'], 'submitted', $actor, ['submitted_by' => $actor->id, 'submitted_at' => now(), 'decision_comment' => null]);
    }

    public function verify(CaptainReport $report, User $actor, ?string $comment, bool $applyToPortCall): CaptainReport
    {
        $r = $this->move($report, ['submitted'], 'verified', $actor, ['verified_by' => $actor->id, 'verified_at' => now(), 'decision_comment' => $comment]);
        if ($applyToPortCall && $r->port_call_id && in_array($r->report_type, ['arrival', 'departure'], true)) {
            $call = PortCall::query()->findOrFail($r->port_call_id);
            $field = $r->report_type === 'arrival' ? 'ata' : 'atd';
            $this->portCalls->update($call, [$field => $r->reported_at->toIso8601String(), 'lock_version' => $call->lock_version], $actor);
        }

        return $r;
    }

    public function reject(CaptainReport $report, User $actor, string $reason): CaptainReport
    {
        return $this->move($report, ['submitted'], 'rejected', $actor, ['verified_by' => null, 'verified_at' => null, 'decision_comment' => $reason]);
    }

    public function delete(CaptainReport $report): void
    {
        if ($report->status !== 'draft') {
            throw new BusinessRuleException('Only draft reports can be deleted.', 'report_read_only');
        }
        $report->delete();
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(CaptainReport $report, array $from, string $to, User $actor, array $extra): CaptainReport
    {
        return DB::transaction(function () use ($report, $from, $to, $actor, $extra) {
            $r = CaptainReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($r->status, $from, true)) {
                throw new BusinessRuleException("A {$r->status} report cannot become {$to}.", 'invalid_status_transition');
            }
            if ($r->voyage_id && ! Voyage::query()->findOrFail($r->voyage_id)->isOperationallyOpen()) {
                throw new BusinessRuleException('The voyage is closed; reports are read-only.', 'voyage_read_only');
            }
            $old = $r->status;
            $r->fill([...$extra, 'status' => $to, 'updated_by' => $actor->id])->save();
            activity('captain_reports')->performedOn($r)->causedBy($actor)->event($to)
                ->withProperties(['old' => ['status' => $old], 'attributes' => ['status' => $to, 'comment' => $extra['decision_comment'] ?? null]])
                ->log("Captain report {$r->report_type} {$r->reported_at->toIso8601String()} {$to}");

            return $r;
        });
    }

    /** REP-02: strictly increasing times within the voyage (or vessel when unassigned); rejected reports are ignored. */
    private function assertChronology(CaptainReport $r): void
    {
        $scope = CaptainReport::query()->where('status', '!=', 'rejected')
            ->when($r->voyage_id, fn ($q) => $q->where('voyage_id', $r->voyage_id), fn ($q) => $q->where('vessel_id', $r->vessel_id)->whereNull('voyage_id'))
            ->when($r->exists, fn ($q) => $q->whereKeyNot($r->id));

        $original = $r->exists ? $r->getOriginal('reported_at') : null;
        if ($original === null) {
            $latest = (clone $scope)->max('reported_at');
            if ($latest && ! $r->reported_at->greaterThan(CarbonImmutable::parse($latest, 'UTC'))) {
                throw new BusinessRuleException('Out-of-order report: it must be later than the latest report ('.CarbonImmutable::parse($latest, 'UTC')->toIso8601String().').',
                    'report_out_of_order', ['reported_at' => ['Must be after the latest report.']], 422);
            }

            return;
        }
        $prev = (clone $scope)->where('reported_at', '<', $original)->max('reported_at');
        $next = (clone $scope)->where('reported_at', '>', $original)->min('reported_at');
        if (($prev && ! $r->reported_at->greaterThan(CarbonImmutable::parse($prev, 'UTC'))) || ($next && ! $r->reported_at->lessThan(CarbonImmutable::parse($next, 'UTC')))) {
            throw new BusinessRuleException('Out-of-order report: the time must stay between the previous and next report.', 'report_out_of_order',
                ['reported_at' => ['Out of sequence.']], 422);
        }
    }
}
