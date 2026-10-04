<?php

namespace App\Http\Resources\Operations;

use App\Models\PortDa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PortDa
 */
class PortDaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            ...$d->only(['id', 'da_number', 'port_call_id', 'voyage_id', 'port_id', 'agent_company_id', 'da_type', 'proforma_da_id', 'currency', 'fx_rate',
                'fx_method', 'total_amount', 'base_amount', 'status', 'remarks', 'lock_version']),
            'base_currency' => config('offshore.base_currency'),
            'is_editable' => $d->isEditable(),
            'submitted_at' => $d->submitted_at?->toIso8601String(),
            'submitted_by' => $d->submitted_by,
            'approved_at' => $d->approved_at?->toIso8601String(),
            'approved_by' => $d->approved_by,
            'created_at' => $d->getAttribute('created_at')?->toIso8601String(),
            'port' => $d->port?->only(['id', 'name', 'unlocode']),
            'port_call' => $d->portCall ? ['id' => $d->portCall->id, 'label' => $d->portCall->label()] : null,
            'voyage' => $d->voyage?->only(['id', 'voyage_number', 'status']),
            'agent' => $d->agent?->only(['id', 'legal_name']),
            'proforma' => $d->proforma?->only(['id', 'da_number', 'status']),
            'items' => PortDaItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
