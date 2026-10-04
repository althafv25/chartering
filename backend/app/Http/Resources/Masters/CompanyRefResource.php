<?php

namespace App\Http\Resources\Masters;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact company reference used inside other resources and pickers.
 *
 * @mixin Company
 */
class CompanyRefResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'legal_name' => $this->legal_name,
            'country' => $this->country,
            'roles' => $this->relationLoaded('roles') ? $this->roleNames() : null,
        ];
    }
}
