<?php

namespace App\Http\Resources\Operations;

use App\Models\CiiFormulaSet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CiiFormulaSet
 */
class CiiFormulaSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fs = $this->resource;

        return [
            ...$fs->only(['id', 'code', 'name', 'status', 'remarks']),
            'parameters' => $fs->parameters,
            'effective_date' => $fs->effective_date->toDateString(),
            'expiration_date' => $fs->expiration_date?->toDateString(),
            'created_at' => $fs->created_at?->toIso8601String(),
            'updated_at' => $fs->updated_at?->toIso8601String(),
        ];
    }
}
