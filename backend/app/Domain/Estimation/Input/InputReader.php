<?php

namespace App\Domain\Estimation\Input;

use App\Support\Decimal;
use InvalidArgumentException;

/** Collects validation issues while parsing an input snapshot (pure, no DB). */
final class InputReader
{
    /** @var list<string> */
    public array $issues = [];

    /**
     * @param  array<string, mixed>  $a
     */
    public function dec(array $a, string $key, string $label, bool $required = true, ?string $default = null, bool $positive = false, ?string $max = null): ?string
    {
        $v = $a[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required && $default === null) {
                $this->issues[] = "{$label} is required.";
            }

            return $default;
        }
        if (! is_string($v) && ! is_int($v)) {
            $this->issues[] = "{$label} must be a decimal string.";

            return null;
        }
        try {
            $d = Decimal::of((string) $v);
        } catch (InvalidArgumentException) {
            $this->issues[] = "{$label} must be a number.";

            return null;
        }
        if (Decimal::cmp($d, '0') < 0) {
            $this->issues[] = "{$label} cannot be negative.";

            return null;
        }
        if ($positive && Decimal::isZero($d)) {
            $this->issues[] = "{$label} must be greater than zero.";

            return null;
        }
        if ($max !== null && Decimal::cmp($d, $max) > 0) {
            $this->issues[] = "{$label} cannot exceed {$max}.";

            return null;
        }

        return $d;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  list<string>  $allowed
     */
    public function enum(array $a, string $key, string $label, array $allowed, ?string $default = null): string
    {
        $v = $a[$key] ?? $default;
        if (! is_string($v) || ! in_array($v, $allowed, true)) {
            $this->issues[] = "{$label} must be one of: ".implode(', ', $allowed).'.';

            return $allowed[0];
        }

        return $v;
    }

    /** @param array<string, mixed>|null $a */
    public function money(?array $a, string $label, string $scenarioCurrency): ?Money
    {
        if ($a === null) {
            return null;
        }
        $amount = $this->dec($a, 'amount', "{$label} amount", false, '0') ?? '0';
        $currency = strtoupper((string) ($a['currency'] ?? $scenarioCurrency));
        $fx = $currency === $scenarioCurrency
            ? ($this->dec($a, 'fx_rate', "{$label} FX rate", false, '1', true) ?? '1')
            : ($this->dec($a, 'fx_rate', "{$label} FX rate ({$currency}→{$scenarioCurrency})", true, null, true) ?? '1');
        $account = $this->enum($a, 'account', "{$label} account", ['owner', 'charterer'], 'owner');

        return new Money($amount, $currency, $fx, $account);
    }
}
