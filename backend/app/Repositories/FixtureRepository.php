<?php

namespace App\Repositories;

use App\Models\Fixture;
use App\Models\Voyage;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Fixtures and voyages listing (read side, phase 4). */
class FixtureRepository
{
    /** @param array<string, mixed> $f */
    public function fixtures(array $f, int $perPage): LengthAwarePaginator
    {
        $q = Fixture::query()->with(['vessel', 'charterer', 'enquiry']);
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('fixture_number', 'like', $like)->orWhereHas('vessel', fn ($v) => $v->where('name', 'like', $like)));
        }
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }

        return $q->latest('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $f */
    public function voyages(array $f, int $perPage): LengthAwarePaginator
    {
        $q = Voyage::query()->with(['vessel', 'estimation', 'scenario', 'charterer', 'portCalls.port', 'portCalls.location']);
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('voyage_number', 'like', $like)->orWhereHas('vessel', fn ($v) => $v->where('name', 'like', $like)));
        }
        if (! empty($f['open'])) {
            $q->whereNotIn('status', ['finalized', 'cancelled']);
        }
        foreach (['status', 'conversion_type', 'vessel_id', 'operation_type'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }

        return $q->latest('id')->paginate($perPage);
    }
}
