<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Enums\VoyageExpenseStatus;
use App\Enums\VoyageRevenueStatus;
use App\Models\User;
use App\Models\Voyage;
use App\Models\VoyageExpense;
use App\Models\VoyageRevenue;
use App\Models\VoyageSnapshot;
use App\Services\Operations\VoyageMetricsService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Voyage P&L (F5/F6): estimate (initial snapshot) vs actual (ledger lines) in base currency.
 *
 * Actual revenue  = Σ base_amount of non-estimate revenue lines that are confirmed or invoiced.
 * Actual commission = Σ commission_amount × fx_rate of the same lines.
 * Actual expenses = Σ base_amount of non-estimate expense lines that are confirmed, approved or paid.
 * Gross profit = revenue − expenses (F5, before commission); net profit = gross − commission.
 * Variance = actual − estimate; % = variance / |estimate| (null when estimate = 0) (F6).
 * Overheads are not allocated (BR-FIN-02 is still [CONFIRM]). TCE is not calculated from actuals yet.
 */
class VoyageFinancialService
{
    public function __construct(private readonly VoyageMetricsService $metrics) {}

    /** @return array<string, mixed> */
    public function forVoyage(User $actor, Voyage $voyage): array
    {
        $this->authorize($actor);

        $actual = $this->actuals([$voyage->id])[$voyage->id] ?? $this->blank();
        $estimate = $this->estimate($voyage);

        $days = $this->metrics->current($voyage)['metrics']['total_days'] ?? null;
        $actual['profit_per_day'] = ($days !== null && Decimal::cmp($days, '0') > 0) ? Decimal::round(Decimal::div($actual['net_profit'], $days), 2) : null;

        $rows = [];
        foreach ([
            'gross_revenue' => 'Revenue', 'commission' => 'Commission', 'total_costs' => 'Expenses', 'net_profit' => 'Net profit',
        ] as $key => $label) {
            $est = $estimate[$key] ?? null;
            $act = $actual[$key];
            $variance = $est !== null ? Decimal::round(Decimal::sub($act, $est), 2) : null;
            $rows[] = [
                'metric' => $key, 'label' => $label, 'estimate' => $est, 'actual' => $act, 'variance' => $variance,
                'variance_pct' => ($variance !== null && $est !== null && Decimal::cmp($est, '0') !== 0)
                    ? Decimal::round(Decimal::mul(Decimal::div($variance, $this->abs($est)), '100'), 2) : null,
            ];
        }

        return [
            'voyage_id' => $voyage->id,
            'base_currency' => (string) config('offshore.base_currency'),
            'rows' => $rows,
            'gross_profit' => $actual['gross_profit'],
            'margin_pct' => $actual['margin_pct'],
            'profit_per_day' => $actual['profit_per_day'],
            'total_days' => $days,
            'revenue_by_category' => $this->byCategory($voyage->id, 'revenue'),
            'expense_by_category' => $this->byCategory($voyage->id, 'expense'),
            'estimate_available' => $estimate !== [],
            'notes' => ['Overheads are not allocated (BR-FIN-02 pending).', 'Estimate = initial snapshot; actual = confirmed ledger lines.'],
        ];
    }

    /**
     * Actual P&L figures per voyage id, computed with two grouped queries.
     *
     * @param  list<int>|null  $voyageIds  null = all voyages
     * @return array<int, array<string, string|null>>
     */
    public function actuals(?array $voyageIds = null): array
    {
        $revenue = VoyageRevenue::query()
            ->selectRaw('voyage_id, COALESCE(SUM(base_amount), 0) AS revenue, COALESCE(SUM(ROUND(commission_amount * fx_rate, 2)), 0) AS commission')
            ->whereNotNull('voyage_id')->where('is_estimate', false)
            ->whereIn('status', [VoyageRevenueStatus::Confirmed->value, VoyageRevenueStatus::Invoiced->value])
            ->when($voyageIds !== null, fn ($q) => $q->whereIn('voyage_id', $voyageIds))
            ->groupBy('voyage_id')->get()->keyBy('voyage_id');

        $expenses = VoyageExpense::query()
            ->selectRaw('voyage_id, COALESCE(SUM(base_amount), 0) AS expenses')
            ->whereNotNull('voyage_id')->where('is_estimate', false)
            ->whereIn('status', [VoyageExpenseStatus::Confirmed->value, VoyageExpenseStatus::Approved->value, VoyageExpenseStatus::Paid->value])
            ->when($voyageIds !== null, fn ($q) => $q->whereIn('voyage_id', $voyageIds))
            ->groupBy('voyage_id')->get()->keyBy('voyage_id');

        $out = [];
        foreach ($revenue->keys()->merge($expenses->keys())->unique() as $id) {
            $rev = Decimal::round((string) ($revenue[$id]->revenue ?? '0'), 2);
            $com = Decimal::round((string) ($revenue[$id]->commission ?? '0'), 2);
            $exp = Decimal::round((string) ($expenses[$id]->expenses ?? '0'), 2);
            $out[(int) $id] = $this->figures($rev, $com, $exp);
        }
        foreach ($voyageIds ?? [] as $id) {
            $out[$id] ??= $this->blank();
        }

        return $out;
    }

