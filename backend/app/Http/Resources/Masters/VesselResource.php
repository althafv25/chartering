<?php

namespace App\Http\Resources\Masters;

use App\Models\Vessel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Decimal values are returned as strings (never floats).
 *
 * @mixin Vessel
 */
class VesselResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn (string $rel) => $this->whenLoaded($rel, fn () => $this->{$rel} ? (new CompanyRefResource($this->{$rel}))->resolve($request) : null);

        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'imo_number' => $this->imo_number,
            'mmsi' => $this->mmsi,
            'call_sign' => $this->call_sign,
            'official_number' => $this->official_number,
            'vessel_type_id' => $this->vessel_type_id,
            'vessel_type' => $this->whenLoaded('vesselType', fn () => [
                'id' => $this->vesselType->id, 'code' => $this->vesselType->code, 'name' => $this->vesselType->name,
                'attribute_schema' => $this->vesselType->attribute_schema ?? [],
            ]),
            'subtype' => $this->subtype,
            'flag_country' => $this->flag_country,
            'port_of_registry' => $this->port_of_registry,
            'year_built' => $this->year_built,
            'builder' => $this->builder,
            'class_society' => $this->class_society,
            'class_notation' => $this->class_notation,
            'ownership_type' => $this->ownership_type,
            'owner_company_id' => $this->resource->getAttribute('owner_company_id'),
            'manager_company_id' => $this->resource->getAttribute('manager_company_id'),
            'commercial_manager_company_id' => $this->resource->getAttribute('commercial_manager_company_id'),
            'technical_manager_company_id' => $this->resource->getAttribute('technical_manager_company_id'),
            'owner' => $ref('owner'),
            'manager' => $ref('manager'),
            'commercial_manager' => $ref('commercialManager'),
            'technical_manager' => $ref('technicalManager'),
            'main_engine' => $this->main_engine,
            'aux_engines' => $this->aux_engines,
            'propulsion' => $this->propulsion,
            'dp_class' => $this->dp_class,
            'crew_capacity' => $this->crew_capacity,
            'passenger_capacity' => $this->passenger_capacity,
            'custom_attributes' => (object) ($this->custom_attributes ?? []),
            'commercial_status' => $this->commercial_status,
            'operational_status' => $this->operational_status,
            'crew_management_vessel_id' => $this->crew_management_vessel_id,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'lock_version' => $this->lock_version,
            'former_names' => $this->whenLoaded('nameHistory', fn () => $this->nameHistory->map(fn ($h) => ['name' => $h->name, 'valid_to' => $h->valid_to->toDateString()])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        foreach (array_keys(Vessel::DECIMALS) as $col) {
            $data[$col] = $this->resource->getAttribute($col);
        }

        return $data;
    }
}
