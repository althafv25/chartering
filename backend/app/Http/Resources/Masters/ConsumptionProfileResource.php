<?php

namespace App\Http\Resources\Masters;

use App\Models\VesselConsumptionProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VesselConsumptionProfile */
class ConsumptionProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vessel_id' => $this->vessel_id,
            'name' => $this->name,
            'source' => $this->source,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_default' => $this->is_default,
            'remarks' => $this->remarks,
            'rates' => $this->whenLoaded('rates', fn () => $this->rates->map(fn ($r) => [
                'id' => $r->id,
                'mode' => $r->mode,
                'speed_kn' => $r->speed_kn,
                'fuel_type_id' => $r->fuel_type_id,
                'fuel_type' => $r->relationLoaded('fuelType') ? ['code' => $r->fuelType->code, 'name' => $r->fuelType->name] : null,
                'consumption_mt_per_day' => $r->consumption_mt_per_day,
            ])),
        ];
    }
}
