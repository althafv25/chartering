<?php

namespace App\Http\Resources\Chartering;

use App\Models\Estimation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Estimation
 */
class EstimationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $user = fn (string $rel) => $this->whenLoaded($rel, fn () => $e->{$rel} ? ['id' => $e->{$rel}->id, 'name' => $e->{$rel}->name] : null);

        return [
            'id' => $e->id,
            'estimation_number' => $e->estimation_number,
            'estimation_type' => $e->estimation_type,
            'title' => $e->getAttribute('title'),
            'currency' => $e->currency,
            'status' => $e->status,
            'is_editable' => $e->isEditable(),
            'enquiry_id' => $e->enquiry_id,
            'enquiry' => $this->whenLoaded('enquiry', fn () => $e->enquiry ? [
                'id' => $e->enquiry->id, 'enquiry_number' => $e->enquiry->enquiry_number, 'status' => $e->enquiry->status,
                'business_type' => $e->enquiry->business_type,
                'charterer' => $e->enquiry->relationLoaded('charterer') ? $e->enquiry->charterer?->legal_name : null,
            ] : null),
            'vessel_id' => $e->vessel_id,
            'vessel' => $this->whenLoaded('vessel', fn () => $e->vessel->only(['id', 'code', 'name', 'imo_number'])),
            'submitted_by' => $user('submitter'),
            'submitted_at' => $e->submitted_at?->toIso8601String(),
            'decided_by' => $user('decider'),
            'decided_at' => $e->decided_at?->toIso8601String(),
            'decision_comment' => $e->getAttribute('decision_comment'),
            'cloned_from' => $this->whenLoaded('clonedFrom', fn () => $e->clonedFrom ? ['id' => $e->clonedFrom->id, 'estimation_number' => $e->clonedFrom->estimation_number] : null),
            'remarks' => $e->getAttribute('remarks'),
            'lock_version' => $e->lock_version,
            'scenarios_count' => $this->whenCounted('scenarios'),
            'selected_scenario' => $this->whenLoaded('selectedScenario', fn () => $e->selectedScenario ? tap(new ScenarioResource($e->selectedScenario), fn ($r) => $r->summary = true)->resolve($request) : null),
            'scenarios' => $this->whenLoaded('scenarios', fn () => $e->scenarios->map(fn ($s) => tap(new ScenarioResource($s), fn ($r) => $r->summary = true)->resolve($request))),
            'created_at' => $e->created_at?->toIso8601String(),
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }
}
