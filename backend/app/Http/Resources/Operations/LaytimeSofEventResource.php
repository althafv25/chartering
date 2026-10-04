<?php

namespace App\Http\Resources\Operations;

use App\Models\LaytimeSofEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaytimeSofEvent
 */
class LaytimeSofEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $e = $this->resource;

        return [
            ...$e->only(['id', 'laytime_calculation_id', 'event_code', 'description', 'source']),
            'event_at' => $e->event_at?->toIso8601String(),
        ];
    }
}
