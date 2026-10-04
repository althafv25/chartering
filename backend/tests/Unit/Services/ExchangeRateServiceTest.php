<?php

namespace Tests\Unit\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeRateService $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        config(['offshore.base_currency' => 'USD']);
        $this->fx = app(ExchangeRateService::class);

        $rate = fn ($d, $b, $q, $r) => ExchangeRate::query()->create(['rate_date' => $d, 'base_currency' => $b, 'quote_currency' => $q, 'rate' => $r, 'source' => 'manual']);
        $rate('2026-09-01', 'USD', 'AED', '3.67250000');
        $rate('2026-09-01', 'USD', 'EUR', '0.90000000');
        $rate('2026-09-15', 'USD', 'EUR', '0.92000000');
    }

    public function test_identity_direct_and_inverse(): void
    {
        $this->assertSame('1.00000000', $this->fx->resolve('USD', 'USD', '2026-10-01')['rate']);
        $this->assertSame('3.67250000', $this->fx->resolve('USD', 'AED', '2026-10-01')['rate']);

        $inverse = $this->fx->resolve('AED', 'USD', '2026-10-01');
        $this->assertSame('inverse', $inverse['method']);
        $this->assertSame('0.27229408', $inverse['rate']); // 1 / 3.6725
    }

    public function test_uses_latest_rate_on_or_before_the_date(): void
    {
        $this->assertSame('0.90000000', $this->fx->resolve('USD', 'EUR', '2026-09-14')['rate']);
        $this->assertSame('0.92000000', $this->fx->resolve('USD', 'EUR', '2026-09-15')['rate']);
        $this->assertSame('2026-09-15', $this->fx->resolve('USD', 'EUR', '2026-12-31')['rate_date']);
    }

    public function test_cross_rate_via_base_currency(): void
    {
        // AED→EUR = (1 / 3.6725) × 0.92 = 0.2505105513...
        $r = $this->fx->resolve('AED', 'EUR', '2026-10-01');
        $this->assertSame('cross:USD', $r['method']);
        $this->assertSame('0.25051055', $r['rate']);
    }

    public function test_convert_rounds_to_target_currency_decimals(): void
    {
        $this->assertSame('3672.50', $this->fx->convert('1000', 'USD', 'AED', '2026-10-01'));
        $this->assertSame('272.29', $this->fx->convert('1000', 'AED', 'USD', '2026-10-01'));
    }

    public function test_missing_rate_is_a_clear_error(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('No exchange rate is available for GBP/USD');
        $this->fx->resolve('GBP', 'USD', '2026-10-01');
    }

    public function test_rate_before_first_entry_is_missing(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->fx->resolve('USD', 'AED', '2026-08-31');
    }
}
