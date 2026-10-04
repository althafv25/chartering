<?php

namespace App\Http\Resources\Masters;

use App\Models\OffshoreLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OffshoreLocation */
class OffshoreLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'field_name' => $this->field_name,
            'block' => $this->block,
            'operator' => $this->whenLoaded('operator', fn () => $this->operator ? (new CompanyRefResource($this->operator))->resolve($request) : null),
            'operator_company_id' => $this->operator_company_id,
            'nearest_port' => $this->whenLoaded('nearestPort', fn () => $this->nearestPort ? ['id' => $this->nearestPort->id, 'label' => $this->nearestPort->label()] : null),
            'nearest_port_id' => $this->nearest_port_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'water_depth_m' => $this->water_depth_m,
            'timezone' => $this->timezone,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
