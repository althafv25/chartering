<?php

namespace Tests\Unit\Domain;

use App\Domain\Estimation\EstimationCalculator;
use App\Domain\Estimation\Input\ScenarioInput;
use App\Domain\Estimation\InvalidScenarioInput;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases are hand-calculated (docs/08). Values are exact strings.
 */
class EstimationCalculatorTest extends TestCase
{
    private const VLSFO = 2;

    private const MGO = 5;

    private const LSMGO = 4;

    /** EST-TC-01 (docs/08): voyage charter, no margin. */
    private function tc01(array $override = []): array
    {
        return array_replace_recursive([
            'type' => 'voyage_charter', 'currency' => 'USD', 'currency_decimals' => 2,
            'inputs' => [
                'sea_margin_pct' => '0',
                'legs' => [
                    ['label' => 'Ballast', 'condition' => 'ballast', 'distance_nm' => '1200', 'speed_kn' => '12'],
                    ['label' => 'Laden', 'condition' => 'laden', 'distance_nm' => '2400', 'speed_kn' => '12'],
                ],
                'calls' => [
                    ['label' => 'Load', 'kind' => 'port', 'working_days' => '2', 'port_cost' => ['amount' => '30000']],
                    ['label' => 'Discharge', 'kind' => 'port', 'working_days' => '2', 'port_cost' => ['amount' => '30000']],
                ],
                'consumption' => [
                    ['mode' => 'sea_laden', 'speed_kn' => '12', 'fuel_type_id' => self::VLSFO, 'fuel_code' => 'VLSFO', 'mt_per_day' => '20'],
                    ['mode' => 'sea_ballast', 'speed_kn' => '12', 'fuel_type_id' => self::VLSFO, 'fuel_code' => 'VLSFO', 'mt_per_day' => '20'],
                    ['mode' => 'port_working', 'fuel_type_id' => self::MGO, 'fuel_code' => 'MGO', 'mt_per_day' => '3'],
                    ['mode' => 'port_idle', 'fuel_type_id' => self::MGO, 'fuel_code' => 'MGO', 'mt_per_day' => '1.5'],
                ],
                'fuel_prices' => [
                    ['fuel_type_id' => self::VLSFO, 'fuel_code' => 'VLSFO', 'price_per_mt' => '600'],
                    ['fuel_type_id' => self::MGO, 'fuel_code' => 'MGO', 'price_per_mt' => '800'],
                ],
                'revenue_items' => [['key' => 'r1', 'description' => 'Freight', 'basis' => 'per_mt', 'quantity' => '50000', 'rate' => '15',
                    'commissionable' => true, 'address_pct' => '3.75', 'brokerage_pct' => '1.25', 'primary' => true]],
                'cost_items' => [],
            ],
        ], $override);
    }

    /** @return array<string, mixed> */
    private function calc(array $a): array
    {
        return (new EstimationCalculator)->calculate(ScenarioInput::fromArray($a), ScenarioInput::hashOf($a))->toArray();
    }

    public function test_golden_voyage_charter_est_tc_01(): void
    {
        $r = $this->calc($this->tc01());

        $this->assertSame('3600.00', $r['sea_distance_nm']);
        $this->assertSame('12.500000', $r['sea_days']);          // 1200/288 + 2400/288
        $this->assertSame('4.000000', $r['port_days']);
        $this->assertSame('16.500000', $r['total_days']);
        $this->assertSame('262.000', $r['fuel_total_mt']);        // 250 VLSFO + 12 MGO
        $this->assertSame('159600.00', $r['fuel_cost']);          // 250×600 + 12×800
        $this->assertSame('60000.00', $r['port_costs']);
        $this->assertSame('750000.00', $r['gross_revenue']);
        $this->assertSame('37500.00', $r['total_commission']);    // 5 %
        $this->assertSame('712500.00', $r['net_revenue']);
        $this->assertSame('219600.00', $r['voyage_costs']);
        $this->assertSame('492900.00', $r['profit']);
        $this->assertSame('65.7200', $r['profit_margin_pct']);
        $this->assertSame('29872.73', $r['profit_per_day']);
        $this->assertSame('29872.73', $r['tce_per_day']);
        $this->assertSame('4.6232', $r['breakeven_rate']);        // 219600 / (50000 × 0.95)
        $this->assertSame(EstimationCalculator::VERSION, $r['calculation_version']);
        $this->assertNotEmpty($r['trace']);
    }

    public function test_sea_margin_applies_to_sea_time_not_distance(): void
    {
        $r = $this->calc($this->tc01(['inputs' => ['sea_margin_pct' => '10']]));

        $this->assertSame('3600.00', $r['sea_distance_nm']);  // distance unchanged (BR-EST-01)
        $this->assertSame('13.750000', $r['sea_days']);        // 12.5 × 1.10
        $this->assertSame('17.750000', $r['total_days']);
        $this->assertSame('275.000', $r['breakdown']['fuel'][0]['sea_mt']); // 13.75 × 20
    }

