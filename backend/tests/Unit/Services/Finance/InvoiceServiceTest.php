<?php

namespace Tests\Unit\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Enums\VoyageRevenueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\RevenueCategory;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\VoyageRevenue;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\VoyageRevenueService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;

    private VoyageRevenueService $revenues;

    private User $financeUser;

    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);

        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);

        $this->service = app(InvoiceService::class);
        $this->revenues = app(VoyageRevenueService::class);
        $this->financeUser = $this->userWithRole(UserRole::Finance);
        $this->customer = Company::query()->create(['code' => 'C1', 'legal_name' => 'Gulf Charterers', 'normalized_name' => 'gulf charterers', 'address_line1' => '1 Marina Rd', 'city' => 'Dubai', 'country' => 'AE', 'tax_number' => 'TRN123']);
    }

    private function draftInvoice(): Invoice
    {
        return $this->service->create([
            'invoice_type' => 'freight',
            'customer_company_id' => $this->customer->id,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'currency' => 'USD',
        ], $this->financeUser);
    }

    public function test_create_snapshots_billing_and_fx(): void
    {
        $invoice = $this->draftInvoice();

        $this->assertSame('Gulf Charterers', $invoice->billing_snapshot['name']);
        $this->assertSame('TRN123', $invoice->billing_snapshot['tax_no']);
        $this->assertSame('1.00000000', $invoice->fx_rate); // USD -> USD identity
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
    }

    public function test_save_lines_computes_tax_and_totals_f2_f3(): void
    {
        $invoice = $this->draftInvoice();
        $taxCode = TaxCode::query()->create(['code' => 'VAT5', 'name' => 'VAT 5%', 'rate_pct' => '5.0000']);

        $updated = $this->service->saveLines($invoice, [
            ['description' => 'Freight', 'quantity' => '2', 'rate' => '1000', 'tax_code_id' => $taxCode->id],
            ['description' => 'Extra', 'amount' => '250.00'],
        ], $this->financeUser);

        $this->assertCount(2, $updated->lines);
        $this->assertSame('2000.00', $updated->lines[0]->amount); // F2 qty*rate
        $this->assertSame('100.00', $updated->lines[0]->tax_amount); // 2000 * 5%
        $this->assertSame('0.00', $updated->lines[1]->tax_amount);

        $this->assertSame('2250.00', $updated->subtotal); // F3 sum amount
        $this->assertSame('100.00', $updated->tax_amount); // F3 sum tax
        $this->assertSame('2350.00', $updated->total); // F3 subtotal + tax
        $this->assertSame('2350.00', $updated->base_total); // F1 (USD base, rate 1)
    }

    public function test_attach_confirmed_revenue_line_marks_it_invoiced_inv02(): void
    {
        $category = (int) RevenueCategory::query()->where('code', 'FREIGHT')->value('id');
        $revenue = $this->revenues->create(['revenue_category_id' => $category, 'description' => 'Freight', 'quantity' => '100', 'rate' => '10', 'currency' => 'USD'], $this->financeUser);
        $confirmed = $this->revenues->confirm($revenue, $this->financeUser);

        $invoice = $this->draftInvoice();
        $updated = $this->service->attachRevenueLines($invoice, [$confirmed->id], $this->financeUser);

        $this->assertCount(1, $updated->lines);
        $this->assertSame(VoyageRevenueStatus::Invoiced, VoyageRevenue::query()->findOrFail($confirmed->id)->status);

        // Cannot invoice twice.
        $invoice2 = $this->draftInvoice();
        $this->expectException(BusinessRuleException::class);
        $this->service->attachRevenueLines($invoice2, [$confirmed->id], $this->financeUser);
    }

    public function test_issue_assigns_sequential_number_and_locks_edits(): void
    {
        $invoice = $this->draftInvoice();
        $this->service->saveLines($invoice, [['description' => 'Freight', 'amount' => '100.00']], $this->financeUser);

        $issued = $this->service->issue(Invoice::query()->findOrFail($invoice->id), $this->financeUser);
        $this->assertMatchesRegularExpression('/^INV-2026-\d{5}$/', (string) $issued->invoice_number);
        $this->assertSame(InvoiceStatus::Issued, $issued->status);

        $this->expectException(BusinessRuleException::class);
        $this->service->update($issued, ['remarks' => 'edit'], $this->financeUser);
    }

    public function test_optimistic_lock_detects_stale_write(): void
    {
        $invoice = $this->draftInvoice();
        $v0 = $invoice->lock_version;

        $this->service->update(Invoice::query()->findOrFail($invoice->id), ['lock_version' => $v0, 'remarks' => 'first'], $this->financeUser);

        $this->expectException(BusinessRuleException::class);
        $this->service->update(Invoice::query()->findOrFail($invoice->id), ['lock_version' => $v0, 'remarks' => 'stale'], $this->financeUser);
    }

    public function test_credit_note_reverses_totals_and_cancels_original(): void
    {
        $invoice = $this->draftInvoice();
        $this->service->saveLines($invoice, [['description' => 'Freight', 'amount' => '500.00']], $this->financeUser);
        $issued = $this->service->issue(Invoice::query()->findOrFail($invoice->id), $this->financeUser);

        $credit = $this->service->creditNote($issued, $this->financeUser, 'overbilled');

        $this->assertSame('credit_note', $credit->invoice_type->value);
        $this->assertSame('-500.00', $credit->subtotal);
        $this->assertSame(InvoiceStatus::Issued, $credit->status);
        $this->assertSame(InvoiceStatus::Cancelled, Invoice::query()->findOrFail($issued->id)->status);
        $this->assertSame($issued->id, $credit->credit_note_for_id);
    }

    public function test_generate_pdf_creates_document_and_links_invoice(): void
    {
        $invoice = $this->draftInvoice();
        $this->service->saveLines($invoice, [['description' => 'Freight', 'amount' => '500.00']], $this->financeUser);
        $issued = $this->service->issue(Invoice::query()->findOrFail($invoice->id), $this->financeUser);

        $document = $this->service->generatePdf($issued, $this->financeUser);

        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertGreaterThan(0, $document->size_bytes);
        $this->assertSame($document->id, Invoice::query()->findOrFail($issued->id)->pdf_document_id);
    }

    public function test_cancel_delete_and_credit_release_billing_without_losing_history(): void
    {
        $revenue = $this->revenues->create(['revenue_category_id' => RevenueCategory::query()->value('id'), 'description' => 'Freight', 'amount' => '100', 'currency' => 'USD'], $this->financeUser);
        $revenue = $this->revenues->confirm($revenue, $this->financeUser);
        foreach (['cancel', 'delete', 'credit'] as $action) {
            $invoice = $this->service->attachRevenueLines($this->draftInvoice(), [$revenue->id], $this->financeUser);
            $line = $invoice->lines()->firstOrFail();
            if ($action === 'cancel') {
                $this->service->cancel($invoice, $this->financeUser, 'Rebill');
            } elseif ($action === 'delete') {
                $this->service->delete($invoice, $this->financeUser);
            } else {
                $this->service->creditNote($this->service->issue($invoice, $this->financeUser), $this->financeUser, 'Rebill');
            }
            $this->assertSame($revenue->id, $line->refresh()->voyage_revenue_id);
            $this->assertNotNull($line->released_at);
            $this->assertSame(VoyageRevenueStatus::Confirmed, $revenue->refresh()->status);
            $this->assertFalse($revenue->invoiceLine()->exists());
        }
        $replacement = $this->service->attachRevenueLines($this->draftInvoice(), [$revenue->id], $this->financeUser);
        $this->assertSame($replacement->id, $revenue->refresh()->invoiceLine->invoice_id);
        $this->expectException(BusinessRuleException::class);
        $this->service->saveLines($this->draftInvoice(), [['voyage_revenue_id' => $revenue->id, 'description' => 'Duplicate', 'amount' => '100']], $this->financeUser);
    }

    public function test_credit_note_reverses_the_original_tax_snapshot_after_master_changes(): void
    {
        $tax = TaxCode::query()->create(['code' => 'VAT5', 'name' => 'VAT', 'rate_pct' => '5']);
        $invoice = $this->service->saveLines($this->draftInvoice(), [['description' => 'Freight', 'amount' => '100', 'tax_code_id' => $tax->id]], $this->financeUser);
        $invoice = $this->service->issue($invoice, $this->financeUser);
        $tax->update(['rate_pct' => '10']);
        $credit = $this->service->creditNote($invoice, $this->financeUser, 'Correction');
        $this->assertSame('-105.00', $credit->total);
        $this->assertSame('-5.00', $credit->tax_amount);
        $this->assertSame('5.0000', $credit->lines()->firstOrFail()->tax_rate_pct);
    }
}
