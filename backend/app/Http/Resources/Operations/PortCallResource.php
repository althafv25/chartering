<?php

namespace App\Http\Resources\Operations;

use App\Models\PortCall;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Times are returned in UTC (ISO) and as port-local values (`*_local`) for display/edit.
 *
 * @mixin PortCall
 */
class PortCallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $tz = $c->timezone();
        $times = [];
        foreach (PortCall::TIMES as $f) {
            $times[$f] = $c->getAttribute($f)?->toIso8601String();
            $times["{$f}_local"] = LocalTime::toLocal($c->getAttribute($f), $tz);
        }

        return [
            ...$c->only(['id', 'voyage_id', 'sequence', 'port_id', 'offshore_location_id', 'agent_company_id', 'purpose', 'berth', 'status', 'remarks', 'lock_version']),
            ...$times,
            'timezone' => $tz,
            'label' => $c->label(),
            'port' => $c->port?->only(['id', 'name', 'unlocode', 'timezone']),
            'location' => $c->location?->only(['id', 'name', 'timezone']),
            'agent' => $c->agent?->only(['id', 'legal_name']),
        ];
    }
}
