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
use App\Services\Finance\PayableService;
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

    public function test_cross_currency_limits_use_the_target_currency_and_roll_back_the_whole_batch(): void
    {
        ExchangeRate::query()->create(['rate_date' => '2026-10-05', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.20000000', 'source' => 'manual']);
        $payables = app(PayableService::class);
        $payable = $payables->create(['supplier_company_id' => $this->customer->id, 'supplier_invoice_ref' => 'FX-1', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31', 'currency' => 'USD', 'subtotal' => '100'], $this->financeUser);
        $payable = $payables->approve($payable, $this->userWithRole(UserRole::Finance));

        foreach ([['invoice_id', $this->issuedInvoice('USD', '100'), 'received'], ['payable_id', $payable, 'paid']] as [$key, $target, $direction]) {
            $payment = $this->service->create(['direction' => $direction, 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05', 'amount' => '100', 'currency' => 'EUR'], $this->financeUser);
            try {
                // First row fits, second would make USD 108 against USD 100.
                $this->service->allocate($payment, [[$key => $target->id, 'amount' => '50'], [$key => $target->id, 'amount' => '40']], $this->financeUser);
                $this->fail('Cross-currency over-allocation was accepted.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('allocation_exceeds_balance', $e->errorCode);
            }
            $this->assertSame('0.00', $target->refresh()->amount_paid);
            $this->assertSame('100.00', $payment->refresh()->unallocated_amount);
            $this->assertSame(0, $payment->allocations()->count());
            $this->service->allocate($payment, [[$key => $target->id, 'amount' => '50']], $this->financeUser);
            $this->assertSame('40.00', $target->refresh()->balance);
        }
    }

    public function test_allocations_require_one_target_with_matching_company_and_direction(): void
    {
        $invoice = $this->issuedInvoice('USD', '100');
        $other = Company::query()->create(['code' => 'OTHER', 'legal_name' => 'Other', 'normalized_name' => 'other']);
        foreach ([['paid', $this->customer->id], ['received', $other->id]] as [$direction, $company]) {
            $payment = $this->service->create(['direction' => $direction, 'company_id' => $company, 'payment_date' => '2026-10-05', 'amount' => '100', 'currency' => 'USD'], $this->financeUser);
            try {
                $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '100']], $this->financeUser);
                $this->fail('Mismatched payment was accepted.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('allocation_target_mismatch', $e->errorCode);
            }
            $this->assertSame('100.00', $payment->refresh()->unallocated_amount);
        }
        try {
            $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'payable_id' => 1, 'amount' => '100']], $this->financeUser);
            $this->fail('Ambiguous allocation was accepted.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }
        $this->assertSame('0.00', $invoice->refresh()->amount_paid);
    }

    public function test_same_foreign_currency_records_realised_fx_at_the_two_snapshot_rates(): void
    {
        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.10000000', 'source' => 'manual']);
        ExchangeRate::query()->create(['rate_date' => '2026-10-05', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.20000000', 'source' => 'manual']);
        $invoice = $this->issuedInvoice('EUR', '100');
        $payment = $this->service->create(['direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05', 'amount' => '100', 'currency' => 'EUR'], $this->financeUser);
        $allocation = $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '100']], $this->financeUser)[0];
        $this->assertSame('10.00', $allocation->fx_difference_base);
        $this->assertSame('0.00', $invoice->refresh()->balance);
    }

    public function test_crediting_a_paid_invoice_releases_receipts_and_preserves_reversal(): void
    {
        $invoice = $this->issuedInvoice('USD', '100');
        $payment = $this->service->create(['direction' => 'received', 'company_id' => $this->customer->id, 'payment_date' => '2026-10-05', 'amount' => '100', 'currency' => 'USD'], $this->financeUser);
        $this->service->allocate($payment, [['invoice_id' => $invoice->id, 'amount' => '100']], $this->financeUser);
        $credit = $this->invoices->creditNote($invoice, $this->financeUser, 'Full credit');
        $this->assertSame('-100.00', $credit->total);
        $this->assertSame('100.00', $payment->refresh()->unallocated_amount);
        $this->assertSame('0.00', $invoice->refresh()->amount_paid);
        $this->assertSame(0, $payment->allocations()->count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'payments', 'event' => 'unallocated']);
        $this->service->reverse($payment, $this->financeUser, 'Receipt reversed');
        $this->assertSame(PaymentStatus::Reversed, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->refresh()->status);
    }
}
