<?php

namespace App\Repositories;

use App\Models\Offer;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OfferRepository
{
    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        $q = Offer::query()->with(['enquiry.charterer', 'vessel', 'counterparty', 'revisions'])->withCount('revisions');
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('offer_number', 'like', $like)
                ->orWhereHas('enquiry', fn ($e) => $e->where('enquiry_number', 'like', $like))
                ->orWhereHas('vessel', fn ($v) => $v->where('name', 'like', $like)));
        }
        foreach (['status', 'enquiry_id', 'vessel_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }

        return ListQuery::sort($q, $f['sort'] ?? null, ['created_at', 'offer_number', 'status'], '-created_at')->paginate($perPage);
    }

    public function detail(Offer $offer): Offer
    {
        return $offer->load(['enquiry.charterer', 'vessel', 'counterparty', 'revisions.scenario.estimation', 'revisions.creator', 'revisions.fixture']);
    }
}
