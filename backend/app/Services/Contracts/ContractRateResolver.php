<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractRate;
use App\Models\ContractVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Effective-rate lookup (CT-03): for date D, the version is the highest
 * version_no whose effective_from ≤ D; within it a rate line applies when
 * effective_from ≤ D ≤ effective_to (open ends allowed). Used by offshore
 * activities / invoicing in later phases, which must snapshot the result.
 */
class ContractRateResolver
{
    public function versionAt(Contract $contract, CarbonInterface|string $date): ?ContractVersion
    {
        $d = Carbon::parse($date)->toDateString();

        return ContractVersion::query()->where('contract_id', $contract->id)->whereDate('effective_from', '<=', $d)
            ->orderByDesc('version_no')->first();
    }

    /** @return Collection<int, ContractRate> */
    public function ratesAt(Contract $contract, CarbonInterface|string $date): Collection
    {
        $version = $this->versionAt($contract, $date);
        if (! $version || ($contract->end_date && Carbon::parse($date)->gt($contract->end_date))) {
            return collect();
        }
        $d = Carbon::parse($date)->toDateString();

        return ContractRate::query()->with('activityType')->where('contract_id', $contract->id)->where('version_no', $version->version_no)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $d))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d))
            ->orderBy('rate_type')->get();
    }

    /** Specific rate; an activity-specific line wins over a generic one of the same type. */
    public function rateFor(Contract $contract, CarbonInterface|string $date, string $rateType, ?int $activityTypeId = null): ?ContractRate
    {
        $rates = $this->ratesAt($contract, $date)->where('rate_type', $rateType);

        return ($activityTypeId ? $rates->firstWhere('offshore_activity_type_id', $activityTypeId) : null)
            ?? $rates->firstWhere('offshore_activity_type_id', null);
    }
}
