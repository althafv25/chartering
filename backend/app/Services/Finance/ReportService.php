<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Enums\VoyageExpenseStatus;
use App\Enums\VoyageRevenueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Enquiry;
use App\Models\User;
use App\Models\Voyage;
use App\Services\Operations\FleetReports;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Report catalogue (03 M17). Each report returns {columns, rows} of display-ready strings so the
 * same payload feeds the JSON view and the CSV export. Money is in base currency as 2-dp strings.
 * Report permission = reports.view plus the report's own permission (financial reports need
 * commercial.financials.view for aggregated profitability; line registers use revenues.view/expenses.view).
 * CSV, Excel (.xlsx) and PDF exports need reports.export.
 */
class ReportService
{
    /** slug => [title, permission, filters] */
    private const CATALOGUE = [
        'voyage-pnl' => ['Voyage P&L', Permission::FinancialsView, ['vessel_id', 'status', 'from', 'to']],
        'outstanding-invoices' => ['Outstanding invoices (aging)', Permission::InvoicesView, ['customer_company_id', 'as_of']],
        'revenue' => ['Revenue lines', Permission::RevenuesView, ['voyage_id', 'status', 'from', 'to']],
        'expenses' => ['Expense lines', Permission::ExpensesView, ['voyage_id', 'status', 'from', 'to']],
        'estimated-vs-actual' => ['Estimated vs actual (voyages)', Permission::FinancialsView, ['vessel_id', 'status', 'from', 'to']],
        'vessel-profitability' => ['Vessel profitability', Permission::FinancialsView, ['status', 'from', 'to']],
        'voyage-status' => ['Voyage status', Permission::VoyagesView, ['vessel_id', 'status', 'from', 'to']],
        'fleet-status' => ['Fleet status', Permission::VesselsView, []],
        'contract-status' => ['Contract status', Permission::ContractsView, ['status']],
        'bunker-consumption' => ['Bunker consumption (verified reports)', Permission::BunkersView, ['voyage_id', 'from', 'to']],
        'port-cost' => ['Port cost (Port DA)', Permission::PortDaView, ['voyage_id', 'status', 'from', 'to']],
        'offshore-activities' => ['Offshore activities', Permission::OffshoreActivitiesView, ['voyage_id', 'status', 'from', 'to']],
        'laytime-demurrage' => ['Laytime & demurrage', Permission::LaytimeView, ['voyage_id', 'status', 'from', 'to']],
        'chartering-activity' => ['Chartering activity (enquiries)', Permission::EnquiriesView, ['status', 'from', 'to']],
        'vessel-utilization' => ['Vessel utilization', Permission::VoyagesView, ['vessel_id', 'from', 'to']],
        'vessel-performance' => ['Vessel performance (verified reports)', Permission::CaptainReportsView, ['vessel_id', 'from', 'to']],
    ];

    public function __construct(private readonly VoyageFinancialService $financials, private readonly FleetReports $fleet) {}

