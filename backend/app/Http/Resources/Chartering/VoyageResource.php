<?php

namespace App\Http\Resources\Chartering;

use App\Http\Resources\Operations\MilestoneResource;
use App\Http\Resources\Operations\OffHireResource;
use App\Http\Resources\Operations\PortCallResource;
use App\Models\Voyage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Voyage
 */
class VoyageResource extends JsonResource
{
    /** Detail view: include port calls, milestones and off-hire. */
    public bool $withChildren = false;

    public function toArray(Request $request): array
    {
        $v = $this->resource;

        return [
            ...collect($v->only(['id', 'voyage_number', 'vessel_id', 'fixture_id', 'contract_id', 'estimation_id', 'estimation_scenario_id', 'conversion_type',
                'direct_reason', 'operation_type', 'charterer_company_id', 'currency', 'status', 'remarks', 'lock_version', 'status_reason', 'reopened_count']))->all(),
            'commenced_at' => $v->commenced_at?->toIso8601String(),
            'completed_at' => $v->completed_at?->toIso8601String(),
            'finalized_at' => $v->finalized_at?->toIso8601String(),
            'finalization_waivers' => $v->getAttribute('finalization_waivers'),
            'cancelled_at' => $v->getAttribute('cancelled_at')?->toIso8601String(),
            'status_changed_at' => $v->getAttribute('status_changed_at')?->toIso8601String(),
            'allowed_transitions' => Voyage::TRANSITIONS[$v->status] ?? [],
            'can_complete' => in_array($v->status, Voyage::COMPLETABLE, true),
            'is_open' => $v->isOperationallyOpen(),
            'charterer' => $this->whenLoaded('charterer', fn () => $v->charterer?->only(['id', 'legal_name'])),
            'next_port_call' => $this->whenLoaded('portCalls', function () use ($v) {
                $next = $v->portCalls->first(fn ($c) => ! in_array($c->status, ['sailed', 'cancelled'], true));

                return $next ? ['id' => $next->id, 'label' => $next->label(), 'eta' => $next->eta?->toIso8601String(), 'status' => $next->status] : null;
            }),
            'port_calls' => $this->when($this->withChildren, fn () => PortCallResource::collection($v->portCalls)),
            'milestones' => $this->when($this->withChildren, fn () => MilestoneResource::collection($v->milestones)),
            'off_hires' => $this->when($this->withChildren, fn () => OffHireResource::collection($v->offHires)),
            'vessel' => $this->whenLoaded('vessel', fn () => $v->vessel->only(['id', 'code', 'name'])),
            'fixture' => $this->whenLoaded('fixture', fn () => $v->fixture?->only(['id', 'fixture_number'])),
            'contract' => $this->whenLoaded('contract', fn () => $v->contract?->only(['id', 'contract_number', 'status'])),
            'estimation' => $this->whenLoaded('estimation', fn () => $v->estimation?->only(['id', 'estimation_number', 'estimation_type', 'status'])),
            'scenario' => $this->whenLoaded('scenario', fn () => $v->scenario?->only(['id', 'code', 'name'])),
            'snapshots' => $this->whenLoaded('snapshots', fn () => $v->snapshots->map(fn ($s) => [
                'id' => $s->id, 'type' => $s->type, 'name' => $s->getAttribute('name'), 'calculation_version' => $s->getAttribute('calculation_version'),
                'inputs_hash' => $s->getAttribute('inputs_hash'), 'payload' => $s->payload, 'created_at' => $s->created_at?->toIso8601String(),
            ])),
            'created_at' => $v->created_at?->toIso8601String(),
        ];
    }
}