    public function test_leg_margin_override_and_trace_formula(): void
    {
        $a = $this->tc01();
        $a['inputs']['legs'][0]['sea_margin_pct'] = '20';
        $r = $this->calc($a);

        $this->assertSame('13.333333', $r['sea_days']); // 1200/288×1.2 + 2400/288 = 5 + 8.333333
        $step = collect($r['trace'])->firstWhere('ref', 'E2.leg1.margin');
        $this->assertSame('5', $step['value']);
        $this->assertStringContainsString('(1 + 20/100)', $step['formula']);
    }

    public function test_port_working_idle_and_waiting_fuel(): void
    {
        $a = $this->tc01();
        $a['inputs']['calls'][0] = ['label' => 'Load', 'kind' => 'port', 'working_days' => '2', 'idle_days' => '1', 'waiting_days' => '0.5'];
        $r = $this->calc($a);

        // working 2×3 + idle 1×1.5 + waiting 0.5×1.5 (idle consumption, BR-EST-07) + discharge 2×3
        $mgo = collect($r['breakdown']['fuel'])->firstWhere('code', 'MGO');
        $this->assertSame('14.250', $mgo['port_mt']);
        $this->assertSame('5.500000', $r['port_days']);
    }

    public function test_offshore_dp_and_standby_use_dedicated_consumption(): void
    {
        $a = $this->tc01(['type' => 'offshore_day_rate']);
        $a['inputs']['consumption'][] = ['mode' => 'dp_operation', 'fuel_type_id' => self::MGO, 'fuel_code' => 'MGO', 'mt_per_day' => '7.5'];
        $a['inputs']['consumption'][] = ['mode' => 'standby', 'fuel_type_id' => self::MGO, 'fuel_code' => 'MGO', 'mt_per_day' => '2.5'];
        $a['inputs']['legs'] = [['label' => 'To field', 'condition' => 'ballast', 'distance_nm' => '120', 'speed_kn' => '12']];
        $a['inputs']['calls'] = [['label' => 'Field', 'kind' => 'offshore', 'dp_days' => '10', 'standby_days' => '2']];
        $a['inputs']['revenue_items'] = [['key' => 'd', 'description' => 'Day rate', 'basis' => 'per_day', 'rate' => '14500', 'commissionable' => true, 'brokerage_pct' => '2.5', 'primary' => true],
            ['key' => 'm', 'description' => 'Mob fee', 'basis' => 'lump_sum', 'rate' => '25000', 'commissionable' => false]];
        $r = $this->calc($a);

        $mgo = collect($r['breakdown']['fuel'])->firstWhere('code', 'MGO');
        $this->assertSame('75.000', $mgo['dp_mt']);
        $this->assertSame('5.000', $mgo['standby_mt']);
        $this->assertSame('12.416667', $r['total_days']);              // 0.416667 sea + 12
        $this->assertSame('205041.67', $r['gross_revenue']);          // 12.416667 × 14500 + 25000
        $this->assertSame('4501.04', $r['total_commission']);         // 2.5 % of day-rate only
        // voyage costs: 8.333 MT VLSFO × 600 (sea) + 80 MT MGO × 800 (DP + standby)
        $this->assertSame('69000.00', $r['voyage_costs']);
        // TCE uses total elapsed days incl. offshore days (BR-EST-04): (200540.625 − 69000) / 12.416667
        $this->assertSame('10593.88', $r['tce_per_day']);
    }

    public function test_revenue_bases_and_per_item_commission(): void
    {
        $a = $this->tc01();
        $a['inputs']['revenue_items'] = [
            ['key' => 'a', 'description' => 'Freight', 'basis' => 'per_mt', 'quantity' => '1000', 'rate' => '10', 'commissionable' => true, 'address_pct' => '2.5', 'primary' => true],
            ['key' => 'b', 'description' => 'Hire', 'basis' => 'per_day', 'quantity' => '2', 'rate' => '1000', 'commissionable' => true, 'brokerage_pct' => '1.25'],
            ['key' => 'c', 'description' => 'Hourly', 'basis' => 'per_hour', 'quantity' => '10', 'rate' => '100', 'commissionable' => false, 'brokerage_pct' => '50'],
            ['key' => 'd', 'description' => 'Ballast bonus', 'basis' => 'lump_sum', 'rate' => '5000', 'commissionable' => false],
            ['key' => 'e', 'description' => 'EUR item', 'basis' => 'lump_sum', 'rate' => '1000', 'currency' => 'EUR', 'fx_rate' => '1.1', 'commissionable' => true, 'other_pct' => '10'],
        ];
        $r = $this->calc($a);
        $lines = collect($r['breakdown']['revenue'])->keyBy('key');

        $this->assertSame('10000.00', $lines['a']['amount']);
        $this->assertSame('250.00', $lines['a']['commission']);
        $this->assertSame('2000.00', $lines['b']['amount']);
        $this->assertSame('25.00', $lines['b']['commission']);
        $this->assertSame('1000.00', $lines['c']['amount']);
        $this->assertSame('0.00', $lines['c']['commission']);   // not commissionable despite %
        $this->assertSame('1100.00', $lines['e']['amount']);    // FX snapshot applied
        $this->assertSame('110.00', $lines['e']['commission']);
        $this->assertSame('19100.00', $r['gross_revenue']);
        $this->assertSame('385.00', $r['total_commission']);
        $this->assertSame('18715.00', $r['net_revenue']);
    }

