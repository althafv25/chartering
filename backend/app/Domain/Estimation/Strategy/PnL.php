<?php

namespace App\Domain\Estimation\Strategy;

/** Profit & loss figures produced by a strategy (exact decimal strings). */
final class PnL
{
    public function __construct(
        public readonly string $voyageCosts,   // costs included in TCE
        public readonly string $totalCosts,    // all owner-account costs
        public readonly string $tonnageCost,   // cargo relet only (else 0)
        public readonly string $profit,
        public readonly ?string $marginPct,
        public readonly ?string $profitPerDay,
        public readonly ?string $tcePerDay,
    ) {}
}
