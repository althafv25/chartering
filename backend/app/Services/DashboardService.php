<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PayableStatus;
use App\Enums\Permission;
use App\Models\Contract;
use App\Models\Estimation;
use App\Models\Fixture;
use App\Models\Invoice;
use App\Models\Payable;
use App\Models\User;
use App\Models\Vessel;
use App\Models\Voyage;
use App\Models\VoyageExpense;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard KPIs. Operational counts need only dashboard.view; money figures are
 * returned only with commercial.financials.view (absent key = not permitted, never 0).
 * Pending approvals are counted only for the areas the user may approve.
 */
class DashboardService
{
    private const OPEN_VOYAGE_EXCLUDED = ['draft', 'completed', 'finalized', 'cancelled'];

    /** @return array<string, mixed> */
    public function summary(User $actor): array
    {
        $base = (string) config('offshore.base_currency');
        $out = [
            'base_currency' => $base,
            'active_vessels' => Vessel::query()->where('status', 'active')->count(),
            'active_voyages' => Voyage::query()->whereNotIn('status', self::OPEN_VOYAGE_EXCLUDED)->count(),
            'pending_approvals' => $this->pendingApprovals($actor),
        ];

        if ($actor->can(Permission::FinancialsView->value)) {
            $today = Carbon::today()->toDateString();
            // One pass over the open documents (conditional sums) instead of a scan per figure.
            $inv = DB::table('invoices')->whereNull('deleted_at')
                ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value, InvoiceStatus::Overdue->value])->where('balance', '>', 0)
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(ROUND(balance * fx_rate, 2)), 0) AS total, COALESCE(SUM(due_date < ?), 0) AS overdue_n, COALESCE(SUM(CASE WHEN due_date < ? THEN ROUND(balance * fx_rate, 2) ELSE 0 END), 0) AS overdue_total', [$today, $today])
                ->first();
            $out['outstanding_invoices'] = [
                'count' => (int) $inv->n, 'base_total' => Decimal::round((string) $inv->total, 2),
                'overdue_count' => (int) $inv->overdue_n, 'overdue_base_total' => Decimal::round((string) $inv->overdue_total, 2),
            ];
            $pay = DB::table('payables')->whereNull('deleted_at')->whereIn('status', [PayableStatus::Approved->value, PayableStatus::PartiallyPaid->value])->where('balance', '>', 0)
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(ROUND(balance * fx_rate, 2)), 0) AS total, COALESCE(SUM(due_date < ?), 0) AS overdue_n', [$today])->first();
            $out['open_payables'] = ['count' => (int) $pay->n, 'base_total' => Decimal::round((string) $pay->total, 2), 'overdue_count' => (int) $pay->overdue_n];
        }

        return $out;
    }

    /** @return array<string, int> area => count, only for areas the user can approve */
    private function pendingApprovals(User $actor): array
    {
        $areas = [
            'estimations' => [Permission::EstimationsApprove, fn () => Estimation::query()->where('status', 'submitted')->count()],
            'fixtures' => [Permission::FixturesApprove, fn () => Fixture::query()->where('status', 'submitted')->count()],
            'contracts' => [Permission::ContractsApprove, fn () => Contract::query()->where('status', 'under_review')->count()],
            'invoices' => [Permission::InvoicesApprove, fn () => Invoice::query()->where('status', 'submitted')->count()],
            'expenses' => [Permission::ExpensesApprove, fn () => VoyageExpense::query()->where('status', 'confirmed')->count()],
            'payables' => [Permission::PayablesApprove, fn () => Payable::query()->where('status', 'draft')->count()],
        ];
        $out = [];
        foreach ($areas as $key => [$permission, $count]) {
            if ($actor->can($permission->value)) {
                $out[$key] = $count();
            }
        }

        return $out;
    }
}
