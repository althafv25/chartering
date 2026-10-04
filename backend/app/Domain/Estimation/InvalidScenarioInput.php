<?php

namespace App\Domain\Estimation;

use RuntimeException;

/** Inputs are incomplete or inconsistent; the calculation is not performed. */
final class InvalidScenarioInput extends RuntimeException
{
    /** @param list<string> $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct('Scenario inputs are incomplete: '.implode('; ', $issues));
    }
}
