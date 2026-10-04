<?php

namespace App\Domain\Estimation\Input;

/** A port call or an offshore operation period. Days are per consumption mode. */
final class CallInput
{
    public function __construct(
        public readonly string $label,
        public readonly string $kind, // port|offshore
        public readonly string $workingDays,
        public readonly string $idleDays,
        public readonly string $waitingDays,
        public readonly string $dpDays,
        public readonly string $standbyDays,
        public readonly ?Money $portCost,
        public readonly ?Money $agencyCost,
    ) {}
}
