<?php

namespace App\Services\Chartering;

use App\Domain\Estimation\Strategy\StrategyFactory;
use App\Domain\Geo\RoutePoint;
use App\Exceptions\BusinessRuleException;
use App\Integrations\Distance\StoredDistanceProvider;
use App\Models\Enquiry;
use App\Models\Estimation;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\ReferenceModel;
use App\Models\RevenueCategory;
use App\Models\Vessel;
use App\Models\VesselConsumptionProfile;
use App\Services\ExchangeRateService;
use Illuminate\Support\Str;

/**
 * The ONLY place that reads master data into a scenario. Called when a
 * scenario is created, when the user explicitly runs "Refresh defaults", and
 * when a user adds/changes an item (to snapshot that item's master values).
 * Recalculation never calls this class.
 */
class ScenarioDefaults
{
    public function __construct(
        private readonly StoredDistanceProvider $distances,
        private readonly ExchangeRateService $fx,
    ) {}

    /** @return array<string, mixed> */
    public function vesselSnapshot(Vessel $vessel): array
    {
        return [
            'id' => $vessel->id, 'code' => $vessel->code, 'name' => $vessel->name, 'imo_number' => $vessel->imo_number,
            'dwt_mt' => $vessel->getAttribute('dwt_mt'), 'service_speed_kn' => $vessel->getAttribute('service_speed_kn'),
            'eco_speed_kn' => $vessel->getAttribute('eco_speed_kn'), 'max_speed_kn' => $vessel->getAttribute('max_speed_kn'),
            'dp_class' => $vessel->getAttribute('dp_class'), 'captured_at' => now()->toIso8601String(),
        ];
    }

    public function profile(Vessel $vessel, ?int $profileId): ?VesselConsumptionProfile
    {
        if ($profileId) {
            $p = VesselConsumptionProfile::query()->with('rates.fuelType')->find($profileId);
            if (! $p || $p->vessel_id !== $vessel->id) {
                throw new BusinessRuleException('The consumption profile does not belong to this vessel.', 'invalid_profile', status: 422);
            }

            return $p;
        }

        $today = now()->toDateString();

        return VesselConsumptionProfile::query()->with('rates.fuelType')->where('vessel_id', $vessel->id)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->orderByDesc('is_default')->orderByDesc('effective_from')->first();
    }

    /** @return list<array<string, mixed>> */
    public function consumptionRows(?VesselConsumptionProfile $profile): array
    {
        return $profile ? $profile->rates->map(fn ($r) => [
            'mode' => $r->mode, 'speed_kn' => $r->speed_kn, 'fuel_type_id' => $r->fuel_type_id,
            'fuel_code' => $r->fuelType->code, 'eca_compliant' => (bool) $r->fuelType->getAttribute('is_eca_compliant'),
            'mt_per_day' => $r->consumption_mt_per_day,
        ])->values()->all() : [];
    }

