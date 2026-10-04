<?php

namespace App\Http\Resources\Chartering;

use App\Models\Fixture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Fixture
 */
class FixtureResource extends JsonResource
{
    public bool $withSnapshot = false;

    public function toArray(Request $request): array
    {
        $f = $this->resource;

        return [
            ...collect($f->only(['id', 'fixture_number', 'offer_revision_id', 'estimation_scenario_id', 'enquiry_id', 'vessel_id', 'charterer_company_id',
                'owner_company_id', 'broker_company_id', 'business_type', 'cargo_description', 'quantity', 'quantity_unit', 'rate', 'rate_basis', 'currency',
                'ports', 'commissions', 'terms', 'status']))->all(),
            'fixture_date' => $f->fixture_date->toDateString(),
            'laycan_from' => $f->laycan_from?->toDateString(),
            'laycan_to' => $f->laycan_to?->toDateString(),
            'vessel' => $this->whenLoaded('vessel', fn () => $f->vessel->only(['id', 'code', 'name'])),
            'charterer' => $this->whenLoaded('charterer', fn () => $f->charterer?->only(['id', 'code', 'legal_name'])),
            'enquiry' => $this->whenLoaded('enquiry', fn () => $f->enquiry->only(['id', 'enquiry_number', 'status'])),
            'recap_snapshot' => $this->when($this->withSnapshot, $f->recap_snapshot),
            'remarks' => $f->getAttribute('remarks'),
            'lock_version' => $f->lock_version,
            'is_editable' => $f->status === 'draft',
            'submitted_at' => $f->submitted_at?->toIso8601String(),
            'decided_at' => $f->decided_at?->toIso8601String(),
            'decision_comment' => $f->getAttribute('decision_comment'),
            'submitted_by' => $this->whenLoaded('submitter', fn () => $f->submitter ? ['id' => $f->submitter->id, 'name' => $f->submitter->name] : null),
            'decided_by' => $this->whenLoaded('decider', fn () => $f->decider ? ['id' => $f->decider->id, 'name' => $f->decider->name] : null),
            'contract' => $this->whenLoaded('contract', fn () => $f->contract ? $f->contract->only(['id', 'contract_number', 'status']) : null),
            'voyage' => $this->whenLoaded('voyage', fn () => $f->voyage ? $f->voyage->only(['id', 'voyage_number', 'status']) : null),
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
