<?php

namespace App\Domain\Estimation\Strategy;

use App\Domain\Estimation\EngineComponents;
use App\Domain\Estimation\Trace;
use App\Support\Decimal as D;

/**
 * Owner-perspective P&L shared by voyage charter, time charter and offshore
 * day-rate estimations (docs/08 §E11–E13, BR-EST-04):
 *   voyage costs = fuel + port + agency + canal + other (+ tonnage, if any) — owner account
 *   total costs  = voyage costs + operational (OPEX)
 *   profit       = net revenue − total costs
 *   TCE          = (net revenue − voyage costs) / total elapsed days (sea + port/offshore)
 */
abstract class StandardStrategy implements EstimationStrategy
{
    public function pnl(EngineComponents $c, Trace $t): PnL
    {
        if (! D::isZero($c->cost('tonnage'))) {
            $t->warn('Head-charter (tonnage) costs are treated as voyage costs. Use a Cargo Relet estimation to report the relet margin separately.');
        }

        $voyage = '0';
        foreach (['fuel', 'port', 'agency', 'canal', 'other', 'tonnage'] as $b) {
            $voyage = D::add($voyage, $c->cost($b));
        }
        $total = D::add($voyage, $c->cost('operational'));
        $profit = D::sub($c->netRevenue, $total);

        $t->add('E11', 'Voyage costs (owner account)', 'fuel + port + agency + canal + other', $voyage);
        $t->add('E11.total', 'Total costs incl. operational', "{$t::n($voyage)} + {$t::n($c->cost('operational'))} operational", $total);
        $t->add('E12', 'Voyage result (profit)', "{$t::n($c->netRevenue)} net revenue − {$t::n($total)}", $profit);

        return $this->finish($c, $t, $voyage, $total, '0', $profit);
    }

    protected function finish(EngineComponents $c, Trace $t, string $voyage, string $total, string $tonnage, string $profit): PnL
    {
        $margin = D::isZero($c->grossRevenue) ? null : D::div(D::mul($profit, '100'), $c->grossRevenue);
        $perDay = D::isZero($c->totalDays) ? null : D::div($profit, $c->totalDays);
        $tce = D::isZero($c->totalDays) ? null : D::div(D::sub($c->netRevenue, $voyage), $c->totalDays);

        $t->add('E12.margin', 'Profit margin %', $margin === null ? 'no revenue' : "{$t::n($profit)} / {$t::n($c->grossRevenue)} × 100", $margin);
        $t->add('E12.per_day', 'Profit per day', $perDay === null ? 'zero days' : "{$t::n($profit)} / {$t::n($c->totalDays)} days", $perDay);
        $t->add('E13', 'TCE per day', $tce === null ? 'zero days' : "({$t::n($c->netRevenue)} − {$t::n($voyage)}) / {$t::n($c->totalDays)} days", $tce);

        return new PnL($voyage, $total, $tonnage, $profit, $margin, $perDay, $tce);
    }
}
