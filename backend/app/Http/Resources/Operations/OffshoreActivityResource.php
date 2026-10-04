<?php

namespace App\Http\Resources\Operations;

use App\Models\OffshoreActivity;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commercial figures (rates, revenue) are only included for users with contracts.rates.view.
 *
 * @mixin OffshoreActivity
 */
class OffshoreActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $tz = (string) ($a->location?->getAttribute('timezone') ?? $request->user()?->getAttribute('timezone') ?? 'UTC');
        $ratesVisible = (bool) $request->user()?->can('contracts.rates.view');

        return [
            ...$a->only(['id', 'activity_number', 'vessel_id', 'voyage_id', 'contract_id', 'offshore_project_id', 'client_company_id', 'offshore_location_id',
                'offshore_activity_type_id', 'description', 'billable_hours', 'non_billable_hours', 'standby_hours', 'fuel_used', 'status', 'decision_comment',
                'remarks', 'lock_version', 'contract_version_no', 'calculation_basis', 'warnings']),
            'start_at' => $a->start_at->toIso8601String(),
            'end_at' => $a->end_at->toIso8601String(),
            'start_at_local' => LocalTime::toLocal($a->start_at, $tz),
            'end_at_local' => LocalTime::toLocal($a->end_at, $tz),
            'timezone' => $tz,
            'duration_hours' => LocalTime::hoursBetween($a->start_at, $a->end_at),
            'is_editable' => $a->isEditable(),
            'rates_visible' => $ratesVisible,
            'currency' => $ratesVisible ? $a->currency : null,
            'revenue_amount' => $ratesVisible ? $a->revenue_amount : null,
            'rate_snapshot' => $ratesVisible ? ($a->rate_snapshot ?? []) : null,
            'vessel' => $a->vessel?->only(['id', 'code', 'name']),
            'voyage' => $a->voyage?->only(['id', 'voyage_number', 'status']),
            'contract' => $a->contract?->only(['id', 'contract_number', 'status']),
            'project' => $a->project?->only(['id', 'code', 'name']),
            'client' => $a->client?->only(['id', 'legal_name']),
            'location' => $a->location?->only(['id', 'code', 'name', 'timezone']),
            'type' => $a->type?->only(['id', 'code', 'name', 'is_billable_default']),
            'submitted_by' => $a->submitter?->only(['id', 'name']),
            'submitted_at' => $a->getAttribute('submitted_at')?->toIso8601String(),
            'verified_by' => $a->verifier?->only(['id', 'name']),
            'verified_at' => $a->getAttribute('verified_at')?->toIso8601String(),
            'created_at' => $a->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
