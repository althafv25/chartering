<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IMO ship identification number: 7 digits, the last being a check digit.
 * Check: Σ(d1..d6 × weights 7..2) mod 10 = d7. (e.g. 9074729 → valid)
 */
class ImoNumber implements ValidationRule
{
    public static function isValid(string $value): bool
    {
        if (! preg_match('/^\d{7}$/', $value)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 6; $i++) {
            $sum += (int) $value[$i] * (7 - $i);
        }

        return $sum % 10 === (int) $value[6];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('The :attribute must be a valid 7-digit IMO number (check digit failed).');
        }
    }
}
