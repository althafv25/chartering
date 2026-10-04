<?php

namespace App\Domain\Estimation\Input;

use App\Support\Decimal;

/** An amount in its own currency with the FX snapshot to the scenario currency. */
final class Money
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $fxRate,   // 1 unit of $currency = fxRate × scenario currency
        public readonly string $account,  // owner|charterer — charterer-account costs are shown but excluded from P&L
    ) {}

    public function inMain(): string
    {
        return Decimal::mul($this->amount, $this->fxRate);
    }
}
