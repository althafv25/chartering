<?php

namespace App\Domain\Estimation\Strategy;

use App\Domain\Estimation\EngineComponents;
use App\Domain\Estimation\Trace;

interface EstimationStrategy
{
    public function type(): string;

    public function label(): string;

    public function pnl(EngineComponents $c, Trace $t): PnL;

    /**
     * Defaults used when a scenario is created (accounts, default revenue line).
     *
     * @return array{bunker_account:string, port_cost_account:string, revenue_category:string, revenue_basis:string}
     */
    public function defaults(): array;
}
