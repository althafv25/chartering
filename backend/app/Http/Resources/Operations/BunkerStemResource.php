<?php

namespace App\Http\Resources\Operations;

use App\Models\BunkerStem;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BunkerStem
 */
class BunkerStemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $tz = $s->portCall?->timezone() ?? (string) ($s->port?->getAttribute('timezone') ?? $request->user()?->getAttribute('timezone') ?? 'UTC');

        return [
            ...$s->only(['id', 'stem_number', 'vessel_id', 'voyage_id', 'port_call_id', 'port_id', 'supplier_company_id', 'fuel_type_id', 'ordered_mt', 'delivered_mt',
                'price_per_mt', 'currency', 'fx_rate', 'fx_method', 'total_amount', 'base_amount', 'bdn_number', 'invoice_reference', 'status', 'remarks', 'lock_version']),
            'base_currency' => config('offshore.base_currency'),
            'ordered_on' => $s->ordered_on->toDateString(),
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'delivered_at_local' => LocalTime::toLocal($s->delivered_at, $tz),
            'timezone' => $tz,
            'vessel' => $s->vessel?->only(['id', 'code', 'name']),
            'voyage' => $s->voyage?->only(['id', 'voyage_number', 'status']),
            'port' => $s->port?->only(['id', 'name', 'unlocode']),
            'port_call' => $s->portCall ? ['id' => $s->portCall->id, 'label' => $s->portCall->label()] : null,
            'supplier' => $s->supplier?->only(['id', 'legal_name']),
            'fuel_type' => $s->fuelType?->only(['id', 'code', 'name']),
        ];
    }
}
