<?php

namespace Tests\Unit\Services\Finance;

use App\Enums\PayableStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Payable;
use App\Models\User;
use App\Services\Finance\PayableService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayableServiceTest extends TestCase
{
    use RefreshDatabase;

    private PayableService $service;

    private User $financeUser;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);

        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);

        $this->service = app(PayableService::class);
        $this->financeUser = $this->userWithRole(UserRole::Finance);
        $this->supplier = Company::query()->create(['code' => 'S1', 'legal_name' => 'Agency Services LLC', 'normalized_name' => 'agency services llc']);
    }

    private function draftPayable(string $currency = 'AED', string $subtotal = '1000.00', string $tax = '0.00'): Payable
    {
        return $this->service->create([
            'supplier_company_id' => $this->supplier->id,
            'supplier_invoice_ref' => 'SUP-INV-001',
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'currency' => $currency,
            'subtotal' => $subtotal,
            'tax' => $tax,
        ], $this->financeUser);
    }

    public function test_create_computes_totals_and_fx_f1_f3(): void
    {
        $payable = $this->draftPayable('AED', '1000.00', '50.00');

        $this->assertSame('1050.00', $payable->total); // F3 subtotal + tax
        $this->assertSame('0.27229408', $payable->fx_rate); // inverse USD->AED
        $this->assertSame('285.91', $payable->base_total); // F1: 1050 * 0.27229408 rounded
        $this->assertSame(PayableStatus::Draft, $payable->status);
        $this->assertMatchesRegularExpression('/^PAYB-2026-\d{5}$/', $payable->payable_number);
    }

    public function test_update_requires_lock_version_and_editable_status(): void
    {
        $payable = $this->draftPayable();
        $v0 = $payable->lock_version;

        $updated = $this->service->update(Payable::query()->findOrFail($payable->id), ['lock_version' => $v0, 'subtotal' => '1200.00'], $this->financeUser);
        $this->assertSame('1200.00', $updated->total);
        $this->assertSame($v0 + 1, $updated->lock_version);

        $this->expectException(BusinessRuleException::class);
        $this->service->update(Payable::query()->findOrFail($payable->id), ['lock_version' => $v0, 'subtotal' => '999.00'], $this->financeUser);
    }

    public function test_approve_requires_different_user_than_creator_apr02(): void
    {
        $payable = $this->draftPayable();

        $this->expectException(BusinessRuleException::class);
        $this->service->approve($payable, $this->financeUser);
    }

    public function test_approve_by_different_user_succeeds_without_voyage(): void
    {
        $otherFinanceUser = $this->userWithRole(UserRole::Finance);
        $payable = $this->draftPayable();

        $approved = $this->service->approve($payable, $otherFinanceUser);
        $this->assertSame(PayableStatus::Approved, $approved->status);
        $this->assertSame($otherFinanceUser->id, $approved->approved_by);
        $this->assertCount(0, $approved->voyageExpenses); // no voyage_id -> nothing booked
    }

    public function test_reapprove_blocked_once_paid(): void
    {
        $otherFinanceUser = $this->userWithRole(UserRole::Finance);
        $payable = $this->draftPayable();
        $approved = $this->service->approve($payable, $otherFinanceUser);

        $approved->amount_paid = '500.00';
        $approved->save();

        $this->expectException(BusinessRuleException::class);
        $this->service->reapprove(Payable::query()->findOrFail($approved->id), $otherFinanceUser);
    }

    public function test_cancel_only_from_draft_or_approved(): void
    {
        $otherFinanceUser = $this->userWithRole(UserRole::Finance);
        $payable = $this->draftPayable();
        $approved = $this->service->approve($payable, $otherFinanceUser);

        $cancelled = $this->service->cancel(Payable::query()->findOrFail($approved->id), $this->financeUser, 'duplicate invoice');
        $this->assertSame(PayableStatus::Cancelled, $cancelled->status);
        $this->assertStringContainsString('Cancelled: duplicate invoice', (string) $cancelled->remarks);

        $this->expectException(BusinessRuleException::class);
        $this->service->cancel(Payable::query()->findOrFail($cancelled->id), $this->financeUser, 'again');
    }

    public function test_delete_requires_draft(): void
    {
        $otherFinanceUser = $this->userWithRole(UserRole::Finance);
        $payable = $this->draftPayable();
        $approved = $this->service->approve($payable, $otherFinanceUser);

        $this->expectException(BusinessRuleException::class);
        $this->service->delete(Payable::query()->findOrFail($approved->id), $this->financeUser);
    }
}