    /**
     * Full default input snapshot for a new scenario.
     *
     * @return array{vessel_snapshot: array<string, mixed>, consumption_profile_id: int|null, inputs: array<string, mixed>}
     */
    public function build(Estimation $est, ?int $profileId = null): array
    {
        $vessel = Vessel::query()->findOrFail($est->vessel_id);
        $enquiry = $est->enquiry_id ? Enquiry::query()->with(['ports.port', 'ports.offshoreLocation'])->find($est->enquiry_id) : null;
        $defaults = StrategyFactory::for($est->estimation_type)->defaults();
        $profile = $this->profile($vessel, $profileId);
        $consumption = $this->consumptionRows($profile);
        $speed = $this->defaultSpeed($vessel, $consumption);
        $ccy = $est->currency;
        $money = fn (string $account) => ['amount' => '0', 'currency' => $ccy, 'fx_rate' => '1', 'account' => $account];

        // Legs & calls from the enquiry itinerary (stored distances only — never estimates, D-026).
        $legs = [];
        $calls = [];
        $ports = $enquiry?->ports ?? collect();
        $laden = false;
        foreach ($ports->values() as $i => $p) {
            $point = $p->point();
            $kind = $p->offshore_location_id || $p->purpose === 'offshore_ops' ? 'offshore' : 'port';
            $calls[] = ['label' => $point['label'], 'point' => $point, 'purpose' => $p->purpose, 'kind' => $kind,
                'working_days' => '0', 'idle_days' => '0', 'waiting_days' => '0', 'dp_days' => '0', 'standby_days' => '0',
                'port_cost' => $money($defaults['port_cost_account']), 'agency_cost' => $money($defaults['port_cost_account'])];
            $laden = match ($p->purpose) {
                'load' => true, 'discharge' => false, default => $laden,
            };
            $next = $ports->values()->get($i + 1);
            if ($next) {
                $to = $next->point();
                $hit = $this->distances->distance(new RoutePoint($point['type'], $point['id'], $point['label']), new RoutePoint($to['type'], $to['id'], $to['label']));
                $legs[] = ['label' => "{$point['label']} → {$to['label']}", 'from' => $point, 'to' => $to, 'condition' => $laden ? 'laden' : 'ballast',
                    'distance_nm' => $hit?->distanceNm, 'eca_distance_nm' => $hit?->ecaDistanceNm ?? '0', 'speed_kn' => $speed, 'sea_margin_pct' => null,
                    'distance_source' => $hit ? "stored:{$hit->provider}" : null];
            }
        }

        $fuelIds = array_values(array_unique(array_column($consumption, 'fuel_type_id')));
        $prices = array_map(fn ($id) => ['fuel_type_id' => $id, 'fuel_code' => collect($consumption)->firstWhere('fuel_type_id', $id)['fuel_code'],
            'price_per_mt' => null, 'currency' => $ccy, 'fx_rate' => '1', 'account' => $defaults['bunker_account']], $fuelIds);

        $category = RevenueCategory::query()->where('code', $defaults['revenue_category'])->first();
        $rateFromEnquiry = $enquiry && $enquiry->rate_idea !== null && $enquiry->getAttribute('rate_basis') === $defaults['revenue_basis'];
        $revenue = $category ? [[
            'key' => 'r1', 'revenue_category_id' => $category->id, 'category_code' => $category->code, 'group' => $category->getAttribute('group'),
            'description' => $category->name, 'basis' => $defaults['revenue_basis'],
            'quantity' => $defaults['revenue_basis'] === 'per_mt' ? $enquiry?->quantity : null,
            'rate' => $rateFromEnquiry ? $enquiry->rate_idea : null,
            'currency' => $ccy, 'fx_rate' => '1', 'commissionable' => (bool) $category->getAttribute('is_commissionable'),
            'address_pct' => '0', 'brokerage_pct' => '0', 'other_pct' => '0', 'primary' => true,
        ]] : [];

        $costs = [];
        if ($est->estimation_type === 'cargo_relet' && ($tonnage = ExpenseCategory::query()->where('code', 'TONNAGE')->first())) {
            $costs[] = ['key' => 'c1', 'expense_category_id' => $tonnage->id, 'category_code' => $tonnage->code, 'group' => 'tonnage',
                'description' => $tonnage->name, 'basis' => 'per_day', 'quantity' => null, 'rate' => null, 'currency' => $ccy, 'fx_rate' => '1', 'account' => 'owner'];
        }

        $ecaFuel = collect($consumption)->firstWhere('eca_compliant', true)['fuel_type_id'] ?? null;

        return [
            'vessel_snapshot' => $this->vesselSnapshot($vessel),
            'consumption_profile_id' => $profile?->id,
            'inputs' => [
                'sea_margin_pct' => '0', 'eca_fuel_type_id' => $ecaFuel, 'legs' => $legs, 'calls' => $calls, 'consumption' => $consumption,
                'fuel_prices' => $prices, 'revenue_items' => $revenue, 'cost_items' => $costs,
            ],
        ];
    }

