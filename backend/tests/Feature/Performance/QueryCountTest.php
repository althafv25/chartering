<?php

namespace Tests\Feature\Performance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\RevenueCategory;
use App\Models\VoyageExpense;
use App\Models\VoyageRevenue;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * N+1 guard (docs/11 performance): list, report and dashboard endpoints must run the same number of
 * queries for 2 rows as for 10. A relation added to a resource without eager loading fails here.
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);
        ExchangeRate::query()->create(['rate_date' => now()->toDateString(), 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.6725', 'source' => 'manual']);
        Sanctum::actingAs($this->userWithRole(UserRole::Finance));
    }

    private function addRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $n = ++$this->seq;
            $company = Company::query()->create(['code' => "C{$n}", 'legal_name' => "Company {$n}", 'normalized_name' => "company {$n}"]);
            Invoice::query()->create([
                'invoice_number' => "INV-T-{$n}", 'invoice_type' => 'freight', 'customer_company_id' => $company->id, 'billing_snapshot' => ['name' => $company->legal_name],
                'issue_date' => now()->toDateString(), 'due_date' => now()->addDays($n)->toDateString(), 'currency' => 'USD', 'fx_rate' => '1',
                'subtotal' => '100.00', 'tax_amount' => '0.00', 'total' => '100.00', 'base_total' => '100.00', 'status' => 'issued',
            ]);
            Payable::query()->create([
                'payable_number' => "PAYB-T-{$n}", 'supplier_company_id' => $company->id, 'supplier_invoice_ref' => "S{$n}", 'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays($n)->toDateString(), 'currency' => 'USD', 'fx_rate' => '1', 'subtotal' => '50.00', 'tax' => '0.00', 'total' => '50.00',
                'base_total' => '50.00', 'status' => 'approved',
            ]);
            Payment::query()->create([
                'payment_number' => "PAY-T-{$n}", 'direction' => 'received', 'company_id' => $company->id, 'payment_date' => now()->toDateString(), 'amount' => '10.00',
                'currency' => 'USD', 'fx_rate' => '1', 'base_amount' => '10.00', 'unallocated_amount' => '10.00',
            ]);
            VoyageRevenue::query()->create([
                'revenue_category_id' => RevenueCategory::query()->value('id'), 'description' => "Rev {$n}", 'currency' => 'USD', 'fx_rate' => '1',
                'amount' => '10.00', 'base_amount' => '10.00', 'status' => 'confirmed',
            ]);
            VoyageExpense::query()->create([
                'expense_category_id' => ExpenseCategory::query()->value('id'), 'description' => "Exp {$n}", 'currency' => 'USD', 'fx_rate' => '1',
                'amount' => '5.00', 'base_amount' => '5.00', 'status' => 'confirmed', 'supplier_company_id' => $company->id,
            ]);
        }
    }

    private function queryCount(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string, array{0: string}> */
    public static function endpoints(): array
    {
        return array_map(fn (string $u) => [$u], [
            'invoices' => '/api/v1/invoices?per_page=50',
            'payables' => '/api/v1/payables?per_page=50',
            'payments' => '/api/v1/payments?per_page=50',
            'revenues' => '/api/v1/voyage-revenues?per_page=50',
            'expenses' => '/api/v1/voyage-expenses?per_page=50',
            'aging' => '/api/v1/receivables/aging',
            'balancing accounts' => '/api/v1/balancing/accounts',
            'cash flow' => '/api/v1/balancing/cash-flow',
            'dashboard' => '/api/v1/dashboard',
            'report outstanding invoices' => '/api/v1/reports/outstanding-invoices',
            'report revenue' => '/api/v1/reports/revenue',
            'report expenses' => '/api/v1/reports/expenses',
            'statistics' => '/api/v1/statistics/revenue?group_by=category',
        ]);
    }

    /** @dataProvider endpoints */
    #[DataProvider('endpoints')]
    public function test_query_count_does_not_grow_with_the_number_of_rows(string $url): void
    {
        $this->addRows(2);
        $this->queryCount($url);                 // warm caches (settings, permissions)
        $small = $this->queryCount($url);

        $this->addRows(8);
        $large = $this->queryCount($url);

        $this->assertSame($small, $large, "{$url} ran {$small} queries for 2 rows but {$large} for 10 (N+1?)");
    }
}
