<?php

namespace App\Services\Operations;

use App\Exceptions\BusinessRuleException;
use App\Models\OffHireEvent;
use App\Models\User;
use App\Models\Voyage;
use App\Support\Decimal;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;

/**
 * Off-hire periods. Hours are calculated exactly (4 dp). No hire deduction is computed:
 * BR-OP-02 is unconfirmed (assumption A-OH-1) — the hours feed actual days and the
 * comparison only. Periods of one voyage must not overlap. draft|disputed → agreed|disputed.
 */
class OffHireService
{
    /** @param array<string, mixed> $data */
    public function save(Voyage $voyage, ?OffHireEvent $event, array $data, User $actor): OffHireEvent
    {
        return DB::transaction(function () use ($voyage, $event, $data, $actor) {
            $v = Voyage::query()->lockForUpdate()->findOrFail($voyage->id);
            if (! $v->isOperationallyOpen()) {
                throw new BusinessRuleException("The voyage is {$v->status}; off-hire is read-only.", 'voyage_read_only');
            }
            if ($event) {
                $event = OffHireEvent::query()->lockForUpdate()->findOrFail($event->id);
                $event->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
                if ($event->status === 'agreed') {
                    throw new BusinessRuleException('An agreed off-hire event cannot be edited.', 'off_hire_read_only');
                }
            }
            $e = $event ?? new OffHireEvent(['voyage_id' => $v->id, 'created_by' => $actor->id]);
            $tz = (string) ($actor->getAttribute('timezone') ?: 'UTC');
            $e->fill(['reason_code' => $data['reason_code'] ?? $e->reason_code, 'description' => $data['description'] ?? $e->getAttribute('description'), 'updated_by' => $actor->id]);
            if (array_key_exists('from_at', $data)) {
                $e->setAttribute('from_at', LocalTime::toUtc($data['from_at'], $tz, 'from_at') ?? throw new BusinessRuleException('From is required.', 'validation_failed', ['from_at' => ['Required.']], 422));
            }
            if (array_key_exists('to_at', $data)) {
                $e->setAttribute('to_at', LocalTime::toUtc($data['to_at'], $tz, 'to_at'));
            }
            if (array_key_exists('fuel_consumed', $data)) {
                $e->fuel_consumed = $data['fuel_consumed'] ? array_values(array_map(fn ($l) => ['fuel_type_id' => (int) $l['fuel_type_id'], 'mt' => Decimal::round((string) $l['mt'], 3)], $data['fuel_consumed'])) : null;
            }
            $this->validatePeriod($v, $e);
            $e->hours = $e->to_at ? LocalTime::hoursBetween($e->from_at, $e->to_at) : null;
            if ($e->status === 'disputed' && $event) {
                $e->status = 'draft';
            }
            $e->save();

            return $e;
        });
    }

    public function decide(OffHireEvent $event, string $status, User $actor, ?string $comment): OffHireEvent
    {
        return DB::transaction(function () use ($event, $status, $actor, $comment) {
            $e = OffHireEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (! $e->voyage->isOperationallyOpen()) {
                throw new BusinessRuleException("The voyage is {$e->voyage->status}; off-hire is read-only.", 'voyage_read_only');
            }
            if ($e->status === $status || ($e->status === 'agreed' && $status === 'agreed')) {
                throw new BusinessRuleException("The event is already {$status}.", 'invalid_status_transition');
            }
            if ($status === 'agreed' && $e->to_at === null) {
                throw new BusinessRuleException('Enter the end of the off-hire period before agreeing it.', 'off_hire_open_ended');
            }
            $e->fill(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment, 'updated_by' => $actor->id])->save();

            return $e;
        });
    }

    public function delete(OffHireEvent $event): void
    {
        if ($event->status === 'agreed' || ! $event->voyage->isOperationallyOpen()) {
            throw new BusinessRuleException('Agreed off-hire (or off-hire on a closed voyage) cannot be deleted.', 'off_hire_read_only');
        }
        $event->delete();
    }

    private function validatePeriod(Voyage $v, OffHireEvent $e): void
    {
        if ($e->to_at && ! $e->to_at->greaterThan($e->from_at)) {
            throw new BusinessRuleException('Off-hire end must be after the start.', 'validation_failed', ['to_at' => ['Must be after From.']], 422);
        }
        if ($v->commenced_at && $e->from_at->lessThan($v->commenced_at)) {
            throw new BusinessRuleException('Off-hire cannot start before the voyage commenced.', 'validation_failed', ['from_at' => ['Before commencement.']], 422);
        }
        $overlap = OffHireEvent::query()->where('voyage_id', $v->id)->when($e->exists, fn ($q) => $q->whereKeyNot($e->id))
            ->where(function ($q) use ($e) {
                $q->whereNull('to_at')->orWhere('to_at', '>', $e->from_at);
            })
            ->when($e->to_at, fn ($q) => $q->where('from_at', '<', $e->to_at))
            ->exists();
        if ($overlap) {
            throw new BusinessRuleException('This period overlaps another off-hire event.', 'off_hire_overlap', ['from_at' => ['Overlaps another event.']], 422);
        }
    }
}