    /**
     * Snapshot master attributes for NEW or CHANGED items only; unchanged items keep
     * their stored snapshot so saving never silently refreshes master data.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    public function snapshotItems(Estimation $est, array $previous, array $next): array
    {
        $prevCons = collect($previous['consumption'] ?? [])->keyBy('fuel_type_id');
        $fuels = FuelType::query()->whereIn('id', collect($next['consumption'] ?? [])->pluck('fuel_type_id')
            ->merge(collect($next['fuel_prices'] ?? [])->pluck('fuel_type_id'))->filter()->unique())->get()->keyBy('id');

        $next['consumption'] = array_map(function ($row) use ($prevCons, $fuels) {
            $prev = $prevCons->get($row['fuel_type_id']);
            $fuel = $fuels->get($row['fuel_type_id']);

            return [...$row,
                'fuel_code' => $prev['fuel_code'] ?? $fuel?->code ?? "#{$row['fuel_type_id']}",
                'eca_compliant' => $prev['eca_compliant'] ?? (bool) $fuel?->getAttribute('is_eca_compliant')];
        }, $next['consumption'] ?? []);

        $codeOf = fn ($id) => collect($next['consumption'])->firstWhere('fuel_type_id', $id)['fuel_code'] ?? $fuels->get($id)?->code ?? "#{$id}";
        $next['fuel_prices'] = array_map(fn ($p) => [...$p, 'fuel_code' => $codeOf($p['fuel_type_id']), 'fx_rate' => $this->fx($est, $p['currency'] ?? null, $p['fx_rate'] ?? null)],
            $next['fuel_prices'] ?? []);

        $next['revenue_items'] = $this->categorised($est, $previous['revenue_items'] ?? [], $next['revenue_items'] ?? [], 'revenue_category_id', RevenueCategory::class, true);
        $next['cost_items'] = $this->categorised($est, $previous['cost_items'] ?? [], $next['cost_items'] ?? [], 'expense_category_id', ExpenseCategory::class, false);

        $next['calls'] = array_map(function ($c) use ($est) {
            foreach (['port_cost', 'agency_cost'] as $k) {
                if (isset($c[$k]) && is_array($c[$k])) {
                    $c[$k]['fx_rate'] = $this->fx($est, $c[$k]['currency'] ?? null, $c[$k]['fx_rate'] ?? null);
                }
            }

            return $c;
        }, $next['calls'] ?? []);

        return $next;
    }

    /**
     * @param  list<array<string, mixed>>  $prev
     * @param  list<array<string, mixed>>  $items
     * @param  class-string<ReferenceModel>  $model
     * @return list<array<string, mixed>>
     */
    private function categorised(Estimation $est, array $prev, array $items, string $fk, string $model, bool $revenue): array
    {
        $prevByKey = collect($prev)->keyBy('key');
        $cats = $model::query()->whereIn('id', collect($items)->pluck($fk)->filter())->get()->keyBy('id');
        $out = [];
        foreach (array_values($items) as $i => $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === '' || collect($out)->contains('key', $key)) {
                $key = ($revenue ? 'r' : 'c').Str::lower(Str::random(8));
            }
            $old = $prevByKey->get($key);
            $same = $old && (int) ($old[$fk] ?? 0) === (int) $item[$fk];
            $cat = $cats->get($item[$fk]);
            if (! $same && ! $cat) {
                throw new BusinessRuleException('Unknown category for '.($revenue ? 'revenue' : 'cost').' item '.($i + 1).'.', 'invalid_category', status: 422);
            }
            $row = [...$item, 'key' => $key,
                'category_code' => $same ? $old['category_code'] : $cat->code,
                'group' => $same ? $old['group'] : $cat->getAttribute('group'),
                'fx_rate' => $this->fx($est, $item['currency'] ?? null, $item['fx_rate'] ?? null)];
            if ($revenue && ! array_key_exists('commissionable', $item)) {
                $row['commissionable'] = $same ? (bool) ($old['commissionable'] ?? false) : (bool) $cat->getAttribute('is_commissionable');
            }
            $out[] = $row;
        }

        return $out;
    }

    /** FX snapshot taken at save time when the user leaves it empty (D-027). */
    private function fx(Estimation $est, ?string $currency, mixed $given): ?string
    {
        $currency = strtoupper((string) ($currency ?: $est->currency));
        if ($currency === $est->currency) {
            return $given !== null && $given !== '' ? (string) $given : '1';
        }
        if ($given !== null && $given !== '') {
            return (string) $given;
        }
        try {
            return $this->fx->resolve($currency, $est->currency, now())['rate'];
        } catch (BusinessRuleException) {
            return null; // calculation reports the missing FX rate
        }
    }

    /** @param list<array<string, mixed>> $consumption */
    private function defaultSpeed(Vessel $vessel, array $consumption): ?string
    {
        $service = $vessel->getAttribute('service_speed_kn');
        $speeds = collect($consumption)->filter(fn ($r) => str_starts_with($r['mode'], 'sea_'))->pluck('speed_kn')->unique()->values();
        if ($service !== null && $speeds->contains(fn ($s) => bccomp((string) $s, (string) $service, 2) === 0)) {
            return (string) $service;
        }

        return $speeds->first() ?? $service;
    }
}
