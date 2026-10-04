<?php

namespace App\Services\Operations;

use App\Enums\VoyageRevenueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Voyage;
use App\Models\VoyageSnapshot;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Voyage lifecycle (07 §6):
 *   operational moves per Voyage::TRANSITIONS (optionally back-dated with `at`)
 *   → completed (OP-03: all port calls sailed/cancelled, no open captain reports)
 *   → finalized (writes the immutable `final` snapshot; OP-04 financial gates below)
 *   finalized → completed via reopen (OP-05: reason, old final kept as a milestone)
 *   any non-terminal → cancelled (reason; OP: nothing invoiced — no invoices exist before phase 10)
 * Commencement (BR-OP-03 proposal) = first move out of draft/nominated.
 *
 * OP-04 finalization gates (each may be explicitly waived with a reason, since several
 * of the underlying business rules — BR-LT-*, BR-DA-01, BK-03 default — are still
 * [CONFIRM] pending; a waiver is recorded on the voyage and audited, never silent):
 *   - ledger: no bunker ROB discontinuity/consumption-variance flag outstanding
 *   - revenue: no voyage revenue left Confirmed (not yet Invoiced/Cancelled)
 *   - port_da: every linked final Port DA is approved or settled
 *   - laytime: every linked laytime calculation is agreed (not draft/submitted/disputed)
 */
class VoyageLifecycleService
{
    /** Gate key => human label, used in error payloads and the waiver JSON. */
    public const GATES = [
        'ledger' => 'Bunker ROB ledger reconciliation',
        'revenue' => 'Confirmed revenue invoiced',
        'port_da' => 'Port DA finals approved',
        'laytime' => 'Laytime agreed',
    ];

    public function __construct(
        private readonly VoyageMetricsService $metrics,
        private readonly RobLedgerService $robLedger,
    ) {}

    /** @param array<string, mixed> $data */
    public function update(Voyage $voyage, array $data, User $actor): Voyage
    {
        return $this->locked($voyage, function (Voyage $v) use ($data, $actor) {
            if ($v->status === 'finalized' || $v->status === 'cancelled') {
                throw new BusinessRuleException("A {$v->status} voyage is read-only.", 'voyage_read_only');
            }
            $v->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            $v->fill(['remarks' => $data['remarks'] ?? $v->getAttribute('remarks'), 'updated_by' => $actor->id])->save();

            return $v;
        });
    }

    public function transition(Voyage $voyage, string $to, User $actor, ?CarbonImmutable $at = null, ?string $note = null): Voyage
    {
        return $this->locked($voyage, function (Voyage $v) use ($to, $actor, $at, $note) {
            $allowed = Voyage::TRANSITIONS[$v->status] ?? [];
            if (! in_array($to, $allowed, true)) {
                throw new BusinessRuleException("Voyage cannot move from {$v->status} to {$to}.", 'invalid_status_transition',
                    ['status' => ['Allowed: '.($allowed === [] ? 'none (use complete/cancel)' : implode(', ', $allowed))]]);
            }
            $when = $this->effectiveTime($v, $at);
            $from = $v->status;
            $v->status = $to;
            $v->setAttribute('status_changed_at', $when);
            if (in_array($from, Voyage::PRE_COMMENCEMENT, true) && ! in_array($to, Voyage::PRE_COMMENCEMENT, true) && $v->commenced_at === null) {
                $v->setAttribute('commenced_at', $when);
            }
            $v->setAttribute('updated_by', $actor->id);
            $v->save();
            $this->log($v, $actor, 'status_changed', "Voyage {$v->voyage_number}: {$from} → {$to}", ['from' => $from, 'to' => $to, 'at' => $when->toIso8601String(), 'note' => $note]);

            return $v;
        });
    }

    public function complete(Voyage $voyage, User $actor, ?CarbonImmutable $at = null): Voyage
    {
        return $this->locked($voyage, function (Voyage $v) use ($actor, $at) {
            if (! in_array($v->status, Voyage::COMPLETABLE, true)) {
                throw new BusinessRuleException("A {$v->status} voyage cannot be completed.", 'invalid_status_transition');
            }
            $openCalls = $v->portCalls()->whereNotIn('status', ['sailed', 'cancelled'])->count();
            $openReports = $v->captainReports()->whereIn('status', ['draft', 'submitted'])->count();
            if ($openCalls > 0 || $openReports > 0) {
                $errors = [];
                if ($openCalls > 0) {
                    $errors['port_calls'] = ["{$openCalls} port call(s) not sailed or cancelled."];
                }
                if ($openReports > 0) {
                    $errors['captain_reports'] = ["{$openReports} captain report(s) still draft or awaiting verification."];
                }
                throw new BusinessRuleException('The voyage cannot be completed yet (OP-03).', 'voyage_not_completable', $errors);
            }
            $when = $this->effectiveTime($v, $at);
            $from = $v->status;
            $v->fill(['status' => 'completed', 'completed_at' => $when, 'status_changed_at' => $when, 'updated_by' => $actor->id])->save();
            $this->log($v, $actor, 'completed', "Voyage {$v->voyage_number} completed", ['from' => $from, 'at' => $when->toIso8601String()]);

            return $v;
        });
    }

