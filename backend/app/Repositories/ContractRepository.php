<?php

namespace App\Repositories;

use App\Models\Contract;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ContractRepository
{
    public const DETAIL = ['customer', 'vessel', 'fixture', 'submitter', 'decider', 'rates.activityType', 'clauses', 'versions', 'amendments.creator', 'amendments.decider'];

    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        $q = Contract::query()->with(['customer', 'vessel', 'fixture']);
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('contract_number', 'like', $like)->orWhere('title', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('legal_name', 'like', $like))
                ->orWhereHas('vessel', fn ($v) => $v->where('name', 'like', $like)));
        }
        foreach (['status', 'contract_type', 'customer_company_id', 'vessel_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['expiring_within_days'])) {
            $q->whereIn('status', ['approved', 'active'])->whereDate('end_date', '>=', today())
                ->whereDate('end_date', '<=', today()->addDays((int) $f['expiring_within_days']));
        }

        return ListQuery::sort($q, $f['sort'] ?? null, ['contract_number', 'start_date', 'end_date', 'created_at', 'status'], '-created_at')->paginate($perPage);
    }

    public function detail(Contract $contract): Contract
    {
        return $contract->load(self::DETAIL);
    }
}