    /** @return list<array{slug: string, title: string, filters: list<string>, formats: list<string>}> */
    public function catalogue(User $actor): array
    {
        $this->authorizeView($actor);
        $out = [];
        foreach (self::CATALOGUE as $slug => [$title, $permission, $filters]) {
            if ($actor->can($permission->value)) {
                $out[] = ['slug' => $slug, 'title' => $title, 'filters' => $filters, 'formats' => $actor->can(Permission::ReportsExport->value) ? ['json', 'csv', 'xlsx', 'pdf'] : ['json']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{slug: string, title: string, base_currency: string, columns: list<array{key: string, label: string, align: string}>, rows: list<array<string, string|null>>, truncated: bool, row_limit: int}
     */
    public function run(User $actor, string $slug, array $filters = [], ?int $limit = null): array
    {
        $limit ??= (int) config('offshore.reports.view_limit');
        $this->authorizeView($actor);
        if (! isset(self::CATALOGUE[$slug])) {
            throw new BusinessRuleException('Unknown report.', 'report_not_found', [], 404);
        }
        [$title, $permission] = self::CATALOGUE[$slug];
        if (! $actor->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }

        [$columns, $rows] = match ($slug) {
            'voyage-pnl' => $this->voyagePnl($filters),
            'outstanding-invoices' => $this->outstandingInvoices($filters, $limit + 1),
            'estimated-vs-actual' => $this->estimatedVsActual($filters),
            'vessel-profitability' => $this->vesselProfitability($filters),
            'voyage-status' => $this->voyageStatus($filters),
            'fleet-status' => $this->fleetStatus(),
            'contract-status' => $this->contractStatus($filters),
            'bunker-consumption' => $this->bunkerConsumption($filters),
            'port-cost' => $this->portCost($filters),
            'offshore-activities' => $this->offshoreActivities($filters, $actor),
            'laytime-demurrage' => $this->laytimeDemurrage($filters),
            'chartering-activity' => $this->charteringActivity($filters),
            'vessel-utilization' => $this->fleet->utilization($filters),
            'vessel-performance' => $this->fleet->performance($filters),
            'revenue' => $this->lines('voyage_revenues', 'revenue_categories', 'revenue_category_id', $filters, [VoyageRevenueStatus::Confirmed->value, VoyageRevenueStatus::Invoiced->value], $limit + 1),
            default => $this->lines('voyage_expenses', 'expense_categories', 'expense_category_id', $filters, [VoyageExpenseStatus::Confirmed->value, VoyageExpenseStatus::Approved->value, VoyageExpenseStatus::Paid->value], $limit + 1),
        };

        $truncated = count($rows) > $limit;

        return [
            'slug' => $slug, 'title' => $title, 'base_currency' => (string) config('offshore.base_currency'), 'columns' => $columns,
            'rows' => $truncated ? array_slice($rows, 0, $limit) : $rows, 'truncated' => $truncated, 'row_limit' => $limit,
        ];
    }

    public function assertExportable(User $actor): void
    {
        if (! $actor->can(Permission::ReportsExport->value)) {
            throw new AuthorizationException('You do not have permission to export reports.');
        }
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function voyagePnl(array $f): array
    {
        $voyages = $this->voyages($f);
        $actuals = $this->financials->actuals($voyages->pluck('id')->all());

        $rows = [];
        foreach ($voyages as $v) {
            $a = $actuals[$v->id];
            $rows[] = [
                'voyage' => $v->voyage_number, 'vessel' => $v->vessel?->name, 'charterer' => $v->charterer?->legal_name, 'status' => $v->status,
                'revenue' => $a['gross_revenue'], 'commission' => $a['commission'], 'expenses' => $a['total_costs'], 'net_profit' => $a['net_profit'],
                'margin_pct' => $a['margin_pct'],
            ];
        }

        return [[
            $this->col('voyage', 'Voyage'), $this->col('vessel', 'Vessel'), $this->col('charterer', 'Charterer'), $this->col('status', 'Status'),
            $this->col('revenue', 'Revenue', 'right'), $this->col('commission', 'Commission', 'right'), $this->col('expenses', 'Expenses', 'right'),
            $this->col('net_profit', 'Net profit', 'right'), $this->col('margin_pct', 'Margin %', 'right'),
        ], $rows];
    }

    /** @return Collection<int, Voyage> */
    private function voyages(array $f): Collection
    {
        return Voyage::query()->with('vessel:id,name', 'charterer:id,legal_name')
            ->when(! empty($f['vessel_id']), fn ($q) => $q->where('vessel_id', (int) $f['vessel_id']))
            ->when(! empty($f['status']), fn ($q) => $q->where('status', (string) $f['status']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('created_at', '<=', $f['to']))
            ->orderBy('id')->get();
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function estimatedVsActual(array $f): array
    {
        $voyages = $this->voyages($f);
        $ids = $voyages->pluck('id')->all();
        $actuals = $this->financials->actuals($ids);
        $estimates = $this->financials->estimates($ids);

        $rows = [];
        foreach ($voyages as $v) {
            $est = $estimates[$v->id] ?? null;
            $act = $actuals[$v->id];
            $variance = $est !== null && isset($est['net_profit']) ? Decimal::round(Decimal::sub($act['net_profit'], $est['net_profit']), 2) : null;
            $rows[] = [
                'voyage' => $v->voyage_number, 'vessel' => $v->vessel?->name, 'status' => $v->status,
                'est_revenue' => $est['gross_revenue'] ?? null, 'act_revenue' => $act['gross_revenue'],
                'est_profit' => $est['net_profit'] ?? null, 'act_profit' => $act['net_profit'], 'variance' => $variance,
                'variance_pct' => ($variance !== null && Decimal::cmp($est['net_profit'], '0') !== 0)
                    ? Decimal::round(Decimal::mul(Decimal::div($variance, ltrim($est['net_profit'], '-')), '100'), 2) : null,
            ];
        }

        return [[
            $this->col('voyage', 'Voyage'), $this->col('vessel', 'Vessel'), $this->col('status', 'Status'),
            $this->col('est_revenue', 'Est. revenue', 'right'), $this->col('act_revenue', 'Act. revenue', 'right'),
            $this->col('est_profit', 'Est. net profit', 'right'), $this->col('act_profit', 'Act. net profit', 'right'),
            $this->col('variance', 'Variance', 'right'), $this->col('variance_pct', 'Variance %', 'right'),
        ], $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function vesselProfitability(array $f): array
    {
        $voyages = $this->voyages($f);
        $actuals = $this->financials->actuals($voyages->pluck('id')->all());

        $byVessel = [];
        foreach ($voyages as $v) {
            $a = $actuals[$v->id];
            $row = $byVessel[$v->vessel_id] ??= ['vessel' => $v->vessel?->name, 'voyages' => 0, 'revenue' => '0.00', 'commission' => '0.00', 'expenses' => '0.00', 'net_profit' => '0.00'];
            $row['voyages']++;
            $row['revenue'] = Decimal::add($row['revenue'], $a['gross_revenue']);
            $row['commission'] = Decimal::add($row['commission'], $a['commission']);
            $row['expenses'] = Decimal::add($row['expenses'], $a['total_costs']);
            $row['net_profit'] = Decimal::add($row['net_profit'], $a['net_profit']);
            $byVessel[$v->vessel_id] = $row;
        }
        $rows = [];
        foreach ($byVessel as $row) {
            $rows[] = [
                'vessel' => $row['vessel'], 'voyages' => (string) $row['voyages'], 'revenue' => Decimal::round($row['revenue'], 2), 'commission' => Decimal::round($row['commission'], 2),
                'expenses' => Decimal::round($row['expenses'], 2), 'net_profit' => Decimal::round($row['net_profit'], 2),
                'margin_pct' => Decimal::cmp($row['revenue'], '0') > 0 ? Decimal::round(Decimal::mul(Decimal::div($row['net_profit'], $row['revenue']), '100'), 2) : null,
            ];
        }
        usort($rows, fn (array $a, array $b) => Decimal::cmp((string) $b['net_profit'], (string) $a['net_profit']));

        return [[
            $this->col('vessel', 'Vessel'), $this->col('voyages', 'Voyages', 'right'), $this->col('revenue', 'Revenue', 'right'), $this->col('commission', 'Commission', 'right'),
            $this->col('expenses', 'Expenses', 'right'), $this->col('net_profit', 'Net profit', 'right'), $this->col('margin_pct', 'Margin %', 'right'),
        ], $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function voyageStatus(array $f): array
    {
        $rows = [];
        foreach ($this->voyages($f) as $v) {
            $rows[] = [
                'voyage' => $v->voyage_number, 'vessel' => $v->vessel?->name, 'charterer' => $v->charterer?->legal_name, 'type' => $v->operation_type, 'status' => $v->status,
                'commenced' => $v->commenced_at?->toDateString(), 'completed' => $v->completed_at?->toDateString(), 'finalized' => $v->finalized_at?->toDateString(),
            ];
        }

        return [[
            $this->col('voyage', 'Voyage'), $this->col('vessel', 'Vessel'), $this->col('charterer', 'Charterer'), $this->col('type', 'Type'), $this->col('status', 'Status'),
            $this->col('commenced', 'Commenced'), $this->col('completed', 'Completed'), $this->col('finalized', 'Finalized'),
        ], $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function fleetStatus(): array
    {
        $rows = DB::table('vessels as v')->leftJoin('vessel_types as t', 't.id', '=', 'v.vessel_type_id')->whereNull('v.deleted_at')->orderBy('v.name')
            ->get(['v.code', 'v.name', 'v.imo_number', 't.name as type', 'v.flag_country', 'v.dwt_mt', 'v.status', 'v.commercial_status', 'v.operational_status'])
            ->map(fn ($r) => [
                'code' => $r->code, 'name' => $r->name, 'imo' => $r->imo_number, 'type' => $r->type, 'flag' => $r->flag_country, 'dwt' => $r->dwt_mt !== null ? Decimal::round((string) $r->dwt_mt, 2) : null,
                'status' => $r->status, 'commercial' => $r->commercial_status, 'operational' => $r->operational_status,
            ])->all();

        return [[
            $this->col('code', 'Code'), $this->col('name', 'Vessel'), $this->col('imo', 'IMO'), $this->col('type', 'Type'), $this->col('flag', 'Flag'),
            $this->col('dwt', 'DWT (mt)', 'right'), $this->col('status', 'Record status'), $this->col('commercial', 'Commercial status'), $this->col('operational', 'Operational status'),
        ], $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function contractStatus(array $f): array
    {
        $rows = DB::table('contracts as c')->leftJoin('companies as x', 'x.id', '=', 'c.customer_company_id')->leftJoin('vessels as v', 'v.id', '=', 'c.vessel_id')
            ->whereNull('c.deleted_at')->when(! empty($f['status']), fn ($q) => $q->where('c.status', (string) $f['status']))->orderBy('c.contract_number')
            ->get(['c.contract_number', 'c.title', 'c.contract_type', 'x.legal_name as customer', 'v.name as vessel', 'c.start_date', 'c.end_date', 'c.current_version', 'c.status'])
            ->map(fn ($r) => [
                'contract' => $r->contract_number, 'title' => $r->title, 'type' => $r->contract_type, 'customer' => $r->customer, 'vessel' => $r->vessel,
                'start' => $r->start_date, 'end' => $r->end_date, 'version' => (string) $r->current_version, 'status' => $r->status,
            ])->all();

        return [[
            $this->col('contract', 'Contract'), $this->col('title', 'Title'), $this->col('type', 'Type'), $this->col('customer', 'Customer'), $this->col('vessel', 'Vessel'),
            $this->col('start', 'Start'), $this->col('end', 'End'), $this->col('version', 'Version', 'right'), $this->col('status', 'Status'),
        ], $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function bunkerConsumption(array $f): array
    {
        $rows = DB::table('captain_report_fuel_lines as l')->join('captain_reports as r', 'r.id', '=', 'l.captain_report_id')
            ->whereNull('r.deleted_at')->where('r.status', 'verified')->whereNotNull('r.voyage_id')
            ->when(! empty($f['voyage_id']), fn ($q) => $q->where('r.voyage_id', (int) $f['voyage_id']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('r.reported_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('r.reported_at', '<=', $f['to']))
            ->groupBy('r.voyage_id', 'l.fuel_type_id')
            ->selectRaw('r.voyage_id, l.fuel_type_id, SUM(l.consumed_mt) AS consumed, SUM(l.received_mt) AS received, COUNT(DISTINCT r.id) AS reports')->get();
        // Names are looked up after grouping by id: much cheaper than grouping by joined text columns.
        $voyages = DB::table('voyages')->whereIn('id', $rows->pluck('voyage_id')->unique())->pluck('voyage_number', 'id');
        $fuels = DB::table('fuel_types')->whereIn('id', $rows->pluck('fuel_type_id')->unique())->pluck('name', 'id');

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'voyage' => (string) ($voyages[$r->voyage_id] ?? "#{$r->voyage_id}"), 'fuel' => (string) ($fuels[$r->fuel_type_id] ?? "#{$r->fuel_type_id}"),
                'consumed' => Decimal::round((string) $r->consumed, 3), 'received' => Decimal::round((string) $r->received, 3), 'reports' => (string) $r->reports,
            ];
        }
        usort($out, fn (array $a, array $b) => [$a['voyage'], $a['fuel']] <=> [$b['voyage'], $b['fuel']]);

        return [[
            $this->col('voyage', 'Voyage'), $this->col('fuel', 'Fuel'), $this->col('consumed', 'Consumed (mt)', 'right'), $this->col('received', 'Received (mt)', 'right'), $this->col('reports', 'Reports', 'right'),
        ], $out];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function portCost(array $f): array
    {
        $rows = DB::table('port_das as d')->join('voyages as v', 'v.id', '=', 'd.voyage_id')->leftJoin('ports as p', 'p.id', '=', 'd.port_id')
            ->whereNull('d.deleted_at')
            ->when(! empty($f['voyage_id']), fn ($q) => $q->where('d.voyage_id', (int) $f['voyage_id']))
            ->when(! empty($f['status']), fn ($q) => $q->where('d.status', (string) $f['status']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('d.created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('d.created_at', '<=', $f['to']))
            ->orderBy('d.id')
            ->get(['d.da_number', 'v.voyage_number', 'p.name as port', 'd.da_type', 'd.status', 'd.currency', 'd.total_amount', 'd.base_amount'])
            ->map(fn ($r) => [
                'da' => $r->da_number, 'voyage' => $r->voyage_number, 'port' => $r->port, 'type' => $r->da_type, 'status' => $r->status,
                'currency' => $r->currency, 'amount' => Decimal::round((string) $r->total_amount, 2), 'base_amount' => Decimal::round((string) $r->base_amount, 2),
            ])->all();

        return [[
            $this->col('da', 'Port DA'), $this->col('voyage', 'Voyage'), $this->col('port', 'Port'), $this->col('type', 'Type'), $this->col('status', 'Status'),
            $this->col('currency', 'Currency'), $this->col('amount', 'Amount', 'right'), $this->col('base_amount', 'Base amount', 'right'),
        ], $rows];
    }

    /**
     * Revenue is shown only with contracts.rates.view (OA-14), as in the activity screens.
     *
     * @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>}
     */
    private function offshoreActivities(array $f, User $actor): array
    {
        $showRevenue = $actor->can(Permission::ContractsRatesView->value);
        $rows = DB::table('offshore_activities as a')->leftJoin('vessels as x', 'x.id', '=', 'a.vessel_id')->leftJoin('voyages as v', 'v.id', '=', 'a.voyage_id')
            ->leftJoin('offshore_activity_types as t', 't.id', '=', 'a.offshore_activity_type_id')->whereNull('a.deleted_at')
            ->when(! empty($f['voyage_id']), fn ($q) => $q->where('a.voyage_id', (int) $f['voyage_id']))
            ->when(! empty($f['status']), fn ($q) => $q->where('a.status', (string) $f['status']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('a.start_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('a.start_at', '<=', $f['to']))
            ->orderBy('a.start_at')
            ->get(['a.activity_number', 'x.name as vessel', 'v.voyage_number', 't.name as type', 'a.start_at', 'a.billable_hours', 'a.non_billable_hours', 'a.standby_hours', 'a.currency', 'a.revenue_amount', 'a.status'])
            ->map(function ($r) use ($showRevenue) {
                $row = [
                    'activity' => $r->activity_number, 'vessel' => $r->vessel, 'voyage' => $r->voyage_number, 'type' => $r->type, 'date' => Carbon::parse($r->start_at)->toDateString(),
                    'billable' => Decimal::round((string) ($r->billable_hours ?? '0'), 2), 'non_billable' => Decimal::round((string) ($r->non_billable_hours ?? '0'), 2),
                    'standby' => Decimal::round((string) ($r->standby_hours ?? '0'), 2), 'status' => $r->status,
                ];
                if ($showRevenue) {
                    $row['currency'] = $r->currency;
                    $row['revenue'] = $r->revenue_amount !== null ? Decimal::round((string) $r->revenue_amount, 2) : null;
                }

                return $row;
            })->all();

        $columns = [
            $this->col('activity', 'Activity'), $this->col('vessel', 'Vessel'), $this->col('voyage', 'Voyage'), $this->col('type', 'Type'), $this->col('date', 'Date'),
            $this->col('billable', 'Billable h', 'right'), $this->col('non_billable', 'Non-billable h', 'right'), $this->col('standby', 'Standby h', 'right'), $this->col('status', 'Status'),
        ];
        if ($showRevenue) {
            $columns[] = $this->col('currency', 'Currency');
            $columns[] = $this->col('revenue', 'Revenue', 'right');
        }

        return [$columns, $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function laytimeDemurrage(array $f): array
    {
        $rows = DB::table('laytime_calculations as l')->join('voyages as v', 'v.id', '=', 'l.voyage_id')->leftJoin('port_calls as pc', 'pc.id', '=', 'l.port_call_id')
            ->leftJoin('ports as p', 'p.id', '=', 'pc.port_id')->whereNull('l.deleted_at')
            ->when(! empty($f['voyage_id']), fn ($q) => $q->where('l.voyage_id', (int) $f['voyage_id']))
            ->when(! empty($f['status']), fn ($q) => $q->where('l.status', (string) $f['status']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('l.created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('l.created_at', '<=', $f['to']))
            ->orderBy('l.id')
            ->get(['v.voyage_number', 'p.name as port', 'l.calculation_type', 'l.allowed_hours', 'l.used_hours', 'l.difference_hours', 'l.currency', 'l.demurrage_amount', 'l.despatch_amount', 'l.status'])
            ->map(fn ($r) => [
                'voyage' => $r->voyage_number, 'port' => $r->port, 'type' => $r->calculation_type,
                'allowed' => $r->allowed_hours !== null ? Decimal::round((string) $r->allowed_hours, 2) : null, 'used' => $r->used_hours !== null ? Decimal::round((string) $r->used_hours, 2) : null,
                'difference' => $r->difference_hours !== null ? Decimal::round((string) $r->difference_hours, 2) : null, 'currency' => $r->currency,
                'demurrage' => $r->demurrage_amount !== null ? Decimal::round((string) $r->demurrage_amount, 2) : null,
                'despatch' => $r->despatch_amount !== null ? Decimal::round((string) $r->despatch_amount, 2) : null, 'status' => $r->status,
            ])->all();

        return [[
            $this->col('voyage', 'Voyage'), $this->col('port', 'Port'), $this->col('type', 'Type'), $this->col('allowed', 'Allowed h', 'right'), $this->col('used', 'Used h', 'right'),
            $this->col('difference', 'Difference h', 'right'), $this->col('currency', 'Currency'), $this->col('demurrage', 'Demurrage', 'right'), $this->col('despatch', 'Despatch', 'right'), $this->col('status', 'Status'),
        ], $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function charteringActivity(array $f): array
    {
        $rows = [];
        $enquiries = Enquiry::query()->with('charterer:id,legal_name')->withCount(['estimations', 'offers'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', (string) $f['status']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('received_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('received_at', '<=', $f['to']))
            ->orderByDesc('received_at')->get();
        foreach ($enquiries as $e) {
            $rows[] = [
                'enquiry' => $e->enquiry_number, 'received' => $e->received_at?->toDateString(), 'type' => $e->business_type, 'charterer' => $e->charterer?->legal_name,
                'estimations' => (string) $e->estimations_count, 'offers' => (string) $e->offers_count, 'status' => $e->status,
            ];
        }

        return [[
            $this->col('enquiry', 'Enquiry'), $this->col('received', 'Received'), $this->col('type', 'Type'), $this->col('charterer', 'Charterer'),
            $this->col('estimations', 'Estimations', 'right'), $this->col('offers', 'Offers', 'right'), $this->col('status', 'Status'),
        ], $rows];
    }

    /** @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>} */
    private function outstandingInvoices(array $f, int $fetch): array
    {
        $asOf = ! empty($f['as_of']) ? Carbon::parse($f['as_of'])->toDateString() : Carbon::today()->toDateString();
        $rows = DB::table('invoices as i')->leftJoin('companies as c', 'c.id', '=', 'i.customer_company_id')->whereNull('i.deleted_at')
            ->whereIn('i.status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value, InvoiceStatus::Overdue->value])
            ->where('i.balance', '>', 0)
            ->when(! empty($f['customer_company_id']), fn ($q) => $q->where('i.customer_company_id', (int) $f['customer_company_id']))
            ->orderBy('i.due_date')->orderBy('i.id')->limit($fetch)
            ->selectRaw('i.invoice_number, c.legal_name AS customer, i.due_date, i.currency, i.balance, ROUND(i.balance * i.fx_rate, 2) AS base_balance, GREATEST(0, DATEDIFF(?, i.due_date)) AS days_overdue', [$asOf])
            ->get()->map(fn ($i) => [
                'invoice' => $i->invoice_number, 'customer' => $i->customer, 'due_date' => (string) $i->due_date, 'currency' => $i->currency,
                'balance' => (string) $i->balance, 'base_balance' => Decimal::round((string) $i->base_balance, 2), 'days_overdue' => (string) $i->days_overdue,
            ])->all();

        return [[
            $this->col('invoice', 'Invoice'), $this->col('customer', 'Customer'), $this->col('due_date', 'Due date'), $this->col('currency', 'Currency'),
            $this->col('balance', 'Balance', 'right'), $this->col('base_balance', 'Base balance', 'right'), $this->col('days_overdue', 'Days overdue', 'right'),
        ], $rows];
    }

    /**
     * @param  list<string>  $statuses
     * @return array{0: list<array<string, string>>, 1: list<array<string, string|null>>}
     */
    private function lines(string $table, string $categoryTable, string $categoryColumn, array $f, array $statuses, int $fetch): array
    {
        $q = DB::table("{$table} as l")->leftJoin('voyages as v', 'v.id', '=', 'l.voyage_id')->leftJoin("{$categoryTable} as c", 'c.id', '=', "l.{$categoryColumn}")
            ->whereNull('l.deleted_at')->where('l.is_estimate', false)
            ->when(! empty($f['status']), fn ($q) => $q->where('l.status', (string) $f['status']), fn ($q) => $q->whereIn('l.status', $statuses))
            ->when(! empty($f['voyage_id']), fn ($q) => $q->where('l.voyage_id', (int) $f['voyage_id']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('l.created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('l.created_at', '<=', $f['to']))
            ->orderBy('l.id')->limit($fetch)
            ->get(['l.id', 'l.description', 'l.currency', 'l.amount', 'l.base_amount', 'l.status', 'l.created_at', 'v.voyage_number', 'c.name as category']);

        $rows = $q->map(fn ($r) => [
            'date' => Carbon::parse($r->created_at)->toDateString(), 'voyage' => $r->voyage_number, 'category' => $r->category, 'description' => $r->description,
            'currency' => $r->currency, 'amount' => Decimal::round((string) $r->amount, 2), 'base_amount' => Decimal::round((string) $r->base_amount, 2), 'status' => $r->status,
        ])->all();

        return [[
            $this->col('date', 'Date'), $this->col('voyage', 'Voyage'), $this->col('category', 'Category'), $this->col('description', 'Description'),
            $this->col('currency', 'Currency'), $this->col('amount', 'Amount', 'right'), $this->col('base_amount', 'Base amount', 'right'), $this->col('status', 'Status'),
        ], $rows];
    }

    /** @return array{key: string, label: string, align: string} */
    private function col(string $key, string $label, string $align = 'left'): array
    {
        return ['key' => $key, 'label' => $label, 'align' => $align];
    }

    private function authorizeView(User $actor): void
    {
        if (! $actor->can(Permission::ReportsView->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
