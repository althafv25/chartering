<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Enums\VoyageExpenseStatus;
use App\Enums\VoyageRevenueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Voyage;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Statistics (03 M17). All need statistics.view; money metrics also need commercial.financials.view and the
 * offshore-hours metrics offshore-activities.view. Results share one shape: {metric, label, unit, group_by,
 * rows: [{label, total, line_count}], total}.
 *
 *  revenue / expenses    base-currency totals of non-estimate lines (confirmed or later)
 *  billable-hours        verified (or invoiced) offshore activities, billable hours
 *  standby-hours         same activities, standby hours
 *  avg-voyage-profit     average actual net profit of completed/finalized voyages that have ledger lines
 *                        (total = average over all those voyages; line_count = voyages)
 */
class StatisticsService
{
    private const METRICS = [
        'revenue' => ['label' => 'Revenue', 'unit' => 'money', 'permission' => Permission::FinancialsView, 'groups' => ['vessel', 'customer', 'month', 'category'],
            'table' => 'voyage_revenues', 'category_table' => 'revenue_categories', 'category_column' => 'revenue_category_id'],
        'expenses' => ['label' => 'Expenses', 'unit' => 'money', 'permission' => Permission::FinancialsView, 'groups' => ['vessel', 'month', 'category'],
            'table' => 'voyage_expenses', 'category_table' => 'expense_categories', 'category_column' => 'expense_category_id'],
        'avg-voyage-profit' => ['label' => 'Average voyage profit', 'unit' => 'money', 'permission' => Permission::FinancialsView, 'groups' => ['vessel', 'month']],
        'billable-hours' => ['label' => 'Offshore billable hours', 'unit' => 'hours', 'permission' => Permission::OffshoreActivitiesView, 'groups' => ['vessel', 'month', 'activity_type'], 'column' => 'billable_hours'],
        'standby-hours' => ['label' => 'Offshore standby hours', 'unit' => 'hours', 'permission' => Permission::OffshoreActivitiesView, 'groups' => ['vessel', 'month', 'activity_type'], 'column' => 'standby_hours'],
    ];

    public function __construct(private readonly VoyageFinancialService $financials) {}

