<?php

namespace App\Domain\Estimation;

use App\Domain\Estimation\Input\CallInput;
use App\Domain\Estimation\Input\ConsumptionInput;
use App\Domain\Estimation\Input\CostItemInput;
use App\Domain\Estimation\Input\Money;
use App\Domain\Estimation\Input\ScenarioInput;
use App\Support\Decimal as D;

/**
 * Time, fuel, revenue and cost components (docs/08 §E1–E12).
 * Pure: no database, no HTTP, no clock; bcmath only; no intermediate rounding.
 */
final class VoyageEngine
{
    /** Expense-category groups → cost bucket. Unlisted groups fall into "other". */
    private const BUCKETS = ['bunker' => 'fuel', 'port' => 'port', 'agency' => 'agency', 'canal' => 'canal', 'tonnage' => 'tonnage', 'operational' => 'operational'];

    /** Port/offshore day components → consumption mode (BR-EST-07: waiting uses idle consumption). */
    private const CALL_MODES = [
        ['workingDays', 'port_working', 'port', 'working'],
        ['idleDays', 'port_idle', 'port', 'idle'],
        ['waitingDays', 'port_idle', 'port', 'waiting'],
        ['dpDays', 'dp_operation', 'dp', 'DP'],
        ['standbyDays', 'standby', 'standby', 'standby'],
    ];

