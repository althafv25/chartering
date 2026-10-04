<?php

namespace Tests\Unit\Services\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payable;
use App\Models\User;
use App\Services\Finance\BalancingService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PayableService;
use App\Services\Finance\PaymentService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BalancingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BalancingService $service;

    private InvoiceService $invoices;

    private PayableService $payables;

    private User $financeUser;

    private Company $customer;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);
        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);

        $this->service = app(BalancingService::class);
        $this->invoices = app(InvoiceService::class);
        $this->payables = app(PayableService::class);
        $this->financeUser = $this->userWithRole(UserRole::Finance);
        $this->customer = Company::query()->create(['code' => 'C1', 'legal_name' => 'Gulf Charterers', 'normalized_name' => 'gulf charterers']);
        $this->supplier = Company::query()->create(['code' => 'S1', 'legal_name' => 'Agency Services LLC', 'normalized_name' => 'agency services llc']);
    }

    private function issuedInvoice(string $amount, string $dueDate): Invoice
    {
        $invoice = $this->invoices->create([
            'invoice_type' => 'freight', 'customer_company_id' => $this->customer->id,
            'issue_date' => '2026-10-01', 'due_date' => $dueDate, 'currency' => 'USD',
        ], $this->financeUser);
        $this->invoices->saveLines($invoice, [['description' => 'Freight', 'amount' => $amount]], $this->financeUser);

        return $this->invoices->issue(Invoice::query()->findOrFail($invoice->id), $this->financeUser);
    }

    private function approvedPayable(string $subtotal, string $dueDate): Payable
    {
        $otherUser = $this->userWithRole(UserRole::Finance);
        $payable = $this->payables->create([
            'supplier_company_id' => $this->supplier->id, 'supplier_invoice_ref' => 'SUP-'.uniqid(),
            'issue_date' => '2026-10-01', 'due_date' => $dueDate, 'currency' => 'USD', 'subtotal' => $subtotal, 'tax' => '0',
        ], $this->financeUser);

        return $this->payables->approve(Payable::query()->findOrFail($payable->id), $otherUser);
    }

    public function test_accounts_groups_receivable_and_payable_by_company(): void
    {
        $this->issuedInvoice('1000.00', '2026-10-15');
        $this->issuedInvoice('500.00', '2026-11-01');
        $this->approvedPayable('300.00', '2026-10-20');

        $result = $this->service->accounts($this->financeUser);
        $this->assertCount(2, $result['accounts']);

        $customerRow = collect($result['accounts'])->firstWhere('company_id', $this->customer->id);
        $this->assertSame('1500.00', $customerRow['receivable']);
        $this->assertSame('0.00', $customerRow['payable']);
        $this->assertSame('1500.00', $customerRow['net']);
        $this->assertSame(2, $customerRow['receivable_count']);

        $supplierRow = collect($result['accounts'])->firstWhere('company_id', $this->supplier->id);
        $this->assertSame('0.00', $supplierRow['receivable']);
        $this->assertSame('300.00', $supplierRow['payable']);
        $this->assertSame('-300.00', $supplierRow['net']);

        $this->assertSame('1500.00', $result['totals']['receivable']);
        $this->assertSame('300.00', $result['totals']['payable']);
        $this->assertSame('1200.00', $result['totals']['net']);
    }

    public function test_accounts_excludes_fully_paid_invoices_and_draft_payables(): void
    {
        $invoice = $this->issuedInvoice('1000.00', '2026-10-15');
        // Fully allocate so balance = 0 — should drop out of the receivable view.
        app(PaymentService::class)->allocate(
            app(PaymentService::class)->create(['direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05', 'amount' => '1000.00', 'currency' => 'USD'], $this->financeUser),
            [['invoice_id' => $invoice->id, 'amount' => '1000.00']],
            $this->financeUser,
        );
        $this->payables->create([
            'supplier_company_id' => $this->supplier->id, 'supplier_invoice_ref' => 'SUP-DRAFT',
            'issue_date' => '2026-10-01', 'due_date' => '2026-10-20', 'currency' => 'USD', 'subtotal' => '999.00', 'tax' => '0',
        ], $this->financeUser);

        $result = $this->service->accounts($this->financeUser);
        $this->assertCount(0, $result['accounts']);
    }

    public function test_cash_flow_buckets_by_due_period_overdue_and_beyond(): void
    {
        Carbon::setTestNow('2026-10-10');
        $this->issuedInvoice('100.00', '2026-10-05'); // overdue (before today)
        $this->issuedInvoice('200.00', '2026-10-20'); // this month
        $this->issuedInvoice('300.00', '2026-11-15'); // next month
        $this->approvedPayable('50.00', '2027-06-01'); // beyond the default 6-month window

        $result = $this->service->cashFlow($this->financeUser);
        $byPeriod = collect($result['periods'])->keyBy('period');

        $this->assertSame('100.00', $byPeriod['overdue']['receivable']);
        $this->assertSame('200.00', $byPeriod['2026-10']['receivable']);
        $this->assertSame('300.00', $byPeriod['2026-11']['receivable']);
        $this->assertSame('50.00', $byPeriod['beyond']['payable']);
        $this->assertSame('600.00', $result['totals']['receivable']);
        $this->assertSame('50.00', $result['totals']['payable']);

        Carbon::setTestNow();
    }

    public function test_cash_flow_respects_explicit_from_and_to(): void
    {
        $this->issuedInvoice('100.00', '2026-12-15');

        $result = $this->service->cashFlow($this->financeUser, ['from' => '2026-12-01', 'to' => '2026-12-31']);
        $this->assertSame('2026-12-01', $result['from']);
        $byPeriod = collect($result['periods'])->keyBy('period');
        $this->assertCount(3, $byPeriod); // overdue, 2026-12, beyond
        $this->assertSame('100.00', $byPeriod['2026-12']['receivable']);
    }

    public function test_filters_by_company_id(): void
    {
        $this->issuedInvoice('1000.00', '2026-10-15');
        $this->approvedPayable('300.00', '2026-10-20');

        $result = $this->service->accounts($this->financeUser, ['company_id' => $this->customer->id]);
        $this->assertCount(1, $result['accounts']);
        $this->assertSame($this->customer->id, $result['accounts'][0]['company_id']);
    }
}
