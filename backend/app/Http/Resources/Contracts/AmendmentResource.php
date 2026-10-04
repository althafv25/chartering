<?php

namespace App\Http\Resources\Contracts;

use App\Models\ContractAmendment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContractAmendment
 */
class AmendmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $canRates = (bool) $request->user()?->can('contracts.rates.view');
        $proposal = $a->proposal;
        $changes = $a->changes;
        if (! $canRates) {
            unset($proposal['rates']);
            if (is_array($changes)) {
                $changes['rates'] = isset($changes['rates']) ? 'hidden' : null;
            }
        }

        return [
            'id' => $a->id,
            'contract_id' => $a->contract_id,
            'amendment_no' => $a->amendment_no,
            'effective_date' => $a->effective_date->toDateString(),
            'summary' => $a->summary,
            'status' => $a->status,
            'proposal' => $proposal,
            'changes' => $changes,
            'resulting_version' => $a->resulting_version,
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'decided_at' => $a->decided_at?->toIso8601String(),
            'decision_comment' => $a->getAttribute('decision_comment'),
            'created_by' => $a->relationLoaded('creator') ? $a->creator?->name : null,
            'decided_by' => $a->relationLoaded('decider') ? $a->decider?->name : null,
            'lock_version' => $a->lock_version,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
