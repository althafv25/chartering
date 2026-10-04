<?php

namespace App\Http\Resources\Operations;

use App\Models\LaytimeException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaytimeException
 */
class LaytimeExceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $e = $this->resource;

        return [
            ...$e->only(['id', 'laytime_calculation_id', 'exception_type', 'pct_counted', 'remarks']),
            'from_at' => $e->from_at?->toIso8601String(),
            'to_at' => $e->to_at?->toIso8601String(),
        ];
    }
}