    /** @return array<string, string|null> */
    private function figures(string $revenue, string $commission, string $expenses): array
    {
        $gross = Decimal::round(Decimal::sub($revenue, $expenses), 2);
        $net = Decimal::round(Decimal::sub($gross, $commission), 2);

        return [
            'gross_revenue' => $revenue, 'commission' => $commission, 'total_costs' => $expenses, 'gross_profit' => $gross, 'net_profit' => $net,
            'margin_pct' => Decimal::cmp($revenue, '0') > 0 ? Decimal::round(Decimal::mul(Decimal::div($net, $revenue), '100'), 2) : null,
        ];
    }

    /** @return array<string, string|null> */
    private function blank(): array
    {
        return $this->figures('0.00', '0.00', '0.00');
    }

    /** @return array<string, string> empty when the voyage has no usable initial snapshot */
    private function estimate(Voyage $voyage): array
    {
        return $this->estimates([$voyage->id])[$voyage->id] ?? [];
    }

    /**
     * Estimated P&L figures per voyage id from the `initial` snapshot (one query). Voyages without a
     * usable snapshot are absent from the result.
     *
     * @param  list<int>  $voyageIds
     * @return array<int, array<string, string>>
     */
    public function estimates(array $voyageIds): array
    {
        $out = [];
        foreach (VoyageSnapshot::query()->where('type', 'initial')->whereIn('voyage_id', $voyageIds)->get(['voyage_id', 'payload']) as $snapshot) {
            $results = $snapshot->payload['results'] ?? null;
            if (! is_array($results)) {
                continue;
            }
            $figures = [];
            foreach (['gross_revenue', 'total_costs', 'profit'] as $key) {
                if (isset($results[$key]) && is_numeric($results[$key])) {
                    $figures[$key === 'profit' ? 'net_profit' : $key] = Decimal::round((string) $results[$key], 2);
                }
            }
            if (isset($results['total_commission']) && is_numeric($results['total_commission'])) {
                $figures['commission'] = Decimal::round((string) $results['total_commission'], 2);
            }
            if ($figures !== []) {
                $out[(int) $snapshot->voyage_id] = $figures;
            }
        }

        return $out;
    }

    /** @return list<array{category: string, amount: string}> */
    private function byCategory(int $voyageId, string $kind): array
    {
        if ($kind === 'revenue') {
            $rows = DB::table('voyage_revenues as l')->join('revenue_categories as c', 'c.id', '=', 'l.revenue_category_id')
                ->where('l.voyage_id', $voyageId)->where('l.is_estimate', false)->whereNull('l.deleted_at')
                ->whereIn('l.status', [VoyageRevenueStatus::Confirmed->value, VoyageRevenueStatus::Invoiced->value]);
        } else {
            $rows = DB::table('voyage_expenses as l')->join('expense_categories as c', 'c.id', '=', 'l.expense_category_id')
                ->where('l.voyage_id', $voyageId)->where('l.is_estimate', false)->whereNull('l.deleted_at')
                ->whereIn('l.status', [VoyageExpenseStatus::Confirmed->value, VoyageExpenseStatus::Approved->value, VoyageExpenseStatus::Paid->value]);
        }

        return $rows->selectRaw('c.name AS category, SUM(l.base_amount) AS amount')->groupBy('c.name')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['category' => (string) $r->category, 'amount' => Decimal::round((string) $r->amount, 2)])->all();
    }

    private function abs(string $v): string
    {
        return str_starts_with($v, '-') ? substr($v, 1) : $v;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can(Permission::FinancialsView->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
