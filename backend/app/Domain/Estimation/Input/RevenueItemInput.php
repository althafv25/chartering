<?php

namespace App\Domain\Estimation\Input;

final class RevenueItemInput
{
    public const BASES = ['lump_sum', 'per_mt', 'per_day', 'per_hour'];

    public function __construct(
        public readonly string $key,
        public readonly string $categoryCode,
        public readonly string $group,
        public readonly string $description,
        public readonly string $basis,
        public readonly ?string $quantity, // per_day/per_hour: null → total elapsed days/hours
        public readonly string $rate,
        public readonly string $currency,
        public readonly string $fxRate,
        public readonly bool $commissionable,
        public readonly string $addressPct,
        public readonly string $brokeragePct,
        public readonly string $otherPct,
        public readonly bool $primary, // rate solved for in break-even
    ) {}

    public function withRate(string $rate): self
    {
        return new self($this->key, $this->categoryCode, $this->group, $this->description, $this->basis, $this->quantity, $rate,
            $this->currency, $this->fxRate, $this->commissionable, $this->addressPct, $this->brokeragePct, $this->otherPct, $this->primary);
    }
}
