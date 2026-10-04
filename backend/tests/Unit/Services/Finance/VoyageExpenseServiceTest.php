<?php

namespace Tests\Unit\Services\Finance;

use App\Enums\UserRole;
use App\Enums\VoyageExpenseStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ExchangeRate;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\VoyageExpense;
use App\Services\Finance\VoyageExpenseService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoyageExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private VoyageExpenseService $service;

    private User $financeUser;

    private User $accountsUser;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);

        ExchangeRate::query()->create(['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67250000', 'source' => 'manual']);

        $this->service = app(VoyageExpenseService::class);
        $this->financeUser = $this->userWithRole(UserRole::Finance);
        $this->accountsUser = $this->userWithRole(UserRole::Accounts);
        $this->categoryId = (int) ExpenseCategory::query()->where('code', 'PORT')->value('id');
    }

    public function test_create_computes_amount_and_fx_f1(): void
    {
        $expense = $this->service->create([
            'expense_category_id' => $this->categoryId,
            'description' => 'Port charges',
            'quantity' => '1',
            'rate' => '500',
            'currency' => 'AED',
        ], $this->financeUser);

        $this->assertSame('500.00', $expense->amount);
        $this->assertSame('136.15', $expense->base_amount); // 500 * (1/3.6725) = 136.147... -> 136.15
        $this->assertSame(VoyageExpenseStatus::Draft, $expense->status);
    }

    public function test_confirm_approve_requires_different_user_apr02(): void
    {
        $expense = $this->service->create([
            'expense_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '1', 'rate' => '100', 'currency' => 'AED',
        ], $this->financeUser);

        $confirmed = $this->service->confirm($expense, $this->financeUser);
        $this->assertSame(VoyageExpenseStatus::Confirmed, $confirmed->status);

        $this->expectException(BusinessRuleException::class);
        $this->service->approve(VoyageExpense::query()->findOrFail($confirmed->id), $this->financeUser);
    }

    public function test_approve_by_different_user_succeeds_then_cancel(): void
    {
        $otherFinanceUser = $this->userWithRole(UserRole::Finance);
        $expense = $this->service->create([
            'expense_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '1', 'rate' => '100', 'currency' => 'AED',
        ], $this->financeUser);
        $confirmed = $this->service->confirm($expense, $this->financeUser);

        $approved = $this->service->approve(VoyageExpense::query()->findOrFail($confirmed->id), $otherFinanceUser);
        $this->assertSame(VoyageExpenseStatus::Approved, $approved->status);

        $cancelled = $this->service->cancel($approved, $otherFinanceUser, 'duplicate');
        $this->assertSame(VoyageExpenseStatus::Cancelled, $cancelled->status);
    }

    public function test_accounts_role_cannot_approve_expenses(): void
    {
        $expense = $this->service->create([
            'expense_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '1', 'rate' => '100', 'currency' => 'AED',
        ], $this->financeUser);
        $confirmed = $this->service->confirm($expense, $this->financeUser);

        $this->expectException(AuthorizationException::class);
        $this->service->approve(VoyageExpense::query()->findOrFail($confirmed->id), $this->accountsUser);
    }

    public function test_edit_blocked_once_confirmed(): void
    {
        $expense = $this->service->create([
            'expense_category_id' => $this->categoryId, 'description' => 'A', 'quantity' => '1', 'rate' => '100', 'currency' => 'AED',
        ], $this->financeUser);
        $confirmed = $this->service->confirm($expense, $this->financeUser);

        $this->expectException(BusinessRuleException::class);
        $this->service->update(VoyageExpense::query()->findOrFail($confirmed->id), ['rate' => '200'], $this->financeUser);
    }
}
