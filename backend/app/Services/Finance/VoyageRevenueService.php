<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Enums\VoyageRevenueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\LaytimeCalculation;
use App\Models\OffshoreActivity;
use App\Models\RevenueCategory;
use App\Models\User;
use App\Models\VoyageRevenue;
use App\Services\ExchangeRateService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Voyage revenue lines (from offshore activities, laytime, or manual entries).
 *
 * Lifecycle: draft → confirmed → invoiced; any of draft|confirmed → cancelled.
 * Only drafts are editable (G-04). FX is snapshotted at save time (F1); the
 * amount is never silently recomputed after confirmation.
 *
 * F1: base_amount = round(amount × fx_rate_to_base, base_decimals)
 */
class VoyageRevenueService
{
    private const EDITABLE_FIELDS = [
        'voyage_id', 'contract_id', 'offshore_activity_id', 'laytime_calculation_id', 'revenue_category_id',
        'description', 'is_estimate', 'quantity', 'rate', 'currency', 'commission_pct_total',
        'service_period_from', 'service_period_to', 'remarks',
    ];

    public function __construct(private readonly ExchangeRateService $fx) {}

    /** @return LengthAwarePaginator<int, VoyageRevenue> */
    public function paginate(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->authorize($actor, Permission::RevenuesView);

        $query = VoyageRevenue::query()->with(['voyage', 'contract', 'category', 'offshoreActivity', 'laytimeCalculation']);
        $this->applyFilters($query, $filters);

        return $query->latest('id')->paginate($perPage);
    }

