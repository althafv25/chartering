<?php

namespace App\Http\Resources\Masters;

use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExchangeRate */
class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rate_date' => $this->rate_date->toDateString(),
            'base_currency' => $this->base_currency,
            'quote_currency' => $this->quote_currency,
            'rate' => $this->rate,
            'source' => $this->source,
            'remarks' => $this->remarks,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