    public function test_per_day_revenue_without_quantity_uses_total_elapsed_days(): void
    {
        $a = $this->tc01(['type' => 'time_charter']);
        $a['inputs']['revenue_items'] = [['key' => 'h', 'description' => 'Hire', 'basis' => 'per_day', 'rate' => '10000', 'commissionable' => true, 'address_pct' => '3.75', 'primary' => true]];
        $a['inputs']['fuel_prices'][0]['account'] = 'charterer';
        $a['inputs']['fuel_prices'][1]['account'] = 'charterer';
        $a['inputs']['calls'][0]['port_cost']['account'] = 'charterer';
        $a['inputs']['calls'][1]['port_cost']['account'] = 'charterer';
        $r = $this->calc($a);

        $this->assertSame('165000.00', $r['gross_revenue']);          // 16.5 days × 10 000
        $this->assertSame('0.00', $r['fuel_cost']);                   // charterer's account
        $this->assertSame('159600.00', $r['breakdown']['charterer_account']['fuel']);
        $this->assertSame('158812.50', $r['profit']);                 // 165000 − 3.75 %
        $this->assertSame('9625.00', $r['tce_per_day']);
        $this->assertSame('0.0000', $r['breakeven_rate']);            // no owner costs → break-even hire 0
    }

    public function test_costs_by_basis_including_pct_of_revenue_and_operational_excluded_from_tce(): void
    {
        $a = $this->tc01();
        $a['inputs']['cost_items'] = [
            ['key' => 'canal', 'group' => 'canal', 'description' => 'Canal', 'basis' => 'lump_sum', 'rate' => '10000'],
            ['key' => 'daily', 'group' => 'other', 'description' => 'Security', 'basis' => 'per_day', 'rate' => '100'],
            ['key' => 'mt', 'group' => 'other', 'description' => 'Cargo handling', 'basis' => 'per_mt', 'quantity' => '50000', 'rate' => '0.1'],
            ['key' => 'pct', 'group' => 'other', 'description' => 'Freight tax', 'basis' => 'pct_of_revenue', 'rate' => '2'],
            ['key' => 'opex', 'group' => 'operational', 'description' => 'Running cost', 'basis' => 'per_day', 'rate' => '1000'],
        ];
        $r = $this->calc($a);

        $this->assertSame('10000.00', $r['canal_costs']);
        $this->assertSame('21650.00', $r['other_costs']);              // 1650 + 5000 + 15000
        $this->assertSame('16500.00', $r['operational_costs']);
        $this->assertSame('251250.00', $r['voyage_costs']);           // 219600 + 10000 + 21650
        $this->assertSame('267750.00', $r['total_costs']);
        $this->assertSame('444750.00', $r['profit']);
        $this->assertSame('27954.55', $r['tce_per_day']);             // (712500 − 251250)/16.5 — OPEX excluded
        // Break-even with a %-of-revenue cost: fixed costs 252750 / (50000 × (0.95 − 0.02)) = 5.43548…
        $this->assertSame('5.4355', $r['breakeven_rate']);
    }

    public function test_break_even_profit_is_zero_at_solved_rate(): void
    {
        $a = $this->tc01();
        $r = $this->calc($a);
        $a['inputs']['revenue_items'][0]['rate'] = bcadd($r['breakeven_rate'], '0', 4);
        $atBreakEven = $this->calc($a);

        // Residual comes only from rounding the rate to 4 dp: ≤ 0.00005 × 50000 × 0.95 = 2.375
        $this->assertLessThanOrEqual(2.375, abs((float) $atBreakEven['profit']));
    }