    /** @throws InvalidScenarioInput when consumption or prices needed for the result are missing */
    public function run(ScenarioInput $in, Trace $t): EngineComponents
    {
        $issues = [];
        $fuel = [];
        $addFuel = function (int $fuelId, string $code, string $bucket, string $qty) use (&$fuel) {
            $fuel[$fuelId] ??= ['code' => $code, 'sea' => '0', 'port' => '0', 'dp' => '0', 'standby' => '0'];
            $fuel[$fuelId][$bucket] = D::add($fuel[$fuelId][$bucket], $qty);
        };
        $codeOf = function (int $fuelId) use ($in): string {
            foreach ($in->consumption as $c) {
                if ($c->fuelTypeId === $fuelId) {
                    return $c->fuelCode;
                }
            }

            return $in->fuelPrices[$fuelId]->fuelCode ?? "#{$fuelId}";
        };

        // ── E1–E6: legs ──────────────────────────────────────────
        $seaDist = '0';
        $ecaDist = '0';
        $seaDays = '0';
        $ecaDays = '0';
        $legLines = [];
        foreach ($in->legs as $i => $leg) {
            $n = $i + 1;
            $seaDist = D::add($seaDist, $leg->distanceNm);
            $ecaDist = D::add($ecaDist, $leg->ecaDistanceNm);
            if (D::isZero($leg->distanceNm)) {
                $legLines[] = ['label' => $leg->label, 'distance_nm' => '0', 'days' => '0'];

                continue;
            }
            $margin = $leg->seaMarginPct ?? $in->seaMarginPct;
            // BR-EST-01: sea margin applies to sea TIME.
            // Multiply before dividing so exact cases stay exact under bcmath truncation.
            $hours = D::mul($leg->speedKn, '24');
            $factor = D::div(D::add('100', $margin), '100');
            $base = D::div($leg->distanceNm, $hours);
            $adj = D::div(D::mul($leg->distanceNm, $factor), $hours);
            $legEca = D::div(D::mul($leg->ecaDistanceNm, $factor), $hours);
            $seaDays = D::add($seaDays, $adj);
            $ecaDays = D::add($ecaDays, $legEca);
            $t->add("E2.leg{$n}", "{$leg->label}: base sea days", "{$this->n($leg->distanceNm)} NM / ({$this->n($leg->speedKn)} kn × 24)", $base);
            $t->add("E2.leg{$n}.margin", "{$leg->label}: sea days incl. {$this->n($margin)}% margin", "{$this->n($base)} × (1 + {$this->n($margin)}/100)", $adj);

            $mode = 'sea_'.$leg->condition;
            $rows = array_filter($in->consumption, fn (ConsumptionInput $c) => $c->mode === $mode && D::cmp($c->speedKn, $leg->speedKn) === 0);
            if (! $rows) {
                $issues[] = "{$leg->label}: no {$mode} consumption at {$this->n($leg->speedKn)} kn. Add a consumption row for this speed.";
            }
            foreach ($rows as $row) {
                $qty = D::mul($adj, $row->mtPerDay);
                if (! D::isZero($legEca) && ! $row->ecaCompliant) {
                    if ($in->ecaFuelTypeId !== null) {
                        // Assumption A-ECA-1: non-compliant fuel is replaced 1:1 (MT) by the ECA fuel inside ECA.
                        $ecaQty = D::mul($legEca, $row->mtPerDay);
                        $addFuel($row->fuelTypeId, $row->fuelCode, 'sea', D::sub($qty, $ecaQty));
                        $addFuel($in->ecaFuelTypeId, $codeOf($in->ecaFuelTypeId), 'sea', $ecaQty);
                        $t->add("E6.leg{$n}.{$row->fuelCode}.eca", "{$leg->label}: {$row->fuelCode} switched to ECA fuel", "{$this->n($legEca)} ECA days × {$this->n($row->mtPerDay)} MT/day", $ecaQty);
                    } else {
                        $addFuel($row->fuelTypeId, $row->fuelCode, 'sea', $qty);
                        $t->warn("{$leg->label}: ECA distance present but no ECA fuel selected — non-compliant {$row->fuelCode} is costed throughout.");
                    }
                } else {
                    $addFuel($row->fuelTypeId, $row->fuelCode, 'sea', $qty);
                }
                $t->add("E6.leg{$n}.{$row->fuelCode}", "{$leg->label}: {$row->fuelCode} at sea", "{$this->n($adj)} days × {$this->n($row->mtPerDay)} MT/day", $qty);
            }
            $legLines[] = ['label' => $leg->label, 'condition' => $leg->condition, 'distance_nm' => $leg->distanceNm, 'eca_distance_nm' => $leg->ecaDistanceNm,
                'speed_kn' => $leg->speedKn, 'sea_margin_pct' => $margin, 'base_days' => $base, 'days' => $adj];
        }
        $t->add('E1', 'Sea distance', 'Σ leg distances', $seaDist);
        $t->add('E1.eca', 'ECA distance', 'Σ leg ECA distances', $ecaDist);
        $t->add('E3', 'Sea days', 'Σ leg sea days (incl. margin)', $seaDays);

        // ── E4/E7: port & offshore days ────────────────────────
        $portDays = '0';
        $callLines = [];
        foreach ($in->calls as $i => $call) {
            $n = $i + 1;
            $callDays = '0';
            foreach (self::CALL_MODES as [$prop, $mode, $bucket, $name]) {
                $days = $call->{$prop};
                if (D::isZero($days)) {
                    continue;
                }
                $callDays = D::add($callDays, $days);
                $rows = array_filter($in->consumption, fn (ConsumptionInput $c) => $c->mode === $mode);
                if (! $rows) {
                    $issues[] = "{$call->label}: {$this->n($days)} {$name} days but no {$mode} consumption row.";
                }
                foreach ($rows as $row) {
                    $qty = D::mul($days, $row->mtPerDay);
                    $addFuel($row->fuelTypeId, $row->fuelCode, $bucket, $qty);
                    $t->add("E7.call{$n}.{$name}.{$row->fuelCode}", "{$call->label}: {$row->fuelCode} {$name}", "{$this->n($days)} days × {$this->n($row->mtPerDay)} MT/day ({$mode})", $qty);
                }
            }
            $portDays = D::add($portDays, $callDays);
            $callLines[] = $this->callLine($call, $callDays);
        }
        $totalDays = D::add($seaDays, $portDays);
        $t->add('E4', 'Port / offshore days', 'Σ working + idle + waiting + DP + standby days', $portDays);
        $t->add('E5', 'Total elapsed days', "{$this->n($seaDays)} sea + {$this->n($portDays)} port/offshore", $totalDays);

        // ── E8: fuel quantities & cost ─────────────────────────
        $costs = ['fuel' => '0', 'port' => '0', 'agency' => '0', 'canal' => '0', 'tonnage' => '0', 'operational' => '0', 'other' => '0'];
        $charterer = $costs;
        $fuelTotal = '0';
        $fuelOut = [];
        ksort($fuel);
        foreach ($fuel as $fid => $f) {
            $total = D::add(D::add($f['sea'], $f['port']), D::add($f['dp'], $f['standby']));
            $fuelTotal = D::add($fuelTotal, $total);
            $price = $in->fuelPrices[$fid] ?? null;
            $cost = '0';
            $account = $price->account ?? 'owner';
            if (! D::isZero($total)) {
                if (! $price || $price->pricePerMt === null) {
                    $issues[] = "Bunker price missing for {$f['code']} ({$this->n($total)} MT consumed).";
                } else {
                    $cost = D::mul(D::mul($total, $price->pricePerMt), $price->fxRate);
                    $t->add("E8.{$f['code']}", "{$f['code']} cost ({$account} account)",
                        "{$this->n($total)} MT × {$this->n($price->pricePerMt)} {$price->currency}/MT × FX {$this->n($price->fxRate)}", $cost);
                    $account === 'owner' ? $costs['fuel'] = D::add($costs['fuel'], $cost) : $charterer['fuel'] = D::add($charterer['fuel'], $cost);
                }
            }
            $fuelOut[$fid] = [...$f, 'total' => $total, 'cost' => $cost, 'account' => $account];
        }
        $t->add('E6.total', 'Total fuel', 'Σ sea + port + DP + standby MT', $fuelTotal);

        if ($issues) {
            throw new InvalidScenarioInput($issues);
        }

        // ── E10: revenue & commission (per item, BR-EST-03) ─────
        $gross = '0';
        $commission = '0';
        $revenueLines = [];
        foreach ($in->revenueItems as $item) {
            [$qty, $qtyNote] = match ($item->basis) {
                'lump_sum' => ['1', 'lump sum'],
                'per_mt' => [(string) $item->quantity, "{$this->n((string) $item->quantity)} MT"],
                'per_day' => $item->quantity !== null ? [$item->quantity, "{$this->n($item->quantity)} days"] : [$totalDays, "{$this->n($totalDays)} elapsed days"],
                'per_hour' => $item->quantity !== null ? [$item->quantity, "{$this->n($item->quantity)} hours"] : [D::mul($totalDays, '24'), 'elapsed days × 24 h'],
                default => throw new \LogicException("Unknown revenue basis {$item->basis}"),
            };
            $amount = D::mul(D::mul($qty, $item->rate), $item->fxRate);
            $pct = D::add(D::add($item->addressPct, $item->brokeragePct), $item->otherPct);
            $com = $item->commissionable ? D::div(D::mul($amount, $pct), '100') : '0';
            $gross = D::add($gross, $amount);
            $commission = D::add($commission, $com);
            $t->add("E10.{$item->key}", "Revenue: {$item->description}", "{$qtyNote} × {$this->n($item->rate)} {$item->currency} × FX {$this->n($item->fxRate)}", $amount);
            $t->add("E10.{$item->key}.com", "Commission: {$item->description}", $item->commissionable ? "{$this->n($amount)} × {$this->n($pct)}%" : 'not commissionable', $com);
            $revenueLines[] = ['key' => $item->key, 'description' => $item->description, 'category_code' => $item->categoryCode, 'basis' => $item->basis,
                'quantity' => $qty, 'rate' => $item->rate, 'currency' => $item->currency, 'amount' => $amount, 'commission_pct' => $item->commissionable ? $pct : '0', 'commission' => $com, 'primary' => $item->primary];
        }
        $net = D::sub($gross, $commission);
        $t->add('E10.gross', 'Gross revenue', 'Σ revenue items', $gross);
        $t->add('E10.commission', 'Total commission', 'Σ item commissions', $commission);
        $t->add('E10.net', 'Net revenue', "{$this->n($gross)} − {$this->n($commission)}", $net);

        // ── E9: costs ──────────────────────────────────────────
        $costLines = [];
        $book = function (string $bucket, string $account, string $amount) use (&$costs, &$charterer) {
            $account === 'owner' ? $costs[$bucket] = D::add($costs[$bucket], $amount) : $charterer[$bucket] = D::add($charterer[$bucket], $amount);
        };
        foreach ($in->calls as $i => $call) {
            foreach (['port' => $call->portCost, 'agency' => $call->agencyCost] as $bucket => $money) {
                if ($money instanceof Money && ! D::isZero($money->amount)) {
                    $amount = $money->inMain();
                    $book($bucket, $money->account, $amount);
                    $t->add('E9.call'.($i + 1).".{$bucket}", "{$call->label}: {$bucket} cost ({$money->account} account)", "{$this->n($money->amount)} {$money->currency} × FX {$this->n($money->fxRate)}", $amount);
                }
            }
        }
        foreach ($in->costItems as $item) {
            $amount = $this->costAmount($item, $totalDays, $gross);
            $bucket = self::BUCKETS[$item->group] ?? 'other';
            $book($bucket, $item->account, $amount);
            $t->add("E9.{$item->key}", "Cost: {$item->description} ({$item->account} account)", $this->costFormula($item, $totalDays, $gross), $amount);
            $costLines[] = ['key' => $item->key, 'description' => $item->description, 'category_code' => $item->categoryCode, 'group' => $item->group,
                'bucket' => $bucket, 'basis' => $item->basis, 'account' => $item->account, 'amount' => $amount];
        }

        return new EngineComponents($seaDist, $ecaDist, $seaDays, $ecaDays, $portDays, $totalDays, $fuelOut, $fuelTotal,
            $costs, $charterer, $gross, $commission, $net, $revenueLines, $costLines, $legLines, $callLines);
    }

