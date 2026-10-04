<?php

namespace App\Http\Resources\Operations;

use App\Models\CaptainReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CaptainReport
 */
class CaptainReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            ...$r->only(['id', 'vessel_id', 'voyage_id', 'port_call_id', 'report_type', 'source', 'status', 'decision_comment', 'lock_version',
                ...array_diff(CaptainReport::DATA_FIELDS, ['reported_at', 'port_call_id', 'report_type'])]),
            'reported_at' => $r->reported_at->toIso8601String(),
            'is_editable' => $r->isEditable(),
            'vessel' => $this->whenLoaded('vessel', fn () => $r->vessel->only(['id', 'code', 'name'])),
            'voyage' => $this->whenLoaded('voyage', fn () => $r->voyage?->only(['id', 'voyage_number', 'status'])),
            'port_call' => $this->whenLoaded('portCall', fn () => $r->portCall ? ['id' => $r->portCall->id, 'label' => $r->portCall->label()] : null),
            'fuel_lines' => $this->whenLoaded('fuelLines', fn () => $r->fuelLines->map(fn ($l) => [
                'fuel_type_id' => $l->fuel_type_id, 'fuel_code' => $l->fuelType->getAttribute('code'),
                'rob_mt' => $l->rob_mt, 'consumed_mt' => $l->consumed_mt, 'received_mt' => $l->received_mt,
            ])),
            'submitted_by' => $this->whenLoaded('submitter', fn () => $r->submitter?->only(['id', 'name'])),
            'submitted_at' => $r->getAttribute('submitted_at')?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $r->verifier?->only(['id', 'name'])),
            'verified_at' => $r->getAttribute('verified_at')?->toIso8601String(),
            'created_at' => $r->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
