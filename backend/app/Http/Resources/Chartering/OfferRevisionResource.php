<?php

namespace App\Http\Resources\Chartering;

use App\Models\OfferRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OfferRevision
 */
class OfferRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $scenario = $r->relationLoaded('scenario') ? $r->scenario : null;

        return [
            'id' => $r->id,
            'offer_id' => $r->offer_id,
            'revision_no' => $r->revision_no,
            'direction' => $r->direction,
            'status' => $r->status,
            'is_immutable' => $r->isImmutable(),
            'is_open' => $r->isOpen(),
            'estimation_scenario_id' => $r->estimation_scenario_id,
            'scenario' => $scenario ? [
                'id' => $scenario->id, 'code' => $scenario->code, 'name' => $scenario->name, 'is_selected' => $scenario->is_selected,
                'estimation_id' => $scenario->estimation_id,
                'estimation_number' => $scenario->relationLoaded('estimation') ? $scenario->estimation->estimation_number : null,
                'estimation_status' => $scenario->relationLoaded('estimation') ? $scenario->estimation->status : null,
            ] : null,
            'rate' => $r->rate,
            'rate_basis' => $r->rate_basis,
            'currency' => $r->currency,
            'quantity' => $r->quantity,
            'quantity_unit' => $r->getAttribute('quantity_unit'),
            'laycan_from' => $r->laycan_from?->toDateString(),
            'laycan_to' => $r->laycan_to?->toDateString(),
            'period_days' => $r->getAttribute('period_days'),
            'ports' => $r->ports,
            'commissions' => $r->commissions,
            'terms' => $r->getAttribute('terms'),
            'valid_until' => $r->valid_until?->toDateString(),
            'remarks' => $r->getAttribute('remarks'),
            'sent_at' => $r->sent_at?->toIso8601String(),
            'received_at' => $r->received_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_reason' => $r->getAttribute('decision_reason'),
            'created_by' => $this->whenLoaded('creator', fn () => $r->creator?->name),
            'fixture' => $this->whenLoaded('fixture', fn () => $r->fixture ? ['id' => $r->fixture->id, 'fixture_number' => $r->fixture->fixture_number] : null),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
