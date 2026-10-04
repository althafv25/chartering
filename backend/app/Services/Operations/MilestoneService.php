<?php

namespace App\Services\Operations;

use App\Exceptions\BusinessRuleException;
use App\Models\MilestoneType;
use App\Models\PortCall;
use App\Models\User;
use App\Models\Voyage;
use App\Models\VoyageMilestone;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;

/**
 * Voyage milestones (planned / actual events). Times are local to the linked port call,
 * otherwise to the user's timezone. Recording a milestone never changes the voyage or
 * vessel status (BR-VS-02 proposal: suggest only).
 */
class MilestoneService
{
    /** @param array<string, mixed> $data */
    public function save(Voyage $voyage, ?VoyageMilestone $milestone, array $data, User $actor): VoyageMilestone
    {
        return DB::transaction(function () use ($voyage, $milestone, $data, $actor) {
            $v = Voyage::query()->lockForUpdate()->findOrFail($voyage->id);
            if (! $v->isOperationallyOpen()) {
                throw new BusinessRuleException("The voyage is {$v->status}; milestones are read-only.", 'voyage_read_only');
            }
            $m = $milestone ?? new VoyageMilestone(['voyage_id' => $v->id, 'created_by' => $actor->id, 'source' => 'manual']);

            $type = MilestoneType::query()->findOrFail((int) ($data['milestone_type_id'] ?? $m->milestone_type_id));
            $scope = $v->operation_type === 'offshore' ? 'offshore' : 'voyage';
            if (! in_array($type->getAttribute('applies_to'), [$scope, 'both'], true)) {
                throw new BusinessRuleException("Milestone type {$type->getAttribute('name')} does not apply to {$scope} operations.", 'validation_failed',
                    ['milestone_type_id' => ['Not applicable to this operation type.']], 422);
            }
            $callId = array_key_exists('port_call_id', $data) ? ($data['port_call_id'] ? (int) $data['port_call_id'] : null) : $m->port_call_id;
            $call = $callId ? PortCall::query()->where('voyage_id', $v->id)->find($callId) : null;
            if ($callId && ! $call) {
                throw new BusinessRuleException('The port call does not belong to this voyage.', 'validation_failed', ['port_call_id' => ['Invalid port call.']], 422);
            }
            $tz = $call?->timezone() ?? (string) ($actor->getAttribute('timezone') ?: 'UTC');
            $m->fill(['milestone_type_id' => $type->id, 'port_call_id' => $callId, 'remarks' => $data['remarks'] ?? $m->getAttribute('remarks'), 'updated_by' => $actor->id]);
            foreach (['planned_at', 'actual_at'] as $f) {
                if (array_key_exists($f, $data)) {
                    $m->setAttribute($f, LocalTime::toUtc($data[$f], $tz, $f));
                }
            }
            if ($m->planned_at === null && $m->actual_at === null) {
                throw new BusinessRuleException('Enter a planned or an actual time.', 'validation_failed', ['actual_at' => ['Planned or actual time required.']], 422);
            }
            if ($m->actual_at?->greaterThan(now()->addHour())) {
                throw new BusinessRuleException('Actual time cannot be in the future.', 'validation_failed', ['actual_at' => ['Future time.']], 422);
            }
            if ($m->isDirty('actual_at')) {
                $m->fill(['verified_by' => null, 'verified_at' => null]);
            }
            $m->save();

            return $m;
        });
    }

    public function verify(VoyageMilestone $m, User $actor): VoyageMilestone
    {
        if ($m->actual_at === null) {
            throw new BusinessRuleException('Only milestones with an actual time can be verified.', 'milestone_not_actual');
        }
        $m->fill(['verified_by' => $actor->id, 'verified_at' => now()])->save();

        return $m;
    }

    public function delete(Voyage $voyage, VoyageMilestone $m): void
    {
        if (! $voyage->isOperationallyOpen()) {
            throw new BusinessRuleException("The voyage is {$voyage->status}; milestones are read-only.", 'voyage_read_only');
        }
        $m->delete();
    }
}
