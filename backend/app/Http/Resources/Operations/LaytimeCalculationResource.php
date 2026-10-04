<?php

namespace App\Http\Resources\Operations;

use App\Models\LaytimeCalculation;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaytimeCalculation
 */
class LaytimeCalculationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $tz = $c->portCall?->timezone() ?? 'UTC';

        return [
            ...$c->only(['id', 'port_call_id', 'voyage_id', 'contract_id', 'calculation_type', 'fixed_hours', 'cargo_quantity', 'rate_per_day', 'rate_unit',
                'terms_code', 'terms_definition', 'notice_time_hours', 'demurrage_rate_per_day', 'despatch_rate_per_day', 'currency', 'once_on_demurrage_rule',
                'allowed_hours', 'used_hours', 'difference_hours', 'demurrage_amount', 'despatch_amount', 'calculation_version', 'trace', 'status', 'remarks',
                'lock_version']),
            'timezone' => $tz,
            'nor_tendered_at' => $c->nor_tendered_at?->toIso8601String(),
            'nor_tendered_at_local' => LocalTime::toLocal($c->nor_tendered_at, $tz),
            'nor_accepted_at' => $c->nor_accepted_at?->toIso8601String(),
            'nor_accepted_at_local' => LocalTime::toLocal($c->nor_accepted_at, $tz),
            'laytime_commenced_at' => $c->laytime_commenced_at?->toIso8601String(),
            'laytime_commenced_at_local' => LocalTime::toLocal($c->laytime_commenced_at, $tz),
            'laytime_completed_at' => $c->laytime_completed_at?->toIso8601String(),
            'laytime_completed_at_local' => LocalTime::toLocal($c->laytime_completed_at, $tz),
            'calculated_at' => $c->calculated_at?->toIso8601String(),
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'submitted_by' => $c->submitted_by,
            'agreed_at' => $c->agreed_at?->toIso8601String(),
            'agreed_by' => $c->agreed_by,
            'created_at' => $c->getAttribute('created_at')?->toIso8601String(),
            'is_editable' => $c->isEditable(),
            'port_call' => $c->portCall ? ['id' => $c->portCall->id, 'label' => $c->portCall->label()] : null,
            'voyage' => $c->voyage?->only(['id', 'voyage_number', 'status']),
            'contract' => $c->contract?->only(['id', 'contract_number', 'status']),
            'sof_events' => $this->whenLoaded('sofEvents', fn () => $c->sofEvents->map(fn ($e) => [...(new LaytimeSofEventResource($e))->resolve(), 'event_at_local' => LocalTime::toLocal($e->event_at, $tz)])->all()),
            'exceptions' => $this->whenLoaded('exceptions', fn () => $c->exceptions->map(fn ($e) => [...(new LaytimeExceptionResource($e))->resolve(), 'from_at_local' => LocalTime::toLocal($e->from_at, $tz), 'to_at_local' => LocalTime::toLocal($e->to_at, $tz)])->all()),
        ];
    }
}
