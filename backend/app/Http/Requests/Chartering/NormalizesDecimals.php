<?php

namespace App\Http\Requests\Chartering;

/**
 * JSON numbers arrive as PHP floats; convert them to exact decimal strings
 * before validation so money never travels as a float (D-005).
 */
trait NormalizesDecimals
{
    protected function prepareForValidation(): void
    {
        $this->replace($this->stringify($this->all()));
    }

    private function stringify(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => $this->stringify($x), $v);
        }
        if (is_float($v)) {
            $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');

            return $s === '-0' ? '0' : $s;
        }

        return $v;
    }
}
