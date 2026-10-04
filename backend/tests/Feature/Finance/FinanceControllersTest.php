<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ExpenseCategory;
use App\Models\RevenueCategory;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceControllersTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);

        ExchangeRate::query()->create(['rate_date' => now()->toDateString(), 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.6725', 'source' => 'manual']);

        $this->finance = $this->userWithRole(UserRole::Finance);
        $this->customer = Company::query()->create(['code' => 'CUST1', 'legal_name' => 'Gulf Shipping Co', 'normalized_name' => 'gulf shipping co']);
        Sanctum::actingAs($this->finance);
    }

    public function test_voyage_revenue_crud_and_workflow(): void
    {
        $categoryId = RevenueCategory::query()->where('code', 'FREIGHT')->value('id');

        $created = $this->postJson('/api/v1/voyage-revenues', [
            'revenue_category_id' => $categoryId,
            'description' => 'Freight charge',
            'quantity' => '100',
            'rate' => '50',
            'currency' => 'USD',
        ])->assertCreated()->json('data');

        $this->assertSame('5000.00', $created['amount']);
        $this->assertSame('draft', $created['status']);
        $this->assertTrue($created['is_editable']);

        $id = $created['id'];
        $this->getJson("/api/v1/voyage-revenues/{$id}")->assertOk()->assertJsonPath('data.id', $id);

        $this->putJson("/api/v1/voyage-revenues/{$id}", ['quantity' => '200', 'rate' => '50'])
            ->assertOk()->assertJsonPath('data.amount', '10000.00');

        $this->postJson("/api/v1/voyage-revenues/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->getJson('/api/v1/voyage-revenues?status=confirmed')->assertOk();

        $this->postJson("/api/v1/voyage-revenues/{$id}/cancel", ['reason' => 'duplicate entry'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_voyage_expense_crud_and_workflow(): void
    {
        $categoryId = ExpenseCategory::query()->value('id');

        $created = $this->postJson('/api/v1/voyage-expenses', [
            'expense_category_id' => $categoryId,
            'description' => 'Port fee',
            'quantity' => '1',
            'rate' => '2500',
            'currency' => 'USD',
        ])->assertCreated()->json('data');

        $id = $created['id'];
        $this->assertSame('draft', $created['status']);

        $this->putJson("/api/v1/voyage-expenses/{$id}", ['rate' => '3000'])
            ->assertOk()->assertJsonPath('data.amount', '3000.00');

        $this->postJson("/api/v1/voyage-expenses/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');

        // Approval requires a different user than confirm's actor (APR-02); same user forbidden via business rule.
        $other = $this->userWithRole(UserRole::Finance);
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/voyage-expenses/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->postJson("/api/v1/voyage-expenses/{$id}/cancel", ['reason' => 'wrong amount'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_invoice_lifecycle_and_credit_note(): void
    {
        $created = $this->postJson('/api/v1/invoices', [
            'invoice_type' => 'freight',
            'customer_company_id' => $this->customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'USD',
        ])->assertCreated()->json('data');

        $id = $created['id'];
        $this->assertSame('draft', $created['status']);
        $this->assertTrue($created['is_editable']);

        $withLines = $this->postJson("/api/v1/invoices/{$id}/lines", [
            'lines' => [
                ['description' => 'Freight', 'quantity' => '1', 'rate' => '1000', 'amount' => '1000.00'],
            ],
        ])->assertOk()->json('data');
        $this->assertSame('1000.00', $withLines['subtotal']);
        $this->assertSame('1000.00', $withLines['total']);

        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

        $approver = $this->userWithRole(UserRole::Finance);
        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $issued = $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk()->json('data');
        $this->assertSame('issued', $issued['status']);
        $this->assertNotNull($issued['invoice_number']);

        $credit = $this->postJson("/api/v1/invoices/{$id}/credit-note", ['reason' => 'service not rendered'])
            ->assertCreated()->json('data');
        $this->assertSame('issued', $credit['status']);
        $this->assertSame('-1000.00', $credit['total']);

        $this->getJson("/api/v1/invoices/{$id}")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_payable_approval_and_payment_allocation(): void
    {
        $supplier = Company::query()->create(['code' => 'SUP1', 'legal_name' => 'Agency Services LLC', 'normalized_name' => 'agency services llc']);

        $payable = $this->postJson('/api/v1/payables', [
            'supplier_company_id' => $supplier->id,
            'supplier_invoice_ref' => 'SUP-INV-001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'USD',
            'subtotal' => '500.00',
            'tax' => '0',
        ])->assertCreated()->json('data');

        $this->assertSame('draft', $payable['status']);
        $this->assertSame('500.00', $payable['total']);

        $approver = $this->userWithRole(UserRole::Finance);
        Sanctum::actingAs($approver);
        $approved = $this->postJson("/api/v1/payables/{$payable['id']}/approve")->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);

        // Record a payment and allocate it to the approved payable.
        $payment = $this->postJson('/api/v1/payments', [
            'direction' => 'paid',
            'company_id' => $supplier->id,
            'payment_date' => now()->toDateString(),
            'amount' => '500.00',
            'currency' => 'USD',
            'method' => 'wire',
        ])->assertCreated()->json('data');
        $this->assertSame('500.00', $payment['unallocated_amount']);

        $allocated = $this->postJson("/api/v1/payments/{$payment['id']}/allocate", [
            'allocations' => [
                ['payable_id' => $payable['id'], 'amount' => '500.00'],
            ],
        ])->assertOk()->json('data');
        $this->assertSame('0.00', $allocated['unallocated_amount']);
        $this->assertCount(1, $allocated['allocations']);

        $this->getJson("/api/v1/payables/{$payable['id']}")->assertOk()->assertJsonPath('data.status', 'paid');

        // Reversing undoes the allocation and marks the payment reversed.
        $reversed = $this->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'bank recall'])
            ->assertOk()->json('data');
        $this->assertSame('reversed', $reversed['status']);
        $this->assertSame('500.00', $reversed['unallocated_amount']);
    }

    public function test_receivables_aging_report(): void
    {
        $asOf = now()->toDateString();

        $created = $this->postJson('/api/v1/invoices', [
            'invoice_type' => 'freight',
            'customer_company_id' => $this->customer->id,
            'issue_date' => now()->subDays(45)->toDateString(),
            'due_date' => now()->subDays(15)->toDateString(),
            'currency' => 'USD',
        ])->assertCreated()->json('data');

        $id = $created['id'];
        $this->postJson("/api/v1/invoices/{$id}/lines", [
            'lines' => [
                ['description' => 'Freight', 'amount' => '750.00'],
            ],
        ])->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk();

        $approver = $this->userWithRole(UserRole::Finance);
        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        $response = $this->getJson("/api/v1/receivables/aging?as_of={$asOf}")->assertOk();
        $response->assertJsonPath('data.as_of', $asOf);
        $response->assertJsonPath('data.base_currency', 'USD');
        $response->assertJsonPath('data.customers.0.customer_company_id', $this->customer->id);
        $response->assertJsonPath('data.customers.0.buckets.1_30', '750.00');
        $response->assertJsonPath('data.customers.0.total', '750.00');
        $response->assertJsonPath('data.totals.1_30', '750.00');
        $response->assertJsonPath('data.totals.grand_total', '750.00');

        // The overview carries no invoice rows; asking for one customer returns its invoices (bucketed the same way).
        $response->assertJsonPath('data.customers.0.invoices', []);
        $detail = $this->getJson("/api/v1/receivables/aging?as_of={$asOf}&customer_company_id={$this->customer->id}")->assertOk();
        $detail->assertJsonCount(1, 'data.customers.0.invoices');
        $detail->assertJsonPath('data.customers.0.invoices.0.bucket', '1_30');
        $detail->assertJsonPath('data.customers.0.invoices.0.days_overdue', 15);
        $detail->assertJsonPath('data.customers.0.invoices.0.base_balance', '750.00');
        $this->getJson("/api/v1/receivables/aging?as_of={$asOf}&customer_company_id=999999")->assertOk()->assertJsonPath('data.customers', []);
    }

    public function test_invoice_and_payable_lists_accept_status_filters(): void
    {
        $this->postJson('/api/v1/invoices', [
            'invoice_type' => 'freight', 'customer_company_id' => $this->customer->id,
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'currency' => 'USD',
        ])->assertCreated();

        $this->getJson('/api/v1/invoices?status=draft&invoice_type=freight')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/invoices?status=issued')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/invoices?status=bogus')->assertStatus(422);
        $this->getJson('/api/v1/payables?status=draft')->assertOk();
        $this->getJson('/api/v1/payments?status=recorded')->assertOk();
    }

    public function test_balancing_accounts_and_cash_flow(): void
    {
        $created = $this->postJson('/api/v1/invoices', [
            'invoice_type' => 'freight', 'customer_company_id' => $this->customer->id,
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'currency' => 'USD',
        ])->assertCreated()->json('data');
        $id = $created['id'];
        $this->postJson("/api/v1/invoices/{$id}/lines", ['lines' => [['description' => 'Freight', 'amount' => '400.00']]])->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk();
        $approver = $this->userWithRole(UserRole::Finance);
        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        $accounts = $this->getJson('/api/v1/balancing/accounts')->assertOk()->json('data');
        $this->assertSame('USD', $accounts['base_currency']);
        $this->assertSame($this->customer->id, $accounts['accounts'][0]['company_id']);
        $this->assertSame('400.00', $accounts['accounts'][0]['receivable']);
        $this->assertSame('400.00', $accounts['totals']['receivable']);

        $cashFlow = $this->getJson('/api/v1/balancing/cash-flow')->assertOk()->json('data');
        $this->assertSame('USD', $cashFlow['base_currency']);
        $this->assertSame('400.00', $cashFlow['totals']['receivable']);
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        $readOnly = $this->userWithRole(UserRole::ReadOnly);
        Sanctum::actingAs($readOnly);

        $categoryId = RevenueCategory::query()->value('id');
        $this->postJson('/api/v1/voyage-revenues', [
            'revenue_category_id' => $categoryId,
            'description' => 'x',
            'quantity' => '1',
            'rate' => '1',
            'currency' => 'USD',
        ])->assertForbidden();
    }
}
