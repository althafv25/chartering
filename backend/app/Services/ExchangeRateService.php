<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Exchange-rate lookup and conversion.
 *
 * Resolution for (from → to) on date D, using the latest rate dated ≤ D:
 *   1. same currency → 1
 *   2. direct pair from→to
 *   3. inverse of to→from (1 / rate)
 *   4. cross via the base currency (from→BASE × BASE→to), each leg by 2–3
 * Rates are returned rounded to 8 dp (exchange_rates scale). Transactions
 * must STORE the returned rate (snapshot); history never re-reads FX.
 */
class ExchangeRateService
{
    public const RATE_SCALE = 8;

    /** @return array{rate:string, method:string, rate_date:?string, source:?string} */
    public function resolve(string $from, string $to, CarbonInterface|string $date): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $day = Carbon::parse($date)->toDateString();

        if ($from === $to) {
            return ['rate' => Decimal::round('1', self::RATE_SCALE), 'method' => 'identity', 'rate_date' => null, 'source' => null];
        }

        $leg = $this->leg($from, $to, $day);
        if ($leg) {
            unset($leg['raw']);

            return $leg;
        }

        $base = (string) config('offshore.base_currency');
        if ($from !== $base && $to !== $base) {
            $a = $this->leg($from, $base, $day);
            $b = $this->leg($base, $to, $day);
            if ($a && $b) {
                $older = min($a['rate_date'], $b['rate_date']);

                return [
                    'rate' => Decimal::round(Decimal::mul($a['raw'], $b['raw']), self::RATE_SCALE),
                    'method' => "cross:{$base}",
                    'rate_date' => $older,
                    'source' => $a['source'] === $b['source'] ? $a['source'] : 'mixed',
                ];
            }
        }

        throw new BusinessRuleException(
            "No exchange rate is available for {$from}/{$to} on or before {$day}. Add a rate under Currencies & FX.",
            'exchange_rate_missing',
            ['currency' => ["Missing rate {$from}/{$to}"]],
            422,
        );
    }

    public function convert(string $amount, string $from, string $to, CarbonInterface|string $date, ?int $scale = null): string
    {
        $rate = $this->resolve($from, $to, $date)['rate'];
        $scale ??= Currency::query()->where('code', strtoupper($to))->value('decimals') ?? 2;

        return Decimal::round(Decimal::mul($amount, $rate), (int) $scale);
    }

    /** @return array{rate:string, raw:string, method:string, rate_date:string, source:string}|null */
    private function leg(string $from, string $to, string $day): ?array
    {
        $direct = $this->latest($from, $to, $day);
        $inverse = $this->latest($to, $from, $day);

        // Prefer the most recent of direct / inverse; on the same date prefer direct.
        if ($direct && (! $inverse || $direct->rate_date->gte($inverse->rate_date))) {
            return ['rate' => Decimal::round($direct->rate, self::RATE_SCALE), 'raw' => $direct->rate, 'method' => 'direct', 'rate_date' => $direct->rate_date->toDateString(), 'source' => $direct->source];
        }

        if ($inverse) {
            $raw = Decimal::div('1', $inverse->rate);

            return ['rate' => Decimal::round($raw, self::RATE_SCALE), 'raw' => $raw, 'method' => 'inverse', 'rate_date' => $inverse->rate_date->toDateString(), 'source' => $inverse->source];
        }

        return null;
    }

    private function latest(string $base, string $quote, string $day): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('base_currency', $base)->where('quote_currency', $quote)
            ->whereDate('rate_date', '<=', $day)
            ->orderByDesc('rate_date')->orderByRaw("source = 'manual' desc")->orderByDesc('id')
            ->first();
    }
}
