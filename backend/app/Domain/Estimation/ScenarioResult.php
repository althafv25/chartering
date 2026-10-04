<?php

namespace App\Domain\Estimation;

use App\Domain\Estimation\Strategy\PnL;
use App\Support\Decimal as D;

/** Calculation output. toArray() applies storage rounding (half-up) once. */
final class ScenarioResult
{
    /**
     * @param  list<array{ref:string,label:string,formula:string,value:string|null}>  $trace
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $version,
        public readonly string $inputsHash,
        public readonly EngineComponents $c,
        public readonly PnL $pnl,
        public readonly ?string $breakevenRate,
        public readonly ?string $breakevenBasis,
        public readonly ?string $breakevenItem,
        public readonly array $trace,
        public readonly array $warnings,
        public readonly int $moneyScale,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $m = fn (?string $v) => $v === null ? null : D::round($v, $this->moneyScale);
        $c = $this->c;

        return [
            'calculation_version' => $this->version,
            'inputs_hash' => $this->inputsHash,
            'sea_distance_nm' => D::round($c->seaDistanceNm, 2),
            'eca_distance_nm' => D::round($c->ecaDistanceNm, 2),
            'sea_days' => D::round($c->seaDays, 6),
            'eca_sea_days' => D::round($c->ecaSeaDays, 6),
            'port_days' => D::round($c->portDays, 6),
            'total_days' => D::round($c->totalDays, 6),
            'fuel_total_mt' => D::round($c->fuelTotalMt, 3),
            'fuel_cost' => $m($c->cost('fuel')),
            'port_costs' => $m($c->cost('port')),
            'agency_costs' => $m($c->cost('agency')),
            'canal_costs' => $m($c->cost('canal')),
            'other_costs' => $m($c->cost('other')),
            'operational_costs' => $m($c->cost('operational')),
            'tonnage_cost' => $m($this->pnl->tonnageCost),
            'gross_revenue' => $m($c->grossRevenue),
            'total_commission' => $m($c->commission),
            'net_revenue' => $m($c->netRevenue),
            'voyage_costs' => $m($this->pnl->voyageCosts),
            'total_costs' => $m($this->pnl->totalCosts),
            'profit' => $m($this->pnl->profit),
            'profit_margin_pct' => $this->pnl->marginPct === null ? null : D::round($this->pnl->marginPct, 4),
            'profit_per_day' => $m($this->pnl->profitPerDay),
            'tce_per_day' => $m($this->pnl->tcePerDay),
            'breakeven_rate' => $this->breakevenRate === null ? null : D::round($this->breakevenRate, 4),
            'breakeven_basis' => $this->breakevenBasis,
            'breakeven_item' => $this->breakevenItem,
            'breakdown' => [
                'fuel' => array_values(array_map(fn ($f, $id) => ['fuel_type_id' => $id, 'code' => $f['code'],
                    'sea_mt' => D::round($f['sea'], 3), 'port_mt' => D::round($f['port'], 3), 'dp_mt' => D::round($f['dp'], 3),
                    'standby_mt' => D::round($f['standby'], 3), 'total_mt' => D::round($f['total'], 3), 'cost' => $m($f['cost']), 'account' => $f['account']],
                    $c->fuel, array_keys($c->fuel))),
                'legs' => array_map(fn ($l) => array_map(fn ($v) => is_string($v) && is_numeric($v) ? D::trim($v) : $v, $l), $c->legLines),
                'calls' => array_map(fn ($l) => array_map(fn ($v) => is_string($v) && is_numeric($v) ? D::trim($v) : $v, $l), $c->callLines),
                'revenue' => array_map(fn ($r) => [...$r, 'quantity' => D::trim($r['quantity']), 'amount' => $m($r['amount']), 'commission' => $m($r['commission'])], $c->revenueLines),
                'costs' => array_map(fn ($x) => [...$x, 'amount' => $m($x['amount'])], $c->costLines),
                'charterer_account' => array_map($m, $c->chartererCosts),
            ],
            'trace' => $this->trace,
            'warnings' => $this->warnings,
        ];
    }
}
