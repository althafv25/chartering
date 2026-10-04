<?php

namespace Tests\Unit\Services\Finance;

use App\Enums\UserRole;
use App\Enums\VoyageRevenueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ExchangeRate;
use App\Models\RevenueCategory;
use App\Models\User;
use App\Models\VoyageRevenue;
use App\Services\Finance\VoyageRevenueService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoyageRevenueServiceTest extends TestCase
{
    use RefreshDatabase;

    private VoyageRevenueService $service;

    private User $financeUser;

    private User $noPermUser;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);

        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);

        $this->service = app(VoyageRevenueService::class);
        $this->financeUser = $this->userWithRole(UserRole::Finance);
        $this->noPermUser = $this->userWithRole(UserRole::ReadOnly);
        $this->categoryId = (int) RevenueCategory::query()->where('code', 'FREIGHT')->value('id');
    }

    public function test_create_computes_amount_and_fx_snapshot_f1(): void
    {
        $revenue = $this->service->create([
            'revenue_category_id' => $this->categoryId,
            'description' => 'Freight charge',
            'quantity' => '1000',
            'rate' => '12.50',
            'currency' => 'AED',
            'commission_pct_total' => '2.5',
        ], $this->financeUser);

        $this->assertSame('12500.00', $revenue->amount); // F2: qty * rate
        $this->assertSame('0.27229408', $revenue->fx_rate); // inverse of USD->AED 3.6725
        $this->assertSame('3403.68', $revenue->base_amount); // F1: amount * fx_rate (12500 * 0.27229408, rounded)
        $this->assertSame('312.50', $revenue->commission_amount); // 12500 * 2.5%
        $this->assertSame(VoyageRevenueStatus::Draft, $revenue->status);
    }

    public function test_update_recomputes_amounts_and_rejects_when_not_editable(): void
    {
        $revenue = $this->service->create([
            'revenue_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '10', 'rate' => '100', 'currency' => 'AED',
        ], $this->financeUser);

        $updated = $this->service->update($revenue, ['quantity' => '20', 'rate' => '100'], $this->financeUser);
        $this->assertSame('2000.00', $updated->amount);

        $this->service->confirm($updated, $this->financeUser);
        $confirmed = VoyageRevenue::query()->findOrFail($updated->id);

        $this->expectException(BusinessRuleException::class);
        $this->service->update($confirmed, ['quantity' => '30'], $this->financeUser);
    }

    public function test_confirm_then_cancel_blocked_once_invoiced(): void
    {
        $revenue = $this->service->create([
            'revenue_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '10', 'rate' => '100', 'currency' => 'AED',
        ], $this->financeUser);

        $confirmed = $this->service->confirm($revenue, $this->financeUser);
        $this->assertSame(VoyageRevenueStatus::Confirmed, $confirmed->status);

        $cancelled = $this->service->cancel($confirmed, $this->financeUser, 'wrong rate');
        $this->assertSame(VoyageRevenueStatus::Cancelled, $cancelled->status);
        $this->assertStringContainsString('Cancelled: wrong rate', (string) $cancelled->remarks);
    }

    public function test_authorization_is_enforced_inline(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->create([
            'revenue_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '10', 'rate' => '100', 'currency' => 'AED',
        ], $this->noPermUser);
    }

    public function test_delete_requires_draft_status(): void
    {
        $revenue = $this->service->create([
            'revenue_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '10', 'rate' => '100', 'currency' => 'AED',
        ], $this->financeUser);
        $this->service->confirm($revenue, $this->financeUser);
        $fresh = VoyageRevenue::query()->findOrFail($revenue->id);

        $this->expectException(BusinessRuleException::class);
        $this->service->delete($fresh, $this->financeUser);
    }
}