    private function costAmount(CostItemInput $c, string $totalDays, string $gross): string
    {
        return match ($c->basis) {
            'lump_sum' => D::mul($c->rate, $c->fxRate),
            'per_day' => D::mul(D::mul($c->quantity ?? $totalDays, $c->rate), $c->fxRate),
            'per_mt' => D::mul(D::mul((string) $c->quantity, $c->rate), $c->fxRate),
            'pct_of_revenue' => D::div(D::mul($gross, $c->rate), '100'),
            default => throw new \LogicException("Unknown cost basis {$c->basis}"),
        };
    }

    private function costFormula(CostItemInput $c, string $totalDays, string $gross): string
    {
        return match ($c->basis) {
            'lump_sum' => "{$this->n($c->rate)} {$c->currency} × FX {$this->n($c->fxRate)}",
            'per_day' => "{$this->n($c->quantity ?? $totalDays)} days × {$this->n($c->rate)} {$c->currency} × FX {$this->n($c->fxRate)}",
            'per_mt' => "{$this->n((string) $c->quantity)} MT × {$this->n($c->rate)} {$c->currency} × FX {$this->n($c->fxRate)}",
            'pct_of_revenue' => "{$this->n($gross)} gross revenue × {$this->n($c->rate)}%",
            default => $c->basis,
        };
    }

    /** @return array<string, mixed> */
    private function callLine(CallInput $c, string $days): array
    {
        return ['label' => $c->label, 'kind' => $c->kind, 'working_days' => $c->workingDays, 'idle_days' => $c->idleDays,
            'waiting_days' => $c->waitingDays, 'dp_days' => $c->dpDays, 'standby_days' => $c->standbyDays, 'days' => $days];
    }

    private function n(string $v): string
    {
        return Trace::n($v);
    }
}
