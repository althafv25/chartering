<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on numeric strings (bcmath). Never use PHP floats
 * for money, rates, fuel or FX. Intermediate scale is 12; round() applies
 * ROUND_HALF_UP (away from zero) only when storing or presenting.
 */
final class Decimal
{
    public const SCALE = 12;

    public static function of(string|int|null $value): string
    {
        $v = trim((string) ($value ?? '0'));
        if ($v === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            throw new InvalidArgumentException("Not a decimal number: [{$v}]");
        }

        return $v;
    }

    public static function add(string|int $a, string|int $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(string|int $a, string|int $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string|int $a, string|int $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(string|int $a, string|int $b): string
    {
        if (self::isZero($b)) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::of($a), self::of($b), self::SCALE);
    }

    public static function cmp(string|int $a, string|int $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isZero(string|int $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** Human-readable exact value for traces: "12.500000000000" → "12.5". */
    public static function trim(string|int $value): string
    {
        $v = self::of($value);
        if (! str_contains($v, '.')) {
            return $v;
        }
        $v = rtrim(rtrim($v, '0'), '.');

        return $v === '-0' || $v === '' ? '0' : $v;
    }

    /** ROUND_HALF_UP (away from zero) to $scale decimal places. */
    public static function round(string|int $value, int $scale): string
    {
        $v = self::of($value);
        $offset = '0.'.str_repeat('0', $scale).'5';
        $rounded = str_starts_with($v, '-')
            ? bcsub($v, $offset, $scale)
            : bcadd($v, $offset, $scale);

        // Avoid "-0.00"
        return self::cmp($rounded, '0') === 0 ? bcadd('0', '0', $scale) : $rounded;
    }
}
