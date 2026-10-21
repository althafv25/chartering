<?php

namespace App\Http\Resources\Operations;

use App\Models\CiiVesselYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CiiVesselYear
 */
class CiiVesselYearResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $y = $this->resource;

        return [
            ...$y->only(['id', 'vessel_id', 'reporting_year', 'formula_set_id', 'capacity_metric', 'capacity_value', 'fuel_consumption',
                'distance_sailed_nm', 'attained_cii', 'required_cii', 'rating', 'source', 'scope_status', 'status', 'calculation_version', 'remarks', 'lock_version']),
            'calculation_trace' => $y->calculation_trace,
            'verified_at' => $y->verified_at?->toIso8601String(),
            'vessel' => $y->vessel?->only(['id', 'code', 'name', 'imo_number', 'dwt_mt', 'gt']),
            'formula_set' => $y->formulaSet?->only(['id', 'code', 'name', 'status']),
            'verified_by' => $y->verifiedBy?->only(['id', 'name']),
            'created_at' => $y->created_at?->toIso8601String(),
            'updated_at' => $y->updated_at?->toIso8601String(),
        ];
    }
}
