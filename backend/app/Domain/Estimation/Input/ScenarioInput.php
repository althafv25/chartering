<?php

namespace App\Domain\Estimation\Input;

use App\Domain\Estimation\InvalidScenarioInput;
use App\Support\Decimal;

/**
 * Immutable, fully-parsed calculation input. Built only from the stored
 * scenario snapshot — never from live master data.
 */
final class ScenarioInput
{
    public const TYPES = ['voyage_charter', 'time_charter', 'offshore_day_rate', 'cargo_relet'];

    /**
     * @param  list<LegInput>  $legs
     * @param  list<CallInput>  $calls
     * @param  list<ConsumptionInput>  $consumption
     * @param  array<int, FuelPriceInput>  $fuelPrices  keyed by fuel_type_id
     * @param  list<RevenueItemInput>  $revenueItems
     * @param  list<CostItemInput>  $costItems
     */
    public function __construct(
        public readonly string $type,
        public readonly string $currency,
        public readonly int $currencyDecimals,
        public readonly string $seaMarginPct,
        public readonly ?int $ecaFuelTypeId,
        public readonly array $legs,
        public readonly array $calls,
        public readonly array $consumption,
        public readonly array $fuelPrices,
        public readonly array $revenueItems,
        public readonly array $costItems,
    ) {}