    public function test_cargo_relet_reports_tonnage_cost_and_margin_separately(): void
    {
        $a = $this->tc01(['type' => 'cargo_relet']);
        $a['inputs']['cost_items'] = [['key' => 't', 'group' => 'tonnage', 'description' => 'Head charter hire', 'basis' => 'per_day', 'rate' => '15000']];
        $r = $this->calc($a);

        $this->assertSame('247500.00', $r['tonnage_cost']);   // 16.5 × 15 000
        $this->assertSame('219600.00', $r['voyage_costs']);   // tonnage excluded
        $this->assertSame('245400.00', $r['profit']);         // 712500 − 247500 − 219600
        $this->assertSame('29872.73', $r['tce_per_day']);     // (NR − voyage costs)/days, before tonnage (A-RELET-1)
        $this->assertNotNull(collect($r['trace'])->firstWhere('ref', 'R4'));

        // Same inputs under voyage charter: tonnage treated as a voyage cost + warning.
        $vc = $this->calc(array_replace($a, ['type' => 'voyage_charter']));
        $this->assertSame('467100.00', $vc['voyage_costs']);
        $this->assertSame('245400.00', $vc['profit']);
        $this->assertNotEmpty($vc['warnings']);
    }

    public function test_eca_substitution_moves_non_compliant_fuel_to_eca_fuel(): void
    {
        $a = $this->tc01();
        $a['inputs']['eca_fuel_type_id'] = self::LSMGO;
        $a['inputs']['legs'][1]['eca_distance_nm'] = '600'; // ¼ of laden leg
        $a['inputs']['fuel_prices'][] = ['fuel_type_id' => self::LSMGO, 'fuel_code' => 'LSMGO', 'price_per_mt' => '900'];
        $r = $this->calc($a);
        $fuel = collect($r['breakdown']['fuel'])->keyBy('code');

        $this->assertSame('2.083333', $r['eca_sea_days']);   // 8.333333 × 600/2400
        $this->assertSame('41.667', $fuel['LSMGO']['sea_mt']);
        $this->assertSame('208.333', $fuel['VLSFO']['sea_mt']);
        $this->assertSame('250.000', bcadd($fuel['LSMGO']['sea_mt'], $fuel['VLSFO']['sea_mt'], 3));
    }

    public function test_missing_consumption_or_price_reports_issues_instead_of_zero(): void
    {
        $a = $this->tc01();
        $a['inputs']['legs'][0]['speed_kn'] = '11';
        unset($a['inputs']['fuel_prices'][1]);
        try {
            $this->calc($a);
            $this->fail('Expected InvalidScenarioInput');
        } catch (InvalidScenarioInput $e) {
            $this->assertStringContainsString('no sea_ballast consumption at 11 kn', implode(' ', $e->issues));
            $this->assertStringContainsString('Bunker price missing for MGO', implode(' ', $e->issues));
        }
    }

    public function test_input_validation(): void
    {
        $a = $this->tc01();
        $a['inputs']['legs'][0]['speed_kn'] = '0';
        $a['inputs']['legs'][1]['eca_distance_nm'] = '3000';
        $a['inputs']['revenue_items'][0]['address_pct'] = '99';
        $a['inputs']['revenue_items'][0]['brokerage_pct'] = '5';
        $a['inputs']['revenue_items'][] = ['key' => 'x', 'basis' => 'lump_sum', 'rate' => '1', 'primary' => true];
        $a['inputs']['revenue_items'][] = ['key' => 'y', 'basis' => 'lump_sum', 'rate' => '1', 'currency' => 'EUR'];
        try {
            ScenarioInput::fromArray($a);
            $this->fail('Expected InvalidScenarioInput');
        } catch (InvalidScenarioInput $e) {
            $all = implode(' | ', $e->issues);
            $this->assertStringContainsString('speed must be greater than zero', $all);
            $this->assertStringContainsString('ECA distance cannot exceed', $all);
            $this->assertStringContainsString('total commission cannot exceed 100%', $all);
            $this->assertStringContainsString('Only one revenue item', $all);
            $this->assertStringContainsString('FX rate (EUR→USD) is required', $all);
        }
    }

    public function test_hash_is_order_insensitive_for_keys_and_deterministic(): void
    {
        $a = $this->tc01();
        $b = $a;
        $b['inputs'] = array_reverse($b['inputs'], true);

        $this->assertSame(ScenarioInput::hashOf($a), ScenarioInput::hashOf($b));
        $this->assertSame($this->calc($a)['profit'], $this->calc($a)['profit']);
        $a['inputs']['sea_margin_pct'] = '1';
        $this->assertNotSame(ScenarioInput::hashOf($a), ScenarioInput::hashOf($b));
    }

    public function test_zero_days_gives_null_per_day_figures(): void
    {
        $a = $this->tc01();
        $a['inputs']['legs'] = [];
        $a['inputs']['calls'] = [];
        $a['inputs']['revenue_items'][0]['primary'] = false;
        $r = $this->calc($a);

        $this->assertNull($r['tce_per_day']);
        $this->assertNull($r['profit_per_day']);
        $this->assertNull($r['breakeven_rate']);
        $this->assertNotEmpty($r['warnings']);
    }
}
