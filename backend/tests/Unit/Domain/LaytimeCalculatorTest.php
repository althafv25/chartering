<?php

namespace Tests\Unit\Domain;

use App\Domain\Laytime\LaytimeCalculator;
use App\Domain\Laytime\LaytimeResult;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class LaytimeCalculatorTest extends TestCase
{
    private function at(string $t): CarbonImmutable
    {
        return CarbonImmutable::parse($t, 'UTC');
    }

    /** @param array<string, mixed> $extra */
    private function calc(array $extra = []): LaytimeResult
    {
        return LaytimeCalculator::calculate([
            'fixed_hours' => '72', 'laytime_commenced_at' => $this->at('2026-10-01 06:00'), 'laytime_completed_at' => $this->at('2026-10-05 12:00'),
            'demurrage_rate_per_day' => '24000', 'despatch_rate_per_day' => '12000', ...$extra,
        ]);
    }

    /** LT-TC-01 (docs/08): A = 72 h, 01 Oct 06:00 → 05 Oct 12:00 = 102 h used → 30 h on demurrage at 24,000/day = 30,000.00. */
    public function test_lt_tc_01_demurrage_without_exceptions(): void
    {
        $r = $this->calc();

        $this->assertTrue($r->isComplete());
        $this->assertSame('102.0000', Decimal::round((string) $r->usedHours, 4));
        $this->assertSame('-30.0000', Decimal::round((string) $r->differenceHours, 4));
        $this->assertSame('30000.00', Decimal::round((string) $r->demurrageAmount, 2));
        $this->assertNull($r->despatchAmount);
    }

    public function test_despatch_when_finished_early(): void
    {
        $r = $this->calc(['laytime_completed_at' => $this->at('2026-10-03 06:00')]);   // 48 h used

        $this->assertSame('24.0000', Decimal::round((string) $r->differenceHours, 4));
        $this->assertSame('12000.00', Decimal::round((string) $r->despatchAmount, 2));   // 1 day saved
        $this->assertNull($r->demurrageAmount);
    }

    /** An exception that starts after demurrage began still counts in full under always-on-demurrage, but is excluded under exceptions_apply. */
    public function test_once_on_demurrage_rule_changes_how_late_exceptions_count(): void
    {
        $rain = [['from_at' => $this->at('2026-10-04 06:00'), 'to_at' => $this->at('2026-10-04 18:00'), 'exception_type' => 'weather', 'pct_counted' => '0']];

        $always = $this->calc(['exceptions' => $rain, 'once_on_demurrage_rule' => 'always_on_demurrage']);
        $applies = $this->calc(['exceptions' => $rain, 'once_on_demurrage_rule' => 'exceptions_apply']);

        $this->assertSame('102.0000', Decimal::round((string) $always->usedHours, 4));
        $this->assertSame('90.0000', Decimal::round((string) $applies->usedHours, 4));
    }

    /** Demurrage starts part-way through a segment that follows an excluded period (the branch that computes the start time). */
    public function test_demurrage_starting_inside_a_later_segment(): void
    {
        $rain = [['from_at' => $this->at('2026-10-03 18:00'), 'to_at' => $this->at('2026-10-04 18:00'), 'exception_type' => 'weather', 'pct_counted' => '0']];

        $r = $this->calc(['exceptions' => $rain]);

        // 60 h before the rain + 18 h after it = 78 h; the 24 h of rain was before demurrage started so it stays excluded.
        $this->assertSame('78.0000', Decimal::round((string) $r->usedHours, 4));
        $this->assertSame('6000.00', Decimal::round((string) $r->demurrageAmount, 2));   // 6 h = 0.25 day × 24,000
    }

    public function test_incomplete_inputs_yield_issues_not_zero(): void
    {
        $r = LaytimeCalculator::calculate(['fixed_hours' => '72']);

        $this->assertFalse($r->isComplete());
        $this->assertNull($r->demurrageAmount);
        $this->assertNotEmpty($r->issues);
    }
}
