<?php

namespace App\Http\Resources\Masters;

use App\Models\VesselStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VesselStatusHistory */
class VesselStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vessel_id' => $this->vessel_id,
            'track' => $this->track,
            'status' => $this->status,
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'port_id' => $this->port_id,
            'offshore_location_id' => $this->offshore_location_id,
            'location' => $this->locationLabel(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'reason' => $this->reason,
            'remarks' => $this->remarks,
            'changed_by' => $this->whenLoaded('changer', fn () => $this->changer ? ['id' => $this->changer->id, 'name' => $this->changer->name] : null),
        ];
    }
}
