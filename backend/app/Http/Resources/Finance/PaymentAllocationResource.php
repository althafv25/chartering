<?php

namespace App\Http\Resources\Finance;

use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentAllocation
 */
class PaymentAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            ...$a->only(['id', 'payment_id', 'invoice_id', 'payable_id', 'allocated_amount', 'invoice_ccy_amount', 'fx_difference_base']),
            'created_by' => $a->created_by,
            'created_at' => $a->getAttribute('created_at')?->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', fn () => $a->invoice?->only(['id', 'invoice_number', 'status', 'currency', 'total'])),
            'payable' => $this->whenLoaded('payable', fn () => $a->payable?->only(['id', 'payable_number', 'status', 'currency', 'total'])),
            'payment' => $this->whenLoaded('payment', fn () => $a->payment?->only(['id', 'payment_number', 'direction', 'currency'])),
        ];
    }
}
