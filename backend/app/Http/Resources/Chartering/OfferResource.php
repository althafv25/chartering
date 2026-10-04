<?php

namespace App\Http\Resources\Chartering;

use App\Http\Resources\Masters\CompanyRefResource;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $latest = $o->relationLoaded('revisions') ? $o->revisions->sortByDesc('revision_no')->first() : null;

        return [
            'id' => $o->id,
            'offer_number' => $o->offer_number,
            'status' => $o->status,
            'enquiry_id' => $o->enquiry_id,
            'enquiry' => $this->whenLoaded('enquiry', fn () => ['id' => $o->enquiry->id, 'enquiry_number' => $o->enquiry->enquiry_number, 'status' => $o->enquiry->status,
                'business_type' => $o->enquiry->business_type, 'charterer' => $o->enquiry->relationLoaded('charterer') ? $o->enquiry->charterer?->legal_name : null]),
            'vessel_id' => $o->vessel_id,
            'vessel' => $this->whenLoaded('vessel', fn () => $o->vessel->only(['id', 'code', 'name'])),
            'counterparty' => $this->whenLoaded('counterparty', fn () => $o->counterparty ? (new CompanyRefResource($o->counterparty))->resolve($request) : null),
            'revisions_count' => $this->whenCounted('revisions'),
            'latest_revision' => $latest ? (new OfferRevisionResource($latest))->resolve($request) : null,
            'revisions' => $this->whenLoaded('revisions', fn () => OfferRevisionResource::collection($o->revisions)->resolve($request)),
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }
}