    public function find(User $actor, int $id): VoyageRevenue
    {
        $this->authorize($actor, Permission::RevenuesView);

        return VoyageRevenue::query()->with(['voyage', 'contract', 'offshoreActivity', 'laytimeCalculation', 'category', 'invoiceLine'])->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): VoyageRevenue
    {
        $this->authorize($actor, Permission::RevenuesCreate);

        return DB::transaction(function () use ($data, $actor) {
            $revenue = new VoyageRevenue(['created_by' => $actor->id]);
            $revenue->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $this->applyAmounts($revenue, $data);
            $revenue->setAttribute('updated_by', $actor->id);
            $revenue->save();

            return $revenue;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(VoyageRevenue $revenue, array $data, User $actor): VoyageRevenue
    {
        $this->authorize($actor, Permission::RevenuesUpdate);

        return DB::transaction(function () use ($revenue, $data, $actor) {
            $locked = VoyageRevenue::query()->lockForUpdate()->findOrFail($revenue->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} revenue line cannot be edited.", 'revenue_read_only');
            }
            $locked->fill(Arr::only($data, self::EDITABLE_FIELDS));
            $this->applyAmounts($locked, $data);
            $locked->setAttribute('updated_by', $actor->id);
            $locked->save();

            return $locked;
        });
    }

    public function confirm(VoyageRevenue $revenue, User $actor): VoyageRevenue
    {
        $this->authorize($actor, Permission::RevenuesUpdate);

        return DB::transaction(function () use ($revenue, $actor) {
            $locked = VoyageRevenue::query()->lockForUpdate()->findOrFail($revenue->id);
            if ($locked->status !== VoyageRevenueStatus::Draft) {
                throw new BusinessRuleException('Only a draft revenue line can be confirmed.', 'invalid_status_transition');
            }
            $locked->fill(['status' => VoyageRevenueStatus::Confirmed, 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function cancel(VoyageRevenue $revenue, User $actor, string $reason): VoyageRevenue
    {
        $this->authorize($actor, Permission::RevenuesUpdate);

        return DB::transaction(function () use ($revenue, $actor, $reason) {
            $locked = VoyageRevenue::query()->lockForUpdate()->findOrFail($revenue->id);
            if (! in_array($locked->status, [VoyageRevenueStatus::Draft, VoyageRevenueStatus::Confirmed], true)) {
                throw new BusinessRuleException("A {$locked->status->value} revenue line cannot be cancelled.", 'invalid_status_transition');
            }
            if ($locked->invoiceLine()->exists()) {
                throw new BusinessRuleException('This revenue line is already on an invoice. Cancel/credit the invoice first.', 'revenue_invoiced');
            }
            $locked->fill(['status' => VoyageRevenueStatus::Cancelled, 'remarks' => trim(($locked->remarks ? $locked->remarks."\n" : '')."Cancelled: {$reason}"), 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function markInvoiced(VoyageRevenue $revenue): void
    {
        $revenue->fill(['status' => VoyageRevenueStatus::Invoiced])->save();
    }

    /**
     * Activity→Revenue automation: Create or update voyage revenue from verified activity.
     * Called by OffshoreActivityService when an activity is verified.
     */
    public function syncFromActivity(OffshoreActivity $activity, User $actor): void
    {
        DB::transaction(function () use ($activity, $actor) {
            // Find existing revenue linked to this activity
            $revenue = VoyageRevenue::query()
                ->where('offshore_activity_id', $activity->id)
                ->lockForUpdate()
                ->first();

            // Find appropriate revenue category for offshore services
            $category = RevenueCategory::query()
                ->where('name', 'LIKE', '%Offshore%')
                ->orWhere('name', 'LIKE', '%Charter%')
                ->first();
            if (! $category) {
                $category = RevenueCategory::query()->first();
            }

            $activityTypeName = $activity->activityType?->name ?? 'Activity';

            $data = [
                'voyage_id' => $activity->voyage_id,
                'contract_id' => $activity->contract_id,
                'offshore_activity_id' => $activity->id,
                'revenue_category_id' => $category?->id,
                'description' => "{$activityTypeName} - {$activity->description}",
                'is_estimate' => false,
                'quantity' => $activity->billable_hours,
                'rate' => null, // Rate is already calculated in revenue_amount
                'currency' => $activity->currency,
                'amount' => $activity->revenue_amount,
                'commission_pct_total' => '0',
                'service_period_from' => $activity->start_at->toDateString(),
                'service_period_to' => $activity->end_at->toDateString(),
                'status' => VoyageRevenueStatus::Confirmed,
            ];

            if ($revenue) {
                // Update existing revenue if not invoiced
                if ($revenue->status === VoyageRevenueStatus::Invoiced) {
                    return; // Don't update invoiced revenue
                }
                $revenue->fill($data);
                $this->applyAmounts($revenue, $data);
                $revenue->setAttribute('updated_by', $actor->id);
                $revenue->save();
            } else {
                // Create new revenue
                $revenue = new VoyageRevenue(['created_by' => $actor->id]);
                $revenue->fill($data);
                $this->applyAmounts($revenue, $data);
                $revenue->setAttribute('updated_by', $actor->id);
                $revenue->save();
            }
        });
    }

    /**
     * Laytime→Revenue automation: Create or update voyage revenue from agreed laytime (demurrage).
     * Called by LaytimeService when a laytime calculation is agreed and has demurrage.
     */
    public function syncFromLaytime(LaytimeCalculation $laytime, User $actor): void
    {
        DB::transaction(function () use ($laytime, $actor) {
            // Find existing revenue linked to this laytime
            $revenue = VoyageRevenue::query()
                ->where('laytime_calculation_id', $laytime->id)
                ->lockForUpdate()
                ->first();

            // Find appropriate revenue category for demurrage
            $category = RevenueCategory::query()
                ->where('name', 'LIKE', '%Demurrage%')
                ->orWhere('name', 'LIKE', '%Laytime%')
                ->first();
            if (! $category) {
                $category = RevenueCategory::query()->first();
            }

            $portCall = $laytime->portCall;
            $portName = $portCall?->port?->name ?? 'Port';
            $data = [
                'voyage_id' => $laytime->voyage_id,
                'contract_id' => $laytime->contract_id,
                'laytime_calculation_id' => $laytime->id,
                'revenue_category_id' => $category?->id,
                'description' => "Demurrage - {$portName} ({$laytime->calculation_type})",
                'is_estimate' => false,
                'quantity' => $laytime->difference_hours,
                'rate' => $laytime->demurrage_rate_per_day,
                'currency' => $laytime->currency,
                'amount' => $laytime->demurrage_amount,
                'commission_pct_total' => '0',
                'service_period_from' => $laytime->laytime_commenced_at?->toDateString(),
                'service_period_to' => $laytime->laytime_completed_at?->toDateString(),
                'status' => VoyageRevenueStatus::Confirmed,
            ];

            if ($revenue) {
                // Update existing revenue if not invoiced
                if ($revenue->status === VoyageRevenueStatus::Invoiced) {
                    return; // Don't update invoiced revenue
                }
                $revenue->fill($data);
                $this->applyAmounts($revenue, $data);
                $revenue->setAttribute('updated_by', $actor->id);
                $revenue->save();
            } else {
                // Create new revenue
                $revenue = new VoyageRevenue(['created_by' => $actor->id]);
                $revenue->fill($data);
                $this->applyAmounts($revenue, $data);
                $revenue->setAttribute('updated_by', $actor->id);
                $revenue->save();
            }
        });
    }

    public function delete(VoyageRevenue $revenue, User $actor): void
    {
        $this->authorize($actor, Permission::RevenuesUpdate);

        DB::transaction(function () use ($revenue) {
            $locked = VoyageRevenue::query()->lockForUpdate()->findOrFail($revenue->id);
            if (! $locked->isEditable()) {
                throw new BusinessRuleException("A {$locked->status->value} revenue line cannot be deleted.", 'revenue_read_only');
            }
            $locked->delete();
        });
    }

    /** F1: base_amount = round(amount × fx_rate_to_base, base_decimals). Snapshots FX at save time. */
    private function applyAmounts(VoyageRevenue $revenue, array $data): void
    {
        $qty = $revenue->quantity;
        $rate = $revenue->rate;
        if (isset($data['amount'])) {
            $amount = Decimal::round((string) $data['amount'], 2);
        } elseif ($qty !== null && $rate !== null) {
            $amount = Decimal::round(Decimal::mul((string) $qty, (string) $rate), 2);
        } else {
            $amount = Decimal::round((string) ($revenue->amount ?? '0'), 2);
        }

        $base = (string) config('offshore.base_currency');
        $fxInfo = $this->fx->resolve($revenue->currency, $base, now());
        $baseAmount = Decimal::round(Decimal::mul($amount, $fxInfo['rate']), 2);

        $commissionPct = Decimal::round((string) ($revenue->commission_pct_total ?? '0'), 4);
        $commissionAmount = Decimal::round(Decimal::mul($amount, Decimal::div($commissionPct, '100')), 2);

        $revenue->fill([
            'amount' => $amount,
            'fx_rate' => $fxInfo['rate'],
            'base_amount' => $baseAmount,
            'commission_amount' => $commissionAmount,
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['voyage_id'])) {
            $query->where('voyage_id', (int) $filters['voyage_id']);
        }
        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', (int) $filters['contract_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['is_estimate'])) {
            $query->where('is_estimate', (bool) $filters['is_estimate']);
        }
    }

    private function authorize(User $actor, Permission $permission): void
    {
        if (! $actor->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
