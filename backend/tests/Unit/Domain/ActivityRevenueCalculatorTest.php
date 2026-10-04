<?php

namespace Tests\Unit\Domain;

use App\Domain\Offshore\ActivityRevenueCalculator;
use PHPUnit\Framework\TestCase;

class ActivityRevenueCalculatorTest extends TestCase
{
    private ActivityRevenueCalculator $calc;

    private array $day = ['rate_type' => 'day_rate', 'amount' => '14500.0000', 'currency' => 'USD', 'unit' => 'per_day', 'contract_rate_id' => 1];

    private array $standby = ['rate_type' => 'standby_day_rate', 'amount' => '9000.0000', 'currency' => 'USD', 'unit' => 'per_day', 'contract_rate_id' => 2];

    protected function setUp(): void
    {
        $this->calc = new ActivityRevenueCalculator;
    }

    public function test_hourly_proration_of_day_rate_and_standby_rate(): void
    {
        // 30 h billable → 1.25 d × 14 500 = 18 125; 6 h standby → 0.25 d × 9 000 = 2 250
        $r = $this->calc->calculate('30', '6', $this->day, $this->standby, []);
        $this->assertSame([], $r['warnings']);
        $this->assertSame('18125.00', $r['lines'][0]['amount']);
        $this->assertSame('1.250000', $r['lines'][0]['quantity']);
        $this->assertSame('2250.00', $r['lines'][1]['amount']);
        $this->assertSame('20375.00', $r['revenue']);
        $this->assertSame('USD', $r['currency']);
    }

    public function test_half_day_and_full_day_proration(): void
    {
        // per started half day: 12 h = 1 half day, 13 h = 2 half days
        $this->assertSame('0.500000000000', ActivityRevenueCalculator::days('12', 'half_day'));
        $this->assertSame('1.000000000000', ActivityRevenueCalculator::days('13', 'half_day'));
        $this->assertSame('1.500000000000', ActivityRevenueCalculator::days('24.5', 'half_day'));
        $this->assertSame('2', ActivityRevenueCalculator::days('24.5', 'full_day'));
        $this->assertSame('1', ActivityRevenueCalculator::days('24', 'full_day'));
        $this->assertSame('14500.00', $this->calc->calculate('13', '0', $this->day, null, [], 'half_day')['revenue']);
        $this->assertSame('29000.00', $this->calc->calculate('25', '0', $this->day, null, [], 'full_day')['revenue']);
    }

    public function test_hourly_rate_is_not_prorated(): void
    {
        $hourly = ['rate_type' => 'hourly', 'amount' => '650.5', 'currency' => 'USD', 'unit' => 'per_hour'];
        $r = $this->calc->calculate('7.5', '0', $hourly, null, [], 'full_day');
        $this->assertSame('4878.75', $r['revenue']);
        $this->assertNull($r['lines'][0]['proration']);
    }

    public function test_standby_at_full_rate_and_missing_rates(): void
    {
        $this->assertSame('3625.00', $this->calc->calculate('0', '6', $this->day, null, [], 'hourly', 'full_rate')['revenue']);

        $noStandby = $this->calc->calculate('24', '6', $this->day, null, []);
        $this->assertSame(['no_standby_rate'], $noStandby['warnings']);
        $this->assertNull($noStandby['revenue']);

        $this->assertSame(['no_billable_rate'], $this->calc->calculate('1', '0', null, null, [])['warnings']);
        $this->assertSame('0.00', $this->calc->calculate('0', '0', null, null, [])['revenue']); // non-billable only
    }

    public function test_lump_sum_and_mixed_currency(): void
    {
        $mob = ['rate_type' => 'mobilization_fee', 'amount' => '25000', 'currency' => 'USD', 'unit' => 'lump_sum', 'contract_rate_id' => 9];
        $r = $this->calc->calculate('12', '0', $this->day, null, [$mob]);
        $this->assertSame('32250.00', $r['revenue']);
        $this->assertSame('lump_sum', $r['lines'][1]['kind']);

        $eur = [...$mob, 'currency' => 'EUR'];
        $mixed = $this->calc->calculate('12', '0', $this->day, null, [$eur]);
        $this->assertSame(['mixed_currency'], $mixed['warnings']);
        $this->assertNull($mixed['revenue']);
    }
}
