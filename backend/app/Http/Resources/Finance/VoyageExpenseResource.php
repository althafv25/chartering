<?php

namespace App\Http\Resources\Finance;

use App\Models\VoyageExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VoyageExpense
 */
class VoyageExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $e = $this->resource;

        return [
            ...$e->only([
                'id', 'voyage_id', 'contract_id', 'expense_category_id', 'source_type', 'source_id', 'description',
                'is_estimate', 'quantity', 'rate', 'currency', 'fx_rate', 'amount', 'base_amount', 'supplier_company_id', 'remarks',
            ]),
            'status' => $e->status->value,
            'base_currency' => config('offshore.base_currency'),
            'is_editable' => $e->isEditable(),
            'incurred_at' => $e->incurred_at?->toIso8601String(),
            'created_by' => $e->created_by,
            'updated_by' => $e->updated_by,
            'created_at' => $e->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $e->getAttribute('updated_at')?->toIso8601String(),
            'voyage' => $e->voyage?->only(['id', 'voyage_number', 'status']),
            'contract' => $e->contract?->only(['id', 'contract_number', 'status']),
            'category' => $e->category?->only(['id', 'code', 'name']),
            'supplier' => $e->supplier?->only(['id', 'legal_name']),
            'source' => $this->whenLoaded('source', fn () => $e->source ? [
                'type' => $e->source_type,
                'id' => $e->source_id,
            ] : null),
        ];
    }
}
