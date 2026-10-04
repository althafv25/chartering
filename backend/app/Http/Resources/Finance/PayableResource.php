<?php

namespace App\Http\Resources\Finance;

use App\Models\Payable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payable
 */
class PayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            ...$p->only([
                'id', 'payable_number', 'supplier_company_id', 'supplier_invoice_ref', 'voyage_id',
                'currency', 'fx_rate', 'subtotal', 'tax', 'total', 'base_total', 'amount_paid', 'balance',
                'remarks', 'lock_version',
            ]),
            'status' => $p->status->value,
            'base_currency' => config('offshore.base_currency'),
            'is_editable' => $p->isEditable(),
            'issue_date' => $p->issue_date?->toDateString(),
            'due_date' => $p->due_date?->toDateString(),
            'is_overdue' => (bool) ($p->due_date && $p->due_date->isPast() && in_array($p->status->value, ['approved', 'partially_paid'], true)),
            'approved_by' => $p->approved_by,
            'approved_at' => $p->approved_at?->toIso8601String(),
            'created_by' => $p->created_by,
            'updated_by' => $p->updated_by,
            'created_at' => $p->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $p->getAttribute('updated_at')?->toIso8601String(),
            'supplier' => $p->supplier?->only(['id', 'legal_name', 'country']),
            'voyage' => $p->voyage?->only(['id', 'voyage_number', 'status']),
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'voyage_expenses' => $this->whenLoaded('voyageExpenses', fn () => $p->voyageExpenses->map->only(['id', 'status', 'amount', 'base_amount'])),
        ];
    }
}