    /**
     * @param  array<string, mixed>  $a  {type, currency, currency_decimals, inputs: {...}}
     *
     * @throws InvalidScenarioInput
     */
    public static function fromArray(array $a): self
    {
        $r = new InputReader;
        $ccy = strtoupper((string) ($a['currency'] ?? ''));
        $type = $r->enum($a, 'type', 'Estimation type', self::TYPES);
        $in = $a['inputs'] ?? [];

        $margin = $r->dec($in, 'sea_margin_pct', 'Sea margin %', false, '0', max: '100') ?? '0';
        $ecaFuel = isset($in['eca_fuel_type_id']) && $in['eca_fuel_type_id'] !== '' ? (int) $in['eca_fuel_type_id'] : null;

        $legs = [];
        foreach (array_values($in['legs'] ?? []) as $i => $l) {
            $label = 'Leg '.($i + 1).(isset($l['label']) && $l['label'] !== '' ? " ({$l['label']})" : '');
            $dist = $r->dec($l, 'distance_nm', "{$label}: distance") ?? '0';
            $eca = $r->dec($l, 'eca_distance_nm', "{$label}: ECA distance", false, '0') ?? '0';
            if (Decimal::cmp($eca, $dist) > 0) {
                $r->issues[] = "{$label}: ECA distance cannot exceed the leg distance.";
            }
            $speed = Decimal::isZero($dist)
                ? ($r->dec($l, 'speed_kn', "{$label}: speed", false, '0') ?? '0')
                : ($r->dec($l, 'speed_kn', "{$label}: speed", true, null, true) ?? '1');
            $legMargin = isset($l['sea_margin_pct']) && $l['sea_margin_pct'] !== '' ? $r->dec($l, 'sea_margin_pct', "{$label}: sea margin %", max: '100') : null;
            $legs[] = new LegInput($label, $r->enum($l, 'condition', "{$label}: condition", ['laden', 'ballast'], 'laden'), $dist, $eca, $speed, $legMargin);
        }

        $calls = [];
        foreach (array_values($in['calls'] ?? []) as $i => $c) {
            $label = 'Call '.($i + 1).(isset($c['label']) && $c['label'] !== '' ? " ({$c['label']})" : '');
            $days = fn (string $k, string $n) => $r->dec($c, $k, "{$label}: {$n}", false, '0') ?? '0';
            $calls[] = new CallInput(
                $label, $r->enum($c, 'kind', "{$label}: kind", ['port', 'offshore'], 'port'),
                $days('working_days', 'working days'), $days('idle_days', 'idle days'), $days('waiting_days', 'waiting days'),
                $days('dp_days', 'DP days'), $days('standby_days', 'standby days'),
                $r->money($c['port_cost'] ?? null, "{$label}: port cost", $ccy),
                $r->money($c['agency_cost'] ?? null, "{$label}: agency cost", $ccy),
            );
        }

        $consumption = [];
        foreach (array_values($in['consumption'] ?? []) as $i => $c) {
            $label = 'Consumption row '.($i + 1);
            $consumption[] = new ConsumptionInput(
                $r->enum($c, 'mode', "{$label}: mode", ConsumptionInput::MODES),
                Decimal::round($r->dec($c, 'speed_kn', "{$label}: speed", false, '0') ?? '0', 2),
                (int) ($c['fuel_type_id'] ?? 0),
                (string) ($c['fuel_code'] ?? ('#'.($c['fuel_type_id'] ?? '?'))),
                (bool) ($c['eca_compliant'] ?? false),
                $r->dec($c, 'mt_per_day', "{$label}: MT/day") ?? '0',
            );
        }

        $prices = [];
        foreach (array_values($in['fuel_prices'] ?? []) as $i => $p) {
            $fid = (int) ($p['fuel_type_id'] ?? 0);
            $label = 'Bunker price '.($p['fuel_code'] ?? ($i + 1));
            $pcy = strtoupper((string) ($p['currency'] ?? $ccy));
            $prices[$fid] = new FuelPriceInput(
                $fid, (string) ($p['fuel_code'] ?? "#{$fid}"),
                $r->dec($p, 'price_per_mt', "{$label}: price", false),
                $pcy,
                $pcy === $ccy ? ($r->dec($p, 'fx_rate', "{$label}: FX rate", false, '1', true) ?? '1') : ($r->dec($p, 'fx_rate', "{$label}: FX rate ({$pcy}→{$ccy})", true, null, true) ?? '1'),
                $r->enum($p, 'account', "{$label}: account", ['owner', 'charterer'], 'owner'),
            );
        }

        $revenue = [];
        $primaries = 0;
        foreach (array_values($in['revenue_items'] ?? []) as $i => $x) {
            $label = 'Revenue '.($i + 1).(isset($x['description']) && $x['description'] !== '' ? " ({$x['description']})" : '');
            $basis = $r->enum($x, 'basis', "{$label}: basis", RevenueItemInput::BASES);
            $qty = $r->dec($x, 'quantity', "{$label}: quantity", $basis === 'per_mt');
            $icy = strtoupper((string) ($x['currency'] ?? $ccy));
            $pcts = array_map(fn ($k) => $r->dec($x, $k, "{$label}: {$k}", false, '0', max: '100') ?? '0', ['address_pct', 'brokerage_pct', 'other_pct']);
            if (Decimal::cmp(Decimal::add(Decimal::add($pcts[0], $pcts[1]), $pcts[2]), '100') > 0) {
                $r->issues[] = "{$label}: total commission cannot exceed 100%.";
            }
            $primary = (bool) ($x['primary'] ?? false);
            $primaries += $primary ? 1 : 0;
            $revenue[] = new RevenueItemInput(
                (string) ($x['key'] ?? 'r'.($i + 1)), (string) ($x['category_code'] ?? ''), (string) ($x['group'] ?? 'other'),
                (string) ($x['description'] ?? ''), $basis, $qty,
                $r->dec($x, 'rate', "{$label}: rate") ?? '0',
                $icy,
                $icy === $ccy ? ($r->dec($x, 'fx_rate', "{$label}: FX rate", false, '1', true) ?? '1') : ($r->dec($x, 'fx_rate', "{$label}: FX rate ({$icy}→{$ccy})", true, null, true) ?? '1'),
                (bool) ($x['commissionable'] ?? false), $pcts[0], $pcts[1], $pcts[2], $primary,
            );
        }
        if ($primaries > 1) {
            $r->issues[] = 'Only one revenue item can be the break-even (primary) item.';
        }

        $costs = [];
        foreach (array_values($in['cost_items'] ?? []) as $i => $x) {
            $label = 'Cost '.($i + 1).(isset($x['description']) && $x['description'] !== '' ? " ({$x['description']})" : '');
            $basis = $r->enum($x, 'basis', "{$label}: basis", CostItemInput::BASES);
            $icy = strtoupper((string) ($x['currency'] ?? $ccy));
            $costs[] = new CostItemInput(
                (string) ($x['key'] ?? 'c'.($i + 1)), (string) ($x['category_code'] ?? ''), (string) ($x['group'] ?? 'other'),
                (string) ($x['description'] ?? ''), $basis,
                $r->dec($x, 'quantity', "{$label}: quantity", $basis === 'per_mt'),
                $r->dec($x, 'rate', "{$label}: rate", true, null, false, $basis === 'pct_of_revenue' ? '100' : null) ?? '0',
                $icy,
                $icy === $ccy || $basis === 'pct_of_revenue' ? ($r->dec($x, 'fx_rate', "{$label}: FX rate", false, '1', true) ?? '1') : ($r->dec($x, 'fx_rate', "{$label}: FX rate ({$icy}→{$ccy})", true, null, true) ?? '1'),
                $r->enum($x, 'account', "{$label}: account", ['owner', 'charterer'], 'owner'),
            );
        }

        if ($ccy === '' || strlen($ccy) !== 3) {
            $r->issues[] = 'Scenario currency is missing.';
        }

        if ($r->issues) {
            throw new InvalidScenarioInput($r->issues);
        }

        return new self($type, $ccy, (int) ($a['currency_decimals'] ?? 2), $margin, $ecaFuel, $legs, $calls, $consumption, $prices, $revenue, $costs);
    }

    public function primaryRevenueItem(): ?RevenueItemInput
    {
        foreach ($this->revenueItems as $item) {
            if ($item->primary) {
                return $item;
            }
        }

        return null;
    }

    public function withPrimaryRate(string $rate): self
    {
        $items = array_map(fn (RevenueItemInput $i) => $i->primary ? $i->withRate($rate) : $i, $this->revenueItems);

        return new self($this->type, $this->currency, $this->currencyDecimals, $this->seaMarginPct, $this->ecaFuelTypeId,
            $this->legs, $this->calls, $this->consumption, $this->fuelPrices, $items, $this->costItems);
    }

    /**
     * Canonical hash of everything that affects the result (stored as inputs_hash).
     *
     * @param  array<string, mixed>  $a
     */
    public static function hashOf(array $a): string
    {
        $canon = function ($v) use (&$canon) {
            if (is_array($v)) {
                if (array_is_list($v)) {
                    return array_map($canon, $v);
                }
                ksort($v);

                return array_map($canon, $v);
            }

            return $v;
        };

        return hash('sha256', json_encode($canon($a), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
