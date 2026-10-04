<?php

namespace App\Repositories;

use App\Models\Enquiry;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EnquiryRepository
{
    public const DETAIL = ['charterer', 'broker', 'cargoType', 'offshoreLocation', 'assignee', 'ports.port', 'ports.offshoreLocation', 'vessels.vessel'];

    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        $q = Enquiry::query()->with(['charterer', 'broker', 'ports.port', 'ports.offshoreLocation'])->withCount(['estimations', 'offers']);

        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('enquiry_number', 'like', $like)->orWhere('cargo_description', 'like', $like)
                ->orWhereHas('charterer', fn ($c) => $c->where('legal_name', 'like', $like))
                ->orWhereHas('broker', fn ($c) => $c->where('legal_name', 'like', $like)));
        }
        foreach (['status', 'business_type', 'charterer_company_id', 'broker_company_id', 'assigned_to'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['from'])) {
            $q->whereDate('received_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('received_at', '<=', $f['to']);
        }

        return ListQuery::sort($q, $f['sort'] ?? null, ['received_at', 'enquiry_number', 'laycan_from', 'status'], '-received_at')->paginate($perPage);
    }

    public function detail(Enquiry $enquiry): Enquiry
    {
        return $enquiry->load(self::DETAIL)->loadCount(['estimations', 'offers']);
    }
}
