<?php

namespace App\Domain\Estimation\Input;

final class LegInput
{
    public function __construct(
        public readonly string $label,
        public readonly string $condition, // laden|ballast
        public readonly string $distanceNm,
        public readonly string $ecaDistanceNm,
        public readonly string $speedKn,
        public readonly ?string $seaMarginPct, // null → scenario default
    ) {}
}