    /** @return list<array{metric: string, label: string, unit: string, groups: list<string>}> the metrics this user may run */
    public function catalogue(User $actor): array
    {
        $out = [];
        foreach (self::METRICS as $key => $def) {
            if ($this->allowed($actor, $def['permission'])) {
                $out[] = ['metric' => $key, 'label' => $def['label'], 'unit' => $def['unit'], 'groups' => $def['groups']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $filters  group_by, from, to
     * @return array<string, mixed>
     */
    public function metric(User $actor, string $metric, array $filters = []): array
    {
        $def = self::METRICS[$metric] ?? null;
        if ($def !== null && ! $this->allowed($actor, $def['permission'])) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
        if (! $actor->can(Permission::StatisticsView->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
        $def ?? throw new BusinessRuleException('Unknown statistic.', 'statistic_not_found', [], 404);

        $groupBy = (string) ($filters['group_by'] ?? 'month');
        if (! in_array($groupBy, $def['groups'], true)) {
            throw new BusinessRuleException('Unsupported grouping for this statistic.', 'validation_failed', ['group_by' => ['Allowed: '.implode(', ', $def['groups'])]], 422);
        }

        [$rows, $total] = match ($metric) {
            'revenue', 'expenses' => $this->ledgerTotals($metric, $def, $groupBy, $filters),
            'avg-voyage-profit' => $this->averageVoyageProfit($groupBy, $filters),
            default => $this->hours($def['column'], $groupBy, $filters),
        };

        return [
            'metric' => $metric, 'label' => $def['label'], 'unit' => $def['unit'], 'group_by' => $groupBy,
            'base_currency' => $def['unit'] === 'money' ? (string) config('offshore.base_currency') : null, 'rows' => $rows, 'total' => $total,
        ];
    }

    private function allowed(User $actor, Permission $permission): bool
    {
        return $actor->can(Permission::StatisticsView->value) && $actor->can($permission->value);
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array{label: string, total: string, line_count: int}>, 1: string}
     */
    private function ledgerTotals(string $metric, array $def, string $groupBy, array $filters): array
    {
        $statuses = $metric === 'revenue'
            ? [VoyageRevenueStatus::Confirmed->value, VoyageRevenueStatus::Invoiced->value]
            : [VoyageExpenseStatus::Confirmed->value, VoyageExpenseStatus::Approved->value, VoyageExpenseStatus::Paid->value];

        $q = DB::table("{$def['table']} as l")->whereNull('l.deleted_at')->where('l.is_estimate', false)->whereIn('l.status', $statuses)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('l.created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('l.created_at', '<=', $filters['to']));

        return match ($groupBy) {
            'vessel' => $this->byId($q->leftJoin('voyages as v', 'v.id', '=', 'l.voyage_id'), 'v.vessel_id', 'base_amount', 'l', 'vessels', 'name', 'No voyage'),
            'customer' => $this->byId($q->leftJoin('voyages as v', 'v.id', '=', 'l.voyage_id'), 'v.charterer_company_id', 'base_amount', 'l', 'companies', 'legal_name', 'No customer'),
            'category' => $this->byId($q, "l.{$def['category_column']}", 'base_amount', 'l', $def['category_table'], 'name', 'Uncategorised'),
            default => $this->collect($q->selectRaw("DATE_FORMAT(l.created_at, '%Y-%m') AS label, SUM(l.base_amount) AS total, COUNT(*) AS line_count")->groupBy('label')->orderBy('label')->get()),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array{label: string, total: string, line_count: int}>, 1: string}
     */
    private function hours(string $column, string $groupBy, array $filters): array
    {
        $q = DB::table('offshore_activities as a')->whereNull('a.deleted_at')->whereIn('a.status', ['verified', 'invoiced'])
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('a.start_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('a.start_at', '<=', $filters['to']));

        return match ($groupBy) {
            'vessel' => $this->byId($q, 'a.vessel_id', $column, 'a', 'vessels', 'name', 'Unknown'),
            'activity_type' => $this->byId($q, 'a.offshore_activity_type_id', $column, 'a', 'offshore_activity_types', 'name', 'Unknown'),
            default => $this->collect($q->selectRaw("DATE_FORMAT(a.start_at, '%Y-%m') AS label, SUM(a.{$column}) AS total, COUNT(*) AS line_count")->groupBy('label')->orderBy('label')->get()),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array{label: string, total: string, line_count: int}>, 1: string}
     */
    private function averageVoyageProfit(string $groupBy, array $filters): array
    {
        $voyages = Voyage::query()->with('vessel:id,name')->whereIn('status', ['completed', 'finalized'])
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('completed_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('completed_at', '<=', $filters['to']))
            ->get(['id', 'vessel_id', 'completed_at']);
        $actuals = $this->financials->actuals($voyages->pluck('id')->all());

        $groups = [];
        $sum = '0';
        $count = 0;
        foreach ($voyages as $v) {
            $a = $actuals[$v->id];
            if (Decimal::isZero($a['gross_revenue']) && Decimal::isZero($a['total_costs'])) {
                continue;   // no ledger lines yet: would drag the average to zero
            }
            $label = $groupBy === 'vessel' ? ($v->vessel?->name ?? 'Unknown') : ($v->completed_at?->format('Y-m') ?? 'Unknown');
            $g = $groups[$label] ??= ['sum' => '0', 'n' => 0];
            $g['sum'] = Decimal::add($g['sum'], $a['net_profit']);
            $g['n']++;
            $groups[$label] = $g;
            $sum = Decimal::add($sum, $a['net_profit']);
            $count++;
        }

        $rows = [];
        foreach ($groups as $label => $g) {
            $rows[] = ['label' => (string) $label, 'total' => Decimal::round(Decimal::div($g['sum'], (string) $g['n']), 2), 'line_count' => $g['n']];
        }
        usort($rows, fn (array $a, array $b) => $groupBy === 'month' ? strcmp($a['label'], $b['label']) : Decimal::cmp($b['total'], $a['total']));

        return [$rows, $count > 0 ? Decimal::round(Decimal::div($sum, (string) $count), 2) : '0.00'];
    }

    /**
     * Groups by a foreign-key id (cheap, indexed) and looks the names up afterwards, instead of grouping by a joined
     * text column. Rows are sorted by total, largest first; a null id gets the fallback label.
     *
     * @return array{0: list<array{label: string, total: string, line_count: int}>, 1: string}
     */
    private function byId(Builder $q, string $idExpr, string $sumColumn, string $alias, string $nameTable, string $nameColumn, string $fallback): array
    {
        $rows = $q->selectRaw("{$idExpr} AS group_id, SUM({$alias}.{$sumColumn}) AS total, COUNT(*) AS line_count")->groupBy($idExpr)->get();
        $names = DB::table($nameTable)->whereIn('id', $rows->pluck('group_id')->filter()->unique())->pluck($nameColumn, 'id');
        $labelled = $rows->map(fn ($r) => (object) ['label' => $r->group_id !== null ? (string) ($names[$r->group_id] ?? "#{$r->group_id}") : $fallback, 'total' => $r->total, 'line_count' => $r->line_count])
            ->sort(fn ($a, $b) => Decimal::cmp((string) $b->total, (string) $a->total))->values();

        return $this->collect($labelled);
    }

    /**
     * @param  iterable<object>  $rows
     * @return array{0: list<array{label: string, total: string, line_count: int}>, 1: string}
     */
    private function collect(iterable $rows): array
    {
        $grand = '0';
        $data = [];
        foreach ($rows as $r) {
            $total = Decimal::round((string) $r->total, 2);
            $grand = Decimal::add($grand, $total);
            $data[] = ['label' => (string) $r->label, 'total' => $total, 'line_count' => (int) $r->line_count];
        }

        return [$data, Decimal::round($grand, 2)];
    }
}
