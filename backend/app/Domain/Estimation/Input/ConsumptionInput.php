<?php

namespace App\Domain\Estimation\Input;

/** One consumption snapshot row: MT/day of a fuel in a mode (speed for sea modes). */
final class ConsumptionInput
{
    public const MODES = ['sea_laden', 'sea_ballast', 'port_working', 'port_idle', 'standby', 'dp_operation', 'manoeuvring'];

    public function __construct(
        public readonly string $mode,
        public readonly string $speedKn,
        public readonly int $fuelTypeId,
        public readonly string $fuelCode,
        public readonly bool $ecaCompliant,
        public readonly string $mtPerDay,
    ) {}
}
