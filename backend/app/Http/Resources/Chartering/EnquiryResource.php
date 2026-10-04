<?php

namespace App\Http\Resources\Chartering;

use App\Http\Resources\Masters\CompanyRefResource;
use App\Models\Enquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enquiry
 */
class EnquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $ref = fn (string $rel) => $this->whenLoaded($rel, fn () => $e->{$rel} ? (new CompanyRefResource($e->{$rel}))->resolve($request) : null);

        return [
            'id' => $e->id,
            'enquiry_number' => $e->enquiry_number,
            'received_at' => $e->received_at->toIso8601String(),
            'source' => $e->getAttribute('source'),
            'business_type' => $e->business_type,
            'charterer_company_id' => $e->getAttribute('charterer_company_id'),
            'broker_company_id' => $e->getAttribute('broker_company_id'),
            'charterer' => $ref('charterer'),
            'broker' => $ref('broker'),
            'cargo_type_id' => $e->getAttribute('cargo_type_id'),
            'cargo_type' => $this->whenLoaded('cargoType', fn () => $e->cargoType?->only(['id', 'code', 'name'])),
            'cargo_description' => $e->getAttribute('cargo_description'),
            'quantity' => $e->quantity,
            'quantity_unit' => $e->getAttribute('quantity_unit'),
            'quantity_tolerance_pct' => $e->getAttribute('quantity_tolerance_pct'),
            'offshore_location_id' => $e->getAttribute('offshore_location_id'),
            'offshore_location' => $this->whenLoaded('offshoreLocation', fn () => $e->offshoreLocation?->only(['id', 'code', 'name'])),
            'laycan_from' => $e->laycan_from?->toDateString(),
            'laycan_to' => $e->laycan_to?->toDateString(),
            'period_days' => $e->getAttribute('period_days'),
            'rate_idea' => $e->rate_idea,
            'rate_basis' => $e->getAttribute('rate_basis'),
            'currency' => $e->currency,
            'commission_terms' => $e->getAttribute('commission_terms'),
            'terms' => $e->getAttribute('terms'),
            'remarks' => $e->getAttribute('remarks'),
            'status' => $e->status,
            'lost_reason' => $e->getAttribute('lost_reason'),
            'assigned_to' => $e->getAttribute('assigned_to'),
            'assignee' => $this->whenLoaded('assignee', fn () => $e->assignee ? ['id' => $e->assignee->id, 'name' => $e->assignee->name] : null),
            'is_closed' => $e->isClosed(),
            'lock_version' => $e->lock_version,
            'ports' => $this->whenLoaded('ports', fn () => $e->ports->map(fn ($p) => [
                'id' => $p->id, 'sequence' => $p->sequence, 'purpose' => $p->purpose, 'notes' => $p->getAttribute('notes'),
                'port_id' => $p->port_id, 'offshore_location_id' => $p->offshore_location_id, 'point' => $p->point(),
            ])),
            'vessels' => $this->whenLoaded('vessels', fn () => $e->vessels->map(fn ($v) => [
                'vessel_id' => $v->getAttribute('vessel_id'), 'shortlist_status' => $v->shortlist_status, 'notes' => $v->getAttribute('notes'),
                'vessel' => $v->vessel?->only(['id', 'code', 'name', 'imo_number', 'commercial_status', 'operational_status']),
            ])),
            'estimations_count' => $this->whenCounted('estimations'),
            'offers_count' => $this->whenCounted('offers'),
            'created_at' => $e->created_at?->toIso8601String(),
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }
}
