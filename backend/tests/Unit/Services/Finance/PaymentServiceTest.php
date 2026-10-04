<?php

namespace Tests\Unit\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $service;

    private InvoiceService $invoices;

    private User $financeUser;

    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);

        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);
        ExchangeRate::query()->create(['rate_date' => '2026-10-05', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.68000000', 'source' => 'manual']);

        $this->service = app(PaymentService::class);
        $this->invoices = app(InvoiceService::class);
        $this->financeUser = $this->userWithRole(UserRole::Finance);
        $this->customer = Company::query()->create(['code' => 'C1', 'legal_name' => 'Gulf Charterers', 'normalized_name' => 'gulf charterers']);
    }

    private function issuedInvoice(string $currency = 'USD', string $amount = '1000.00', string $issueDate = '2026-10-01'): Invoice
    {
        $invoice = $this->invoices->create([
            'invoice_type' => 'freight', 'customer_company_id' => $this->customer->id,
            'issue_date' => $issueDate, 'due_date' => '2026-10-31', 'currency' => $currency,
        ], $this->financeUser);
        $this->invoices->saveLines($invoice, [['description' => 'Freight', 'amount' => $amount]], $this->financeUser);

        return $this->invoices->issue(Invoice::query()->findOrFail($invoice->id), $this->financeUser);
    }

    public function test_create_snapshots_fx_f1(): void
    {
        $payment = $this->service->create([
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-01',
            'amount' => '1000.00', 'currency' => 'AED',
        ], $this->financeUser);

        $this->assertSame('0.27229408', $payment->fx_rate); // inverse of USD->AED
        $this->assertSame('272.29', $payment->base_amount);
        $this->assertSame('1000.00', $payment->unallocated_amount);
        $this->assertMatchesRegularExpression('/^PAY-2026-\d{5}$/', $payment->payment_number);
    }

    public function test_allocate_same_currency_updates_invoice_status_pay01_pay02(): void
    {
        $invoice = $this->issuedInvoice('USD', '1000.00');
        $payment = $this->service->create([
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05',
            'amount' => '1000.00', 'currency' => 'USD',
        ], $this->financeUser);

        $allocations = $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '400.00']], $this->financeUser);
        $this->assertCount(1, $allocations);
        $this->assertSame('400.00', $allocations[0]->invoice_ccy_amount);
        $this->assertSame('0.00', $allocations[0]->fx_difference_base);

        $invoice->refresh();
        $this->assertSame('400.00', $invoice->amount_paid);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);

        $payment->refresh();
        $this->assertSame('600.00', $payment->unallocated_amount);

        $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '600.00']], $this->financeUser);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame('0.00', $payment->refresh()->unallocated_amount);
    }

    public function test_allocate_cross_currency_computes_fx_difference_f4(): void
    {
        // Invoice is in AED (fx snapshot at issue date 2026-10-01: inverse 0.27229408 USD per AED => stored invoice.fx_rate is USD->AED resolve, i.e amount AED -> base USD).
        $invoice = $this->issuedInvoice('AED', '3680.00', '2026-10-01');
        // Payment received in USD, snapshotted at a later date with a different USD->AED rate.
        $payment = $this->service->create([
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05',
            'amount' => '1000.00', 'currency' => 'USD',
        ], $this->financeUser);

        $allocations = $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '1000.00']], $this->financeUser);
        $allocation = $allocations[0];

        // pay(USD)->AED at payment date (2026-10-05 rate 3.68): 1000 * 3.68 = 3680.00
        $this->assertSame('3680.00', $allocation->invoice_ccy_amount);
        // allocated_base_at_payment_rate = 1000 * payment.fx_rate (USD->USD = 1) = 1000.00
        // invoice_ccy_amount_base_at_invoice_rate = 3680 * invoice.fx_rate (AED->USD inverse of 3.6725)
        $invoice->refresh();
        $expectedInvoiceCcyBase = bcmul('3680.00', (string) $invoice->fx_rate, 8);
        $expectedDifference = bcsub('1000.00', bcadd($expectedInvoiceCcyBase, '0', 2), 2);
        $this->assertSame(round((float) $expectedDifference, 2), round((float) $allocation->fx_difference_base, 2));
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
    }

    public function test_allocation_cannot_exceed_payment_amount_pay01(): void
    {
        $invoice = $this->issuedInvoice('USD', '1000.00');
        $payment = $this->service->create([
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05',
            'amount' => '500.00', 'currency' => 'USD',
        ], $this->financeUser);

        $this->expectException(BusinessRuleException::class);
        $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '600.00']], $this->financeUser);
    }

    public function test_allocation_cannot_exceed_invoice_balance(): void
    {
        $invoice = $this->issuedInvoice('USD', '500.00');
        $payment = $this->service->create([
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05',
            'amount' => '1000.00', 'currency' => 'USD',
        ], $this->financeUser);

        $this->expectException(BusinessRuleException::class);
        $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '600.00']], $this->financeUser);
    }

    public function test_duplicate_guard_requires_confirmation_pay03(): void
    {
        $data = [
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05',
            'amount' => '750.00', 'currency' => 'USD', 'bank_reference' => 'REF-001',
        ];
        $this->service->create($data, $this->financeUser);

        $this->expectException(BusinessRuleException::class);
        $this->service->create($data, $this->financeUser);
    }

    public function test_duplicate_guard_bypassed_with_confirm_flag(): void
    {
        $data = [
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05',
            'amount' => '750.00', 'currency' => 'USD', 'bank_reference' => 'REF-002',
        ];
        $this->service->create($data, $this->financeUser);
        $second = $this->service->create([...$data, 'confirm_duplicate' => true], $this->financeUser);

        $this->assertNotNull($second->id);
    }

    public function test_reverse_undoes_allocations_and_marks_reversed(): void
    {
        $invoice = $this->issuedInvoice('USD', '1000.00');
        $payment = $this->service->create([
            'direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05',
            'amount' => '1000.00', 'currency' => 'USD',
        ], $this->financeUser);
        $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '1000.00']], $this->financeUser);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);

        $reversed = $this->service->reverse(Payment::query()->findOrFail($payment->id), $this->financeUser, 'bounced cheque');

        $this->assertSame(PaymentStatus::Reversed, $reversed->status);
        $this->assertSame('1000.00', $reversed->unallocated_amount);
        $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
        $this->assertSame('0.00', $invoice->amount_paid);
    }
}
