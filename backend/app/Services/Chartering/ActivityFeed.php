<?php

namespace App\Services\Chartering;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

/** Record-scoped activity history (shown to users who can view the record, without audit-log access). */
class ActivityFeed
{
    /** @param array<string, list<int>> $subjects morph alias => ids */
    public function for(array $subjects, int $perPage = 25): LengthAwarePaginator
    {
        return Activity::query()->with('causer')
            ->where(function ($q) use ($subjects) {
                foreach ($subjects as $type => $ids) {
                    if ($ids) {
                        $q->orWhere(fn ($w) => $w->where('subject_type', $type)->whereIn('subject_id', $ids));
                    }
                }
            })
            ->latest('id')->paginate($perPage);
    }
}
