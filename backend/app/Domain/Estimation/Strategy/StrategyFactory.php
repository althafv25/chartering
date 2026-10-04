<?php

namespace App\Domain\Estimation\Strategy;

use InvalidArgumentException;

final class StrategyFactory
{
    public static function for(string $type): EstimationStrategy
    {
        return match ($type) {
            'voyage_charter' => new VoyageCharterStrategy,
            'time_charter' => new TimeCharterStrategy,
            'offshore_day_rate' => new OffshoreDayRateStrategy,
            'cargo_relet' => new CargoReletStrategy,
            default => throw new InvalidArgumentException("Unknown estimation type [{$type}]."),
        };
    }
}
