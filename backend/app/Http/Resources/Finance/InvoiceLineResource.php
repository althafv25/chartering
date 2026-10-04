<?php

namespace App\Http\Resources\Finance;

use App\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceLine
 */
class InvoiceLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $l = $this->resource;

        return [
            ...$l->only([
                'id', 'invoice_id', 'sequence', 'voyage_revenue_id', 'description', 'quantity', 'unit', 'rate',
                'amount', 'tax_code_id', 'tax_rate_pct', 'tax_amount', 'line_total',
            ]),
            'tax_code' => $l->taxCode?->only(['id', 'code', 'name', 'rate_pct']),
            'voyage_revenue' => $this->whenLoaded('voyageRevenue', fn () => $l->voyageRevenue?->only(['id', 'description', 'status'])),
        ];
    }
}
