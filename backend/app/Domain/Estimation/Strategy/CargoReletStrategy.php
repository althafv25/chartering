<?php

namespace App\Domain\Estimation\Strategy;

use App\Domain\Estimation\EngineComponents;
use App\Domain\Estimation\Trace;
use App\Support\Decimal as D;

/**
 * Cargo relet: we hold the cargo (or tonnage) commitment and sub-let it.
 *   relet revenue      = revenue items (freight earned from the relet)
 *   tonnage cost       = cost items in group "tonnage" (head charter freight/hire we pay)
 *   voyage costs       = fuel + port + agency + canal + other (our account)
 *   net relet margin   = net revenue − tonnage cost − voyage costs − operational
 *   TCE                = (net revenue − voyage costs) / elapsed days — the daily earning
 *                        of the relet before tonnage cost (comparable with daily hire paid).
 * Assumption A-RELET-1 (docs/08 §R): tonnage cost is excluded from TCE.
 */
final class CargoReletStrategy extends StandardStrategy
{
    public function type(): string
    {
        return 'cargo_relet';
    }

    public function label(): string
    {
        return 'Cargo relet';
    }

    public function defaults(): array
    {
        return ['bunker_account' => 'owner', 'port_cost_account' => 'owner', 'revenue_category' => 'FREIGHT', 'revenue_basis' => 'per_mt'];
    }

    public function pnl(EngineComponents $c, Trace $t): PnL
    {
        $tonnage = $c->cost('tonnage');
        if (D::isZero($tonnage)) {
            $t->warn('No head-charter (tonnage) cost entered — the relet margin equals the voyage result.');
        }

        $voyage = '0';
        foreach (['fuel', 'port', 'agency', 'canal', 'other'] as $b) {
            $voyage = D::add($voyage, $c->cost($b));
        }
        $total = D::add(D::add($voyage, $tonnage), $c->cost('operational'));
        $margin = D::sub($c->netRevenue, $total);

        $t->add('R1', 'Relet revenue (net of commission)', 'net revenue', $c->netRevenue);
        $t->add('R2', 'Tonnage (head charter) cost', 'Σ cost items in group "tonnage"', $tonnage);
        $t->add('R3', 'Voyage costs (our account)', 'fuel + port + agency + canal + other', $voyage);
        $t->add('R4', 'Net relet margin', "{$t::n($c->netRevenue)} − {$t::n($tonnage)} tonnage − {$t::n($voyage)} voyage − {$t::n($c->cost('operational'))} operational", $margin);

        return $this->finish($c, $t, $voyage, $total, $tonnage, $margin);
    }
}
