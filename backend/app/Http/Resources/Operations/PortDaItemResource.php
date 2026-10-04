<?php

namespace App\Http\Resources\Operations;

use App\Models\PortDaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PortDaItem
 */
class PortDaItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            ...$i->only(['id', 'port_da_id', 'da_cost_category_id', 'description', 'estimated_amount', 'actual_amount', 'variance_amount', 'remarks', 'sequence']),
            'category' => $i->category?->only(['id', 'code', 'name']),
        ];
    }
}
