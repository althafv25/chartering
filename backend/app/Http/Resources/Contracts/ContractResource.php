<?php

namespace App\Http\Resources\Contracts;

use App\Http\Resources\Masters\CompanyRefResource;
use App\Models\Contract;
use App\Models\ContractRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commercial rates are only included with `contracts.rates.view`.
 *
 * @mixin Contract
 */
class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $canRates = (bool) $request->user()?->can('contracts.rates.view');
        $user = fn (string $rel) => $this->whenLoaded($rel, fn () => $c->{$rel} ? ['id' => $c->{$rel}->id, 'name' => $c->{$rel}->name] : null);

        return [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'contract_type' => $c->contract_type,
            'title' => $c->getAttribute('title'),
            'status' => $c->status,
            'is_editable' => $c->isEditable(),
            'is_amendable' => $c->isAmendable(),
            'current_version' => $c->current_version,
            'fixture_id' => $c->fixture_id,
            'fixture' => $this->whenLoaded('fixture', fn () => $c->fixture ? ['id' => $c->fixture->id, 'fixture_number' => $c->fixture->fixture_number] : null),
            'customer_company_id' => $c->customer_company_id,
            'customer' => $this->whenLoaded('customer', fn () => (new CompanyRefResource($c->customer))->resolve($request)),
            'vessel_id' => $c->vessel_id,
            'vessel' => $this->whenLoaded('vessel', fn () => $c->vessel?->only(['id', 'code', 'name'])),
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'extension_options' => $c->getAttribute('extension_options'),
            'currency' => $c->currency,
            'payment_terms_days' => $c->getAttribute('payment_terms_days'),
            'payment_terms_text' => $c->getAttribute('payment_terms_text'),
            'commissions' => $c->commissions,
            'terms' => $c->getAttribute('terms'),
            'remarks' => $c->getAttribute('remarks'),
            'submitted_by' => $user('submitter'),
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'decided_by' => $user('decider'),
            'decided_at' => $c->decided_at?->toIso8601String(),
            'decision_comment' => $c->getAttribute('decision_comment'),
            'activated_at' => $c->activated_at?->toIso8601String(),
            'closed_at' => $c->closed_at?->toIso8601String(),
            'close_reason' => $c->getAttribute('close_reason'),
            'lock_version' => $c->lock_version,
            'rates_visible' => $canRates,
            'rates' => $this->when($canRates && $c->relationLoaded('rates'), fn () => $c->rates->map(fn (ContractRate $r) => [
                'id' => $r->id, 'version_no' => $r->version_no, ...$r->canonical(),
                'activity_type' => $r->relationLoaded('activityType') ? $r->activityType?->only(['id', 'code', 'name']) : null,
            ])),
            'clauses' => $this->whenLoaded('clauses', fn () => $c->clauses->map(fn ($x) => ['id' => $x->id, 'version_no' => $x->version_no, 'sequence' => $x->sequence, ...$x->canonical()])),
            'versions' => $this->whenLoaded('versions', fn () => $c->versions->map(fn ($v) => [
                'version_no' => $v->version_no, 'effective_from' => $v->effective_from->toDateString(), 'amendment_id' => $v->amendment_id,
                'header' => $v->header_snapshot, 'created_at' => $v->created_at?->toIso8601String(),
            ])),
            'amendments' => $this->whenLoaded('amendments', fn () => $c->amendments->map(fn ($a) => (new AmendmentResource($a))->resolve($request))),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
