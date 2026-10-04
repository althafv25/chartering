<?php

namespace App\Http\Resources\Finance;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            ...$p->only([
                'id', 'payment_number', 'company_id', 'amount', 'currency', 'fx_rate', 'base_amount',
                'unallocated_amount', 'bank_account_ref', 'bank_reference', 'method', 'remarks',
            ]),
            'direction' => $p->direction->value,
            'status' => $p->status->value,
            'base_currency' => config('offshore.base_currency'),
            'is_reversed' => $p->isReversed(),
            'allocated_amount' => bcsub((string) $p->amount, (string) $p->unallocated_amount, 2),
            'payment_date' => $p->payment_date?->toDateString(),
            'created_by' => $p->created_by,
            'updated_by' => $p->updated_by,
            'created_at' => $p->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $p->getAttribute('updated_at')?->toIso8601String(),
            'company' => $p->company?->only(['id', 'legal_name', 'country']),
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
        ];
    }
}
