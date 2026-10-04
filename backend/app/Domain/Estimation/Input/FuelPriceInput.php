<?php

namespace App\Domain\Estimation\Input;

final class FuelPriceInput
{
    public function __construct(
        public readonly int $fuelTypeId,
        public readonly string $fuelCode,
        public readonly ?string $pricePerMt,
        public readonly string $currency,
        public readonly string $fxRate,
        public readonly string $account, // owner|charterer
    ) {}
}
