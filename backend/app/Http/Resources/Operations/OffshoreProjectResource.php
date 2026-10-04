<?php

namespace App\Http\Resources\Operations;

use App\Models\OffshoreProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OffshoreProject
 */
class OffshoreProjectResource extends JsonResource
{
    /** @var array<string, mixed>|null */
    public ?array $summary = null;

    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            ...$p->only(['id', 'code', 'name', 'client_company_id', 'contract_id', 'offshore_location_id', 'field_name', 'status', 'remarks', 'lock_version']),
            'start_date' => $p->start_date?->toDateString(),
            'end_date' => $p->end_date?->toDateString(),
            'client' => $p->client?->only(['id', 'legal_name']),
            'contract' => $p->contract?->only(['id', 'contract_number', 'status', 'currency']),
            'location' => $p->location?->only(['id', 'code', 'name']),
            'activities_count' => $p->getAttribute('activities_count'),
            'summary' => $this->when($this->summary !== null, fn () => $this->summary),
        ];
    }
}
