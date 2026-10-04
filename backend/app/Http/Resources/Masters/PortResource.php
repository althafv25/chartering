<?php

namespace App\Http\Resources\Masters;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Port */
class PortResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label(),
            'unlocode' => $this->unlocode,
            'country' => $this->country,
            'region' => $this->region,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'max_draft_m' => $this->max_draft_m,
            'max_loa_m' => $this->max_loa_m,
            'max_beam_m' => $this->max_beam_m,
            'restrictions' => $this->restrictions,
            'notes' => $this->notes,
            'status' => $this->status,
            'agents_count' => $this->whenCounted('agents'),
            'agents' => $this->whenLoaded('agents', fn () => $this->agents->map(fn ($c) => [
                'company' => (new CompanyRefResource($c))->resolve($request),
                'is_default' => (bool) $c->getRelation('pivot')->getAttribute('is_default'),
                'remarks' => $c->getRelation('pivot')->getAttribute('remarks'),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
