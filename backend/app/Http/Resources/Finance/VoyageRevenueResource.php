<?php

namespace App\Http\Resources\Finance;

use App\Models\VoyageRevenue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VoyageRevenue
 */
class VoyageRevenueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            ...$r->only([
                'id', 'voyage_id', 'contract_id', 'offshore_activity_id', 'laytime_calculation_id', 'revenue_category_id',
                'description', 'is_estimate', 'quantity', 'rate', 'currency', 'fx_rate', 'amount', 'base_amount',
                'commission_pct_total', 'commission_amount', 'remarks',
            ]),
            'status' => $r->status->value,
            'base_currency' => config('offshore.base_currency'),
            'net_amount' => $r->amount !== null ? bcsub((string) $r->amount, (string) $r->commission_amount, 2) : null,
            'is_editable' => $r->isEditable(),
            'service_period_from' => $r->service_period_from?->toDateString(),
            'service_period_to' => $r->service_period_to?->toDateString(),
            'created_by' => $r->created_by,
            'updated_by' => $r->updated_by,
            'created_at' => $r->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $r->getAttribute('updated_at')?->toIso8601String(),
            'voyage' => $r->voyage?->only(['id', 'voyage_number', 'status']),
            'contract' => $r->contract?->only(['id', 'contract_number', 'status']),
            'offshore_activity' => $r->offshoreActivity?->only(['id', 'activity_number', 'status']),
            'laytime_calculation' => $r->laytimeCalculation?->only(['id', 'status']),
            'category' => $r->category?->only(['id', 'code', 'name']),
            'invoice_line' => $this->whenLoaded('invoiceLine', fn () => $r->invoiceLine ? [
                'id' => $r->invoiceLine->id,
                'invoice_id' => $r->invoiceLine->invoice_id,
            ] : null),
        ];
    }
}
