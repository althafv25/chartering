<?php

namespace App\Http\Resources\Finance;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            ...$i->only([
                'id', 'invoice_number', 'customer_company_id', 'billing_snapshot', 'contract_id', 'voyage_id',
                'currency', 'fx_rate', 'subtotal', 'tax_amount', 'total', 'base_total', 'amount_paid', 'balance',
                'cancelled_reason', 'credit_note_for_id', 'pdf_document_id', 'remarks', 'lock_version',
            ]),
            'invoice_type' => $i->invoice_type->value,
            'status' => $i->status->value,
            'base_currency' => config('offshore.base_currency'),
            'is_editable' => $i->isEditable(),
            'is_credit_note' => $i->invoice_type->value === 'credit_note',
            'line_count' => $i->relationLoaded('lines') ? $i->lines->count() : null,
            'issue_date' => $i->issue_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'is_overdue' => (bool) ($i->due_date && $i->due_date->isPast() && in_array($i->status->value, ['issued', 'partially_paid', 'overdue'], true)),
            'submitted_by' => $i->submitted_by,
            'submitted_at' => $i->submitted_at?->toIso8601String(),
            'approved_by' => $i->approved_by,
            'approved_at' => $i->approved_at?->toIso8601String(),
            'issued_by' => $i->issued_by,
            'issued_at' => $i->issued_at?->toIso8601String(),
            'created_by' => $i->created_by,
            'updated_by' => $i->updated_by,
            'created_at' => $i->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $i->getAttribute('updated_at')?->toIso8601String(),
            'customer' => $i->customer?->only(['id', 'legal_name', 'country']),
            'contract' => $i->contract?->only(['id', 'contract_number', 'status']),
            'voyage' => $i->voyage?->only(['id', 'voyage_number', 'status']),
            'credit_note_for' => $i->creditNoteFor?->only(['id', 'invoice_number', 'status']),
            'credit_notes' => $this->whenLoaded('creditNotes', fn () => $i->creditNotes->map->only(['id', 'invoice_number', 'status', 'total'])),
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
        ];
    }
}
