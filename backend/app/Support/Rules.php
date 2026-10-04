<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/** Reusable validation fragments. */
final class Rules
{
    /** Non-negative decimal with max integer digits and scale (matches DECIMAL(p,s)). @return list<string> */
    public static function decimal(int $integerDigits, int $scale, bool $required = false, bool $allowNegative = false): array
    {
        $max = str_repeat('9', $integerDigits).($scale > 0 ? '.'.str_repeat('9', $scale) : '');

        return [
            $required ? 'required' : 'nullable',
            'numeric',
            "decimal:0,{$scale}",
            $allowNegative ? "min:-{$max}" : 'min:0',
            "max:{$max}",
        ];
    }

    /** @return list<mixed> */
    public static function country(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'size:2', Rule::in(Countries::all())];
    }

    /** @return list<mixed> */
    public static function currency(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'size:3', Rule::exists('currencies', 'code')];
    }

    /** @return list<string> */
    public static function latitude(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'decimal:0,7', 'between:-90,90'];
    }

    /** @return list<string> */
    public static function longitude(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'decimal:0,7', 'between:-180,180'];
    }
}
