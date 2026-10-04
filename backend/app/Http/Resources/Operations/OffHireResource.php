<?php

namespace App\Http\Resources\Operations;

use App\Models\OffHireEvent;
use App\Support\Decimal;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OffHireEvent
 */
class OffHireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $tz = (string) ($request->user()?->getAttribute('timezone') ?: 'UTC');

        return [
            ...$e->only(['id', 'voyage_id', 'reason_code', 'description', 'hours', 'fuel_consumed', 'status', 'decision_comment', 'lock_version']),
            'days' => $e->hours !== null ? Decimal::round(Decimal::div($e->hours, '24'), 4) : null,
            'from_at' => $e->from_at->toIso8601String(),
            'to_at' => $e->to_at?->toIso8601String(),
            'from_at_local' => LocalTime::toLocal($e->from_at, $tz),
            'to_at_local' => LocalTime::toLocal($e->to_at, $tz),
            'timezone' => $tz,
            'decided_by' => $e->decider?->only(['id', 'name']),
            'decided_at' => $e->getAttribute('decided_at')?->toIso8601String(),
        ];
    }
}
