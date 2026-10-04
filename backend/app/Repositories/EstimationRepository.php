<?php

namespace App\Repositories;

use App\Models\Estimation;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EstimationRepository
{
    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        $q = Estimation::query()->with(['vessel', 'enquiry', 'selectedScenario.result'])->withCount('scenarios');
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('estimation_number', 'like', $like)->orWhere('title', 'like', $like)
                ->orWhereHas('vessel', fn ($v) => $v->where('name', 'like', $like))
                ->orWhereHas('enquiry', fn ($e) => $e->where('enquiry_number', 'like', $like)));
        }
        foreach (['status', 'estimation_type', 'vessel_id', 'enquiry_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }

        return ListQuery::sort($q, $f['sort'] ?? null, ['created_at', 'estimation_number', 'status'], '-created_at')->paginate($perPage);
    }

    public function detail(Estimation $est): Estimation
    {
        return $est->load(['vessel', 'enquiry.charterer', 'submitter', 'decider', 'clonedFrom', 'scenarios.result']);
    }
}
