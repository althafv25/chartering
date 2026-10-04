<?php

namespace Tests\Unit\Services\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\AgingService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use Carbon\Carbon;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AS_OF = '2026-10-03';

    private AgingService $service;

    private InvoiceService $invoices;

    private User $financeUser;

    private Company $customerA;

    private Company $customerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);

        ExchangeRate::query()->create(['rate_date' => self::AS_OF, 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);

        $this->service = app(AgingService::class);
        $this->invoices = app(InvoiceService::class);
        $this->financeUser = $this->userWithRole(UserRole::Finance);
        $this->customerA = Company::query()->create(['code' => 'CA', 'legal_name' => 'Gulf Shipping Co', 'normalized_name' => 'gulf shipping co']);
        $this->customerB = Company::query()->create(['code' => 'CB', 'legal_name' => 'Atlas Marine LLC', 'normalized_name' => 'atlas marine llc']);
    }

    /** Creates and issues an invoice for the given customer, due date and amount. */
    private function issuedInvoice(Company $customer, string $dueDate, string $amount): Invoice
    {
        $invoice = $this->invoices->create([
            'invoice_type' => 'freight',
            'customer_company_id' => $customer->id,
            'issue_date' => '2026-09-01',
            'due_date' => $dueDate,
            'currency' => 'USD',
        ], $this->financeUser);

        $this->invoices->saveLines($invoice, [
            ['description' => 'Freight', 'amount' => $amount],
        ], $this->financeUser);

        return $this->invoices->issue(Invoice::query()->findOrFail($invoice->id), $this->financeUser);
    }

    public function test_buckets_invoices_by_days_overdue_per_f7(): void
    {
        // current: due in the future relative to as_of
        $this->issuedInvoice($this->customerA, '2026-10-10', '100.00');
        // current: due exactly on as_of
        $this->issuedInvoice($this->customerA, self::AS_OF, '50.00');
        // 1-30: due 13 days before as_of (2026-09-20)
        $this->issuedInvoice($this->customerA, '2026-09-20', '200.00');
        // 31-60: due 45 days before as_of (2026-08-19)
        $this->issuedInvoice($this->customerA, '2026-08-19', '300.00');
        // 61-90: due 75 days before as_of (2026-07-20)
        $this->issuedInvoice($this->customerA, '2026-07-20', '400.00');
        // over_90: due 120 days before as_of (2026-06-05)
        $this->issuedInvoice($this->customerA, '2026-06-05', '500.00');

        $report = $this->service->report($this->financeUser, self::AS_OF);

        $this->assertSame(self::AS_OF, $report['as_of']);
        $this->assertSame('USD', $report['base_currency']);
        $this->assertCount(1, $report['customers']);

        $customer = $report['customers'][0];
        $this->assertSame($this->customerA->id, $customer['customer_company_id']);
        $this->assertSame('Gulf Shipping Co', $customer['customer_name']);
        $this->assertSame('150.00', $customer['buckets']['current']); // 100 + 50
        $this->assertSame('200.00', $customer['buckets']['1_30']);
        $this->assertSame('300.00', $customer['buckets']['31_60']);
        $this->assertSame('400.00', $customer['buckets']['61_90']);
        $this->assertSame('500.00', $customer['buckets']['over_90']);
        $this->assertSame('1550.00', $customer['total']);

        $this->assertSame('150.00', $report['totals']['current']);
        $this->assertSame('200.00', $report['totals']['1_30']);
        $this->assertSame('300.00', $report['totals']['31_60']);
        $this->assertSame('400.00', $report['totals']['61_90']);
        $this->assertSame('500.00', $report['totals']['over_90']);
        $this->assertSame('1550.00', $report['totals']['grand_total']);

        $invoiceEntries = $customer['invoices'];
        $this->assertCount(6, $invoiceEntries);
        $overNinety = array_values(array_filter($invoiceEntries, fn ($i) => $i['bucket'] === 'over_90'))[0];
        $this->assertSame(120, $overNinety['days_overdue']);
        $this->assertSame('500.00', $overNinety['balance']);
        $this->assertSame('500.00', $overNinety['base_balance']);
    }

    public function test_groups_by_customer_and_sorts_by_total_descending(): void
    {
        $this->issuedInvoice($this->customerA, '2026-09-20', '100.00');
        $this->issuedInvoice($this->customerB, '2026-09-20', '900.00');

        $report = $this->service->report($this->financeUser, self::AS_OF);

        $this->assertCount(2, $report['customers']);
        $this->assertSame($this->customerB->id, $report['customers'][0]['customer_company_id']);
        $this->assertSame('900.00', $report['customers'][0]['total']);
        $this->assertSame($this->customerA->id, $report['customers'][1]['customer_company_id']);
        $this->assertSame('100.00', $report['customers'][1]['total']);
        $this->assertSame('1000.00', $report['totals']['grand_total']);
    }

    public function test_fully_paid_invoices_are_excluded(): void
    {
        $invoice = $this->issuedInvoice($this->customerA, '2026-09-20', '100.00');

        $payment = app(PaymentService::class)->create([
            'direction' => 'received',
            'company_id' => $this->customerA->id,
            'payment_date' => self::AS_OF,
            'amount' => '100.00',
            'currency' => 'USD',
            'method' => 'wire',
        ], $this->financeUser);

        app(PaymentService::class)->allocate($payment, [
            ['invoice_id' => $invoice->id, 'amount' => '100.00'],
        ], $this->financeUser);

        $this->assertSame('0.00', Invoice::query()->findOrFail($invoice->id)->balance);

        $report = $this->service->report($this->financeUser, self::AS_OF);

        $this->assertCount(0, $report['customers']);
        $this->assertSame('0.00', $report['totals']['grand_total']);
    }

    public function test_draft_submitted_and_approved_invoices_are_excluded(): void
    {
        // draft (never issued)
        $draft = $this->invoices->create([
            'invoice_type' => 'freight',
            'customer_company_id' => $this->customerA->id,
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-20',
            'currency' => 'USD',
        ], $this->financeUser);
        $this->invoices->saveLines($draft, [['description' => 'Freight', 'amount' => '100.00']], $this->financeUser);

        // submitted
        $submittedBase = $this->invoices->create([
            'invoice_type' => 'freight',
            'customer_company_id' => $this->customerA->id,
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-20',
            'currency' => 'USD',
        ], $this->financeUser);
        $this->invoices->saveLines($submittedBase, [['description' => 'Freight', 'amount' => '200.00']], $this->financeUser);
        $this->invoices->submit($submittedBase, $this->financeUser);

        // approved
        $approvedBase = $this->invoices->create([
            'invoice_type' => 'freight',
            'customer_company_id' => $this->customerA->id,
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-20',
            'currency' => 'USD',
        ], $this->financeUser);
        $this->invoices->saveLines($approvedBase, [['description' => 'Freight', 'amount' => '300.00']], $this->financeUser);
        $this->invoices->submit($approvedBase, $this->financeUser);
        $approver = $this->userWithRole(UserRole::Finance);
        $this->invoices->approve(Invoice::query()->findOrFail($approvedBase->id), $approver);

        $report = $this->service->report($this->financeUser, self::AS_OF);

        $this->assertCount(0, $report['customers']);
        $this->assertSame('0.00', $report['totals']['grand_total']);
    }

    public function test_as_of_defaults_to_today_when_omitted(): void
    {
        $this->travelTo(Carbon::parse(self::AS_OF));
        $this->issuedInvoice($this->customerA, '2026-09-20', '100.00');

        $report = $this->service->report($this->financeUser);

        $this->assertSame(self::AS_OF, $report['as_of']);
    }
}
