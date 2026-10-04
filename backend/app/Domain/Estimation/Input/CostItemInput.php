<?php

namespace App\Domain\Estimation\Input;

final class CostItemInput
{
    public const BASES = ['lump_sum', 'per_day', 'per_mt', 'pct_of_revenue'];

    public function __construct(
        public readonly string $key,
        public readonly string $categoryCode,
        public readonly string $group, // expense category group (bunker|port|agency|canal|tonnage|operational|...)
        public readonly string $description,
        public readonly string $basis,
        public readonly ?string $quantity,
        public readonly string $rate,
        public readonly string $currency,
        public readonly string $fxRate,
        public readonly string $account,
    ) {}
}
