<?php

namespace App\Http\Resources\Operations;

use App\Models\VoyageMilestone;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VoyageMilestone
 */
class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $m = $this->resource;
        $tz = $m->portCall?->timezone() ?? (string) ($request->user()?->getAttribute('timezone') ?: 'UTC');

        return [
            ...$m->only(['id', 'voyage_id', 'port_call_id', 'milestone_type_id', 'source', 'captain_report_id', 'remarks']),
            'type' => $m->type?->only(['id', 'code', 'name', 'applies_to', 'is_laytime_relevant']),
            'port_call' => $m->portCall ? ['id' => $m->portCall->id, 'sequence' => $m->portCall->sequence, 'label' => $m->portCall->label()] : null,
            'planned_at' => $m->planned_at?->toIso8601String(),
            'actual_at' => $m->actual_at?->toIso8601String(),
            'planned_at_local' => LocalTime::toLocal($m->planned_at, $tz),
            'actual_at_local' => LocalTime::toLocal($m->actual_at, $tz),
            'timezone' => $tz,
            'verified_by' => $m->verifier?->only(['id', 'name']),
            'verified_at' => $m->verified_at?->toIso8601String(),
        ];
    }
}