    public function finalize(Voyage $voyage, User $actor, array $waivers = []): Voyage
    {
        return $this->locked($voyage, function (Voyage $v) use ($actor, $waivers) {
            if ($v->status !== 'completed') {
                throw new BusinessRuleException('Only a completed voyage can be finalized.', 'invalid_status_transition');
            }
            $draftOffHire = $v->offHires()->where('status', 'draft')->count();
            if ($draftOffHire > 0) {
                throw new BusinessRuleException('Agree or dispute all off-hire events before finalizing.', 'off_hire_open', ['off_hire' => ["{$draftOffHire} draft event(s)."]]);
            }

            $gates = $this->evaluateGates($v);
            $applied = [];
            $errors = [];
            foreach ($gates as $key => $gate) {
                if ($gate['passed']) {
                    continue;
                }
                $reason = trim((string) ($waivers[$key] ?? ''));
                if ($reason === '' || mb_strlen($reason) < 10) {
                    $errors[$key] = [$gate['message']];

                    continue;
                }
                $applied[$key] = ['reason' => $reason, 'waived_by' => $actor->id, 'waived_at' => now()->toIso8601String()];
            }
            if ($errors !== []) {
                throw new BusinessRuleException('The voyage cannot be finalized yet (OP-04). Waive each failing gate with a reason (≥10 characters) to proceed anyway.', 'voyage_not_finalizable', $errors);
            }

            $v->unsetRelation('portCalls')->unsetRelation('offHires');
            VoyageSnapshot::query()->create([
                'voyage_id' => $v->id, 'type' => 'final', 'name' => 'Final'.($v->reopened_count > 0 ? " (#{$v->reopened_count})" : ''),
                'payload' => $this->metrics->snapshotPayload($v), 'calculation_version' => 'ops-1', 'created_by' => $actor->id,
            ]);
            $v->fill(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $actor->id, 'finalization_waivers' => $applied === [] ? null : $applied,
                'status_changed_at' => now(), 'updated_by' => $actor->id])->save();
            $this->log($v, $actor, 'finalized', "Voyage {$v->voyage_number} finalized", ['waivers' => $applied]);

            return $v;
        });
    }

    /**
     * OP-04 gate status for display before the user attempts to finalize (e.g. on the voyage
     * detail page). Does not require `completed` status so the UI can show it earlier.
     *
     * @return array<string, array{label: string, passed: bool, message: string, detail: array<string, mixed>}>
     */
    public function financeGateStatus(Voyage $voyage): array
    {
        return $this->evaluateGates($voyage);
    }

    /** @return array<string, array{label: string, passed: bool, message: string, detail: array<string, mixed>}> */
    private function evaluateGates(Voyage $v): array
    {
        return [
            'ledger' => $this->ledgerGate($v),
            'revenue' => $this->revenueGate($v),
            'port_da' => $this->portDaGate($v),
            'laytime' => $this->laytimeGate($v),
        ];
    }

    /** @return array{label: string, passed: bool, message: string, detail: array<string, mixed>} */
    private function ledgerGate(Voyage $v): array
    {
        $ledger = $this->robLedger->forVoyage($v);
        $flags = ['rob_discontinuity' => 0, 'consumption' => 0, 'received_mismatch' => 0];
        foreach ($ledger['fuels'] as $fuel) {
            foreach ($flags as $k => $_) {
                $flags[$k] += (int) ($fuel['flags'][$k] ?? 0);
            }
        }
        $total = array_sum($flags);

        return [
            'label' => self::GATES['ledger'], 'passed' => $total === 0,
            'message' => $total === 0 ? 'Ledger reconciled.' : "{$total} bunker ROB flag(s) outstanding (discontinuity/consumption/received mismatch).",
            'detail' => $flags,
        ];
    }

    /** @return array{label: string, passed: bool, message: string, detail: array<string, mixed>} */
    private function revenueGate(Voyage $v): array
    {
        $count = $v->voyageRevenues()->where('status', VoyageRevenueStatus::Confirmed)->count();

        return [
            'label' => self::GATES['revenue'], 'passed' => $count === 0,
            'message' => $count === 0 ? 'All confirmed revenue has been invoiced.' : "{$count} confirmed revenue line(s) not yet invoiced.",
            'detail' => ['confirmed_not_invoiced' => $count],
        ];
    }

    /** @return array{label: string, passed: bool, message: string, detail: array<string, mixed>} */
    private function portDaGate(Voyage $v): array
    {
        $finals = $v->portDas()->where('da_type', 'final')->get(['id', 'status']);
        $notApproved = $finals->whereNotIn('status', ['approved', 'settled'])->count();

        return [
            'label' => self::GATES['port_da'], 'passed' => $notApproved === 0,
            'message' => $notApproved === 0 ? 'All final Port DAs are approved.' : "{$notApproved} final Port DA(s) not yet approved.",
            'detail' => ['finals_total' => $finals->count(), 'not_approved' => $notApproved],
        ];
    }

    /** @return array{label: string, passed: bool, message: string, detail: array<string, mixed>} */
    private function laytimeGate(Voyage $v): array
    {
        $calcs = $v->laytimeCalculations()->get(['id', 'status']);
        $notAgreed = $calcs->where('status', '!=', 'agreed')->count();

        return [
            'label' => self::GATES['laytime'], 'passed' => $notAgreed === 0,
            'message' => $notAgreed === 0 ? 'All laytime calculations are agreed.' : "{$notAgreed} laytime calculation(s) not yet agreed.",
            'detail' => ['total' => $calcs->count(), 'not_agreed' => $notAgreed],
        ];
    }

    public function reopen(Voyage $voyage, User $actor, string $reason): Voyage
    {
        return $this->locked($voyage, function (Voyage $v) use ($actor, $reason) {
            if ($v->status !== 'finalized') {
                throw new BusinessRuleException('Only a finalized voyage can be reopened.', 'invalid_status_transition');
            }
            $count = $v->reopened_count + 1;
            // OP-05: the old final snapshot is kept unchanged and reclassified as a milestone
            // (payload untouched). Done with a query-builder update on purpose: the model forbids edits.
            DB::table('voyage_snapshots')->where('voyage_id', $v->id)->where('type', 'final')
                ->update(['type' => 'milestone', 'name' => "Final before reopen #{$count}", 'updated_at' => now()]);
            $v->fill(['status' => 'completed', 'finalized_at' => null, 'finalized_by' => null, 'reopened_count' => $count, 'status_reason' => $reason,
                'status_changed_at' => now(), 'updated_by' => $actor->id])->save();
            $this->log($v, $actor, 'reopened', "Voyage {$v->voyage_number} reopened", ['reason' => $reason, 'reopened_count' => $count]);

            return $v;
        });
    }

    public function cancel(Voyage $voyage, User $actor, string $reason): Voyage
    {
        return $this->locked($voyage, function (Voyage $v) use ($actor, $reason) {
            if (in_array($v->status, Voyage::LOCKED, true)) {
                throw new BusinessRuleException("A {$v->status} voyage cannot be cancelled.", 'invalid_status_transition');
            }
            $from = $v->status;
            $v->fill(['status' => 'cancelled', 'cancelled_at' => now(), 'status_reason' => $reason, 'status_changed_at' => now(), 'updated_by' => $actor->id])->save();
            $this->log($v, $actor, 'cancelled', "Voyage {$v->voyage_number} cancelled", ['from' => $from, 'reason' => $reason]);

            return $v;
        });
    }

    public function createMilestoneSnapshot(Voyage $voyage, User $actor, string $name): VoyageSnapshot
    {
        return DB::transaction(function () use ($voyage, $actor, $name) {
            $v = Voyage::query()->lockForUpdate()->findOrFail($voyage->id);
            if (in_array($v->status, ['draft', 'cancelled'], true)) {
                throw new BusinessRuleException("Snapshots cannot be taken on a {$v->status} voyage.", 'voyage_not_started');
            }
            $snapshot = VoyageSnapshot::query()->create([
                'voyage_id' => $v->id, 'type' => 'milestone', 'name' => $name, 'payload' => $this->metrics->snapshotPayload($v),
                'calculation_version' => 'ops-1', 'created_by' => $actor->id,
            ]);
            $this->log($v, $actor, 'snapshot_created', "Snapshot \"{$name}\" saved", ['snapshot_id' => $snapshot->id]);

            return $snapshot;
        });
    }

    /** Back-dating is allowed (reports arrive late) but never before the last status change or into the future. */
    private function effectiveTime(Voyage $v, ?CarbonImmutable $at): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $when = $at ?? $now;
        if ($when->greaterThan($now->addMinutes(5))) {
            throw new BusinessRuleException('The event time cannot be in the future.', 'invalid_datetime', ['at' => ['Future time.']], 422);
        }
        $last = $v->getAttribute('status_changed_at');
        if ($last && $when->lessThan($last)) {
            throw new BusinessRuleException('The event time is before the previous status change.', 'invalid_datetime', ['at' => ['Before '.$last->toIso8601String().'.']], 422);
        }

        return $when;
    }

    /**
     * @param  Closure(Voyage): Voyage  $fn
     */
    private function locked(Voyage $voyage, Closure $fn): Voyage
    {
        return DB::transaction(fn () => $fn(Voyage::query()->lockForUpdate()->findOrFail($voyage->id)));
    }

    /** @param array<string, mixed> $props */
    private function log(Voyage $v, User $actor, string $event, string $message, array $props): void
    {
        activity('voyages')->performedOn($v)->causedBy($actor)->event($event)->withProperties($props)->log($message);
    }
}
