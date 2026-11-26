<?php

namespace Tests\Feature\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * Customer statement of account (report slug customer-statement). The fixture is one customer with: an invoice from before
 * the period (opening balance), a receipt before the period, an invoice and an unallocated receipt inside the period, an
 * invoice that was later credited, a reversed receipt, a draft, and another customer's invoice (none of which may count).
 */
class CustomerStatementTest extends OperationsTestCase
{
    private int $customer;

    private function invoice(string $number, string $issue, string $total, InvoiceStatus $status, string $type = 'hire', bool $issued = true, ?int $customer = null, string $paid = '0.00'): void
    {
        Invoice::query()->create([
            'invoice_number' => $number, 'invoice_type' => $type, 'customer_company_id' => $customer ?? $this->customer,
            'billing_snapshot' => ['name' => 'Statement customer'], 'issue_date' => $issue, 'due_date' => $issue, 'currency' => 'USD', 'fx_rate' => '1',
            'subtotal' => $total, 'tax_amount' => '0.00', 'total' => $total, 'base_total' => $total, 'amount_paid' => $paid, 'status' => $status,
            'issued_at' => $issued ? $issue.' 10:00:00' : null,
        ]);
    }

    private function payment(string $number, string $date, string $amount, PaymentStatus $status = PaymentStatus::Recorded, ?int $company = null): void
    {
        Payment::query()->create([
            'payment_number' => $number, 'direction' => 'received', 'company_id' => $company ?? $this->customer, 'payment_date' => $date,
            'amount' => $amount, 'currency' => 'USD', 'fx_rate' => '1', 'base_amount' => $amount, 'unallocated_amount' => '0.00',
            'method' => 'wire', 'bank_reference' => 'TT-'.$number, 'status' => $status,
        ]);
    }

    private function statement(array $query = []): array
    {
        return $this->as($this->userWithRole(UserRole::Finance))
            ->getJson('/api/v1/reports/customer-statement?'.http_build_query(['customer_company_id' => $this->customer, 'from' => '2026-10-01', 'to' => '2026-10-31', ...$query]))
            ->assertOk()->json('data');
    }

    public function test_statement_has_opening_balance_period_lines_and_closing_balance(): void
    {
        $this->customer = Company::query()->create(['code' => 'SOA1', 'legal_name' => 'Statement Customer', 'normalized_name' => 'statement customer'])->id;
        $other = Company::query()->create(['code' => 'SOA2', 'legal_name' => 'Someone Else', 'normalized_name' => 'someone else'])->id;

        $this->invoice('INV-OLD', '2026-09-10', '1000.00', InvoiceStatus::PartiallyPaid, paid: '400.00');
        $this->payment('PAY-OLD', '2026-09-20', '400.00');
        $this->invoice('INV-B', '2026-10-05', '500.00', InvoiceStatus::Issued);
        $this->payment('PAY-UNALLOC', '2026-10-06', '200.00');
        $this->invoice('INV-C', '2026-10-07', '300.00', InvoiceStatus::Cancelled);
        $this->invoice('CN-C', '2026-10-08', '-300.00', InvoiceStatus::Issued, type: 'credit_note');
        $this->payment('PAY-REV', '2026-10-09', '999.00', PaymentStatus::Reversed);
        $this->invoice('DRAFT', '2026-10-10', '77.00', InvoiceStatus::Draft, issued: false);
        $this->invoice('OTHER', '2026-10-11', '5000.00', InvoiceStatus::Issued, customer: $other);
        $this->payment('PAY-OTHER', '2026-10-12', '5000.00', company: $other);

        $data = $this->statement();
        $rows = $data['rows'];

        $this->assertSame(['Opening balance', 'Invoice', 'Payment', 'Invoice', 'Credit note', 'Closing balance'], array_column($rows, 'type'));
        // Opening: 1,000 invoiced less 400 received before 1 Oct.
        $this->assertSame('600.00', $rows[0]['balance']);
        // Period lines carry a running balance in base currency.
        $this->assertSame(['500.00', null, '1100.00'], [$rows[1]['debit'], $rows[1]['credit'], $rows[1]['balance']]);
        $this->assertSame(['200.00', '900.00'], [$rows[2]['credit'], $rows[2]['balance']]);
        // The credited invoice stays as a debit, and its credit note offsets it.
        $this->assertSame(['300.00', '1200.00'], [$rows[3]['debit'], $rows[3]['balance']]);
        $this->assertSame(['300.00', '900.00'], [$rows[4]['credit'], $rows[4]['balance']]);
        // Closing: debits 800.00, credits 500.00, balance 900.00 (= 600 + 800 - 500).
        $this->assertSame(['800.00', '500.00', '900.00'], [$rows[5]['debit'], $rows[5]['credit'], $rows[5]['balance']]);
        $this->assertStringEndsWith('Statement Customer', $rows[0]['description']);
        // The reversed receipt, the draft and the other customer's invoice and receipt are not in the statement.
        $this->assertNotContains('PAY-REV', array_column($rows, 'reference'));
        $this->assertNotContains('DRAFT', array_column($rows, 'reference'));
        $this->assertNotContains('OTHER', array_column($rows, 'reference'));
        $this->assertNotContains('PAY-OTHER', array_column($rows, 'reference'));
        $this->assertSame(['date', 'type', 'reference', 'description', 'currency', 'original_amount', 'debit', 'credit', 'balance'], array_column($data['columns'], 'key'));
    }

    public function test_customer_is_required_and_only_finance_roles_can_run_it(): void
    {
        $this->as($this->userWithRole(UserRole::Finance))->getJson('/api/v1/reports/customer-statement?from=2026-10-01&to=2026-10-31')
            ->assertStatus(422)->assertJsonPath('error_code', 'customer_required');

        $this->customer = Company::query()->create(['code' => 'SOA3', 'legal_name' => 'Ops Check', 'normalized_name' => 'ops check'])->id;
        $this->as($this->ops)->getJson('/api/v1/reports/customer-statement?customer_company_id='.$this->customer)->assertForbidden();
        $slugs = array_column($this->as($this->userWithRole(UserRole::Finance))->getJson('/api/v1/reports')->assertOk()->json('data'), 'slug');
        $this->assertContains('customer-statement', $slugs);
    }

    public function test_statement_exports_as_csv_with_the_same_rows(): void
    {
        $this->customer = Company::query()->create(['code' => 'SOA4', 'legal_name' => 'Export Customer', 'normalized_name' => 'export customer'])->id;
        $this->invoice('INV-X', '2026-10-03', '250.00', InvoiceStatus::Issued);
        $finance = $this->userWithRole(UserRole::Finance);
        $csv = $this->as($finance)->get('/api/v1/reports/customer-statement?'.http_build_query(['customer_company_id' => $this->customer, 'from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'csv']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Balance', $csv);
        $this->assertStringContainsString('INV-X', $csv);
        $this->assertStringContainsString('250.00', $csv);

        $query = http_build_query(['customer_company_id' => $this->customer, 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->as($finance)->get("/api/v1/reports/customer-statement?{$query}&format=xlsx")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->as($finance)->get("/api/v1/reports/customer-statement?{$query}&format=pdf")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
