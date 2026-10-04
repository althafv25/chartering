<?php

namespace Tests\Unit\Support;

use App\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    /** @return array<string, array{string,int,string}> */
    public static function rounding(): array
    {
        return [
            'half up' => ['1.005', 2, '1.01'],
            'negative half away from zero' => ['-1.005', 2, '-1.01'],
            'below half' => ['2.344999', 2, '2.34'],
            'negative zero' => ['-0.001', 2, '0.00'],
            'integer' => ['7', 3, '7.000'],
            'fx 8dp' => ['0.272294077603', 8, '0.27229408'],
        ];
    }

    #[DataProvider('rounding')]
    public function test_round_half_up(string $in, int $scale, string $expected): void
    {
        $this->assertSame($expected, Decimal::round($in, $scale));
    }

    public function test_arithmetic_is_exact_where_floats_fail(): void
    {
        // 0.1 + 0.2 = 0.30000000000000004 in floating point
        $this->assertSame('0.30', Decimal::round(Decimal::add('0.1', '0.2'), 2));
        $this->assertSame('-1.000000000000', Decimal::sub('1', '2'));
        $this->assertSame('17.15', Decimal::round(Decimal::mul('3.43', '5'), 2));
        $this->assertSame('0.333333333333', Decimal::div('1', '3'));
    }

    public function test_rejects_non_numeric_and_division_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::div('1', '0');
    }

    public function test_rejects_scientific_notation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of('1e5');
    }
}
