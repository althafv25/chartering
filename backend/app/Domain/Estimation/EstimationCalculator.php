<?php

namespace App\Domain\Estimation;

use App\Domain\Estimation\Input\ScenarioInput;
use App\Domain\Estimation\Strategy\StrategyFactory;
use App\Support\Decimal as D;

/**
 * Entry point: Input DTO → pure calculation → Result DTO.
 *
 * Break-even (E14): profit is linear in the primary revenue item's rate
 * (commission and %-of-revenue costs are proportional), so
 *   P(r) = P(0) + r·(P(1) − P(0))  ⇒  r* = −P(0) / (P(1) − P(0)).
 * Both evaluations use the full engine, so every cost/commission rule is honoured exactly.
 */
final class EstimationCalculator
{
    /** Bump on any formula change; persisted with every result. */
    public const VERSION = '1.0.0';

    public function __construct(private readonly VoyageEngine $engine = new VoyageEngine) {}

    /** @throws InvalidScenarioInput */
    public function calculate(ScenarioInput $in, string $inputsHash): ScenarioResult
    {
        $strategy = StrategyFactory::for($in->type);
        $trace = new Trace;
        $components = $this->engine->run($in, $trace);
        $pnl = $strategy->pnl($components, $trace);

        [$rate, $basis, $label] = [null, null, null];
        $primary = $in->primaryRevenueItem();
        if ($primary === null) {
            $trace->warn('No break-even revenue item selected — break-even rate not calculated.');
        } elseif ($primary->basis === 'lump_sum') {
            [$basis, $label] = ['lump_sum', $primary->description];
            $rate = $this->solve($in, $strategy);
        } else {
            [$basis, $label] = [$primary->basis, $primary->description];
            $rate = $this->solve($in, $strategy);
        }

        if ($rate !== null) {
            $trace->add('E14', "Break-even rate ({$label})", "rate at which profit = 0 (linear solve on {$basis} rate)", $rate);
        } elseif ($primary !== null) {
            $trace->warn('Profit does not depend on the break-even item rate — break-even undefined.');
        }

        return new ScenarioResult(self::VERSION, $inputsHash, $components, $pnl, $rate, $basis, $label, $trace->steps(), $trace->warnings(), $in->currencyDecimals);
    }

    private function solve(ScenarioInput $in, Strategy\EstimationStrategy $strategy): ?string
    {
        $silent = new Trace(false);
        $p0 = $strategy->pnl($this->engine->run($in->withPrimaryRate('0'), $silent), $silent)->profit;
        $p1 = $strategy->pnl($this->engine->run($in->withPrimaryRate('1'), $silent), $silent)->profit;
        $slope = D::sub($p1, $p0);

        return D::isZero($slope) ? null : D::div(D::sub('0', $p0), $slope);
    }
}
