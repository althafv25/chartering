<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Estimation;
use App\Models\EstimationScenario;
use App\Models\Fixture;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\User;
use App\Models\Vessel;
use App\Services\Chartering\ScenarioCalculationService;
use App\Support\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Customer-facing commercial documents built from explicit commercial fields only. */
class CommercialPdfService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly DocumentService $documents,
        private readonly ScenarioCalculationService $calculations,
    ) {}

    public function estimation(Estimation $estimation, EstimationScenario $scenario, User $actor): Document
    {
        $this->authorize($estimation, $actor);
        $data = DB::transaction(function () use ($estimation, $scenario) {
            $source = Estimation::query()->lockForUpdate()->with(['vessel', 'enquiry.charterer'])->findOrFail($estimation->id);
            $s = $source->scenarios()->with('result')->findOrFail($scenario->id);
            $s->setRelation('estimation', $source);
            $current = $this->calculations->isCurrent($s);
            $inputs = $s->inputs ?? [];
            $sections = [
                ['title' => 'Estimation & scenario', 'rows' => [
                    ['Estimation', $source->estimation_number], ['Type', Str::headline($source->estimation_type)],
                    ['Scenario', $s->code.' · '.$s->name], ['Selected', $s->is_selected ? 'Yes' : 'No'],
                    ['Currency', $source->currency], ['Customer', $this->company($source->enquiry?->charterer)],
                    ['Calculation', $current ? 'Calculated' : Str::headline($s->calc_status === 'calculated' ? 'stale' : $s->calc_status)],
                    ['Calculated at', $s->calculated_at ? $s->calculated_at->utc()->format('d M Y H:i').' UTC' : null],
                    ['Calculation version', $s->calculation_version],
                ]],
                ['title' => 'Vessel snapshot', 'rows' => $this->vessel($source->vessel, $s->vessel_snapshot ?? [])],
                ['title' => 'General assumptions', 'rows' => [
                    ['Sea margin', $this->number((string) ($inputs['sea_margin_pct'] ?? '0')).'%'],
                    ['ECA fuel', ! empty($inputs['eca_fuel_type_id']) ? $this->fuelLabel($inputs, (int) $inputs['eca_fuel_type_id']) : 'None'],
                ]],
            ];
            if ($current && $s->result) {
                $r = $s->result;
                $timeRows = [];
                foreach (['sea_distance_nm' => 'Sea distance (NM)', 'eca_distance_nm' => 'ECA distance (NM)',
                    'sea_days' => 'Sea days', 'eca_sea_days' => 'ECA sea days', 'port_days' => 'Port / offshore days',
                    'total_days' => 'Total days', 'fuel_total_mt' => 'Total fuel (MT)'] as $key => $label) {
                    $timeRows[] = [$label, $this->number((string) $r->getAttribute($key))];
                }
                $moneyRows = [];
                foreach (['gross_revenue' => 'Gross revenue', 'total_commission' => 'Commission', 'net_revenue' => 'Net revenue',
                    'fuel_cost' => 'Fuel cost', 'port_costs' => 'Port costs', 'agency_costs' => 'Agency costs', 'canal_costs' => 'Canal costs',
                    'other_costs' => 'Other costs', 'operational_costs' => 'Operational costs', 'tonnage_cost' => 'Tonnage costs',
                    'voyage_costs' => 'Voyage costs', 'total_costs' => 'Total costs', 'profit' => 'Profit',
                    'profit_per_day' => 'Profit / day', 'tce_per_day' => 'TCE / day', 'breakeven_rate' => 'Break-even rate'] as $key => $label) {
                    $value = $r->getAttribute($key);
                    $moneyRows[] = [$label, $value !== null ? $this->number((string) $value).' '.$source->currency : null];
                }
                $sections[] = ['title' => 'Time, distance & fuel', 'rows' => $timeRows];
                $sections[] = ['title' => 'Calculated financial summary', 'rows' => $moneyRows];
            }

            return [
                'title' => 'Estimation report', 'reference' => $source->estimation_number.'-'.$s->code,
                'status' => $source->status, 'subtitle' => 'Internal estimation report · '.($source->getAttribute('title') ?? $s->name),
                'sections' => $sections, 'landscape' => true,
                'tables' => $this->estimationTables($inputs),
                'notices' => array_merge($s->calc_issues ?? [], $current ? ($s->result?->warnings ?? []) : [
                    'No current calculated results are available. Complete and save the scenario inputs to calculate it.',
                ]),
                'itinerary' => [], 'rates' => [], 'clauses' => [], 'terms' => null, 'signatures' => false,
            ];
        });

        return $this->generate($estimation, $data, 'estimation_pdf', 'Estimation PDF', $actor);
    }

    public function offer(Offer $offer, OfferRevision $revision, User $actor): Document
    {
        $this->authorize($offer, $actor);
        $data = DB::transaction(function () use ($offer, $revision) {
            $source = Offer::query()->lockForUpdate()->with(['counterparty', 'vessel', 'enquiry.charterer', 'enquiry.broker'])->findOrFail($offer->id);
            $rev = $source->revisions()->with('scenario')->findOrFail($revision->id);
            $reference = "{$source->offer_number}-R{$rev->revision_no}";

            return [
                'title' => $rev->direction === 'inbound' ? 'Counter offer' : 'Charter offer',
                'reference' => $reference, 'status' => $rev->status,
                'subtitle' => "{$source->offer_number} · Revision {$rev->revision_no}",
                'sections' => [
                    ['title' => 'Parties', 'rows' => [
                        ['Recipient', $this->company($source->counterparty ?? $source->enquiry?->charterer)],
                        ['Charterer / customer', $this->company($source->enquiry?->charterer)],
                        ['Broker', $this->company($source->enquiry?->broker)],
                    ]],
                    ['title' => 'Vessel', 'rows' => $this->vessel($source->vessel, $rev->scenario?->vessel_snapshot ?? [])],
                    ['title' => 'Commercial terms', 'rows' => [
                        ['Rate', $this->rate($rev->rate, $rev->currency, $rev->rate_basis)],
                        ['Quantity', $this->quantity($rev->quantity, $rev->getAttribute('quantity_unit'))],
                        ['Period', $this->period($rev->getAttribute('period_days'))],
                        ['Laycan', $this->dates($rev->laycan_from, $rev->laycan_to)],
                        ['Valid until', $this->date($rev->valid_until)],
                        ['Cargo / service', $source->enquiry?->getAttribute('cargo_description')],
                    ]],
                    ['title' => 'Commissions', 'rows' => $this->commissions($rev->commissions ?? [])],
                ],
                'itinerary' => $this->itinerary($rev->ports ?? []),
                'terms' => $rev->getAttribute('terms'), 'rates' => [], 'clauses' => [], 'signatures' => false,
            ];
        });

        return $this->generate($offer, $data, 'offer_pdf', 'Offer PDF', $actor);
    }

    public function fixture(Fixture $fixture, User $actor): Document
    {
        $this->authorize($fixture, $actor);
        $data = DB::transaction(function () use ($fixture) {
            $source = Fixture::query()->lockForUpdate()->with(['vessel', 'charterer', 'revision'])->findOrFail($fixture->id);
            $parties = Company::query()->whereIn('id', array_filter([
                $source->getAttribute('owner_company_id'), $source->getAttribute('broker_company_id'),
            ]))->get()->keyBy('id');

            return [
                'title' => 'Fixture recap', 'reference' => $source->fixture_number, 'status' => $source->status,
                'subtitle' => Str::headline($source->business_type),
                'sections' => [
                    ['title' => 'Parties', 'rows' => [
                        ['Charterer / customer', $this->company($source->charterer)],
                        ['Owner', $this->company($parties->get($source->getAttribute('owner_company_id')))],
                        ['Broker', $this->company($parties->get($source->getAttribute('broker_company_id')))],
                    ]],
                    ['title' => 'Vessel', 'rows' => $this->vessel($source->vessel, $source->recap_snapshot['vessel'] ?? [])],
                    ['title' => 'Agreed commercial terms', 'rows' => [
                        ['Fixture date', $this->date($source->fixture_date)],
                        ['Rate', $this->rate($source->rate, $source->currency, $source->rate_basis)],
                        ['Quantity', $this->quantity($source->getAttribute('quantity'), $source->getAttribute('quantity_unit'))],
                        ['Period', $this->period($source->revision?->getAttribute('period_days'))],
                        ['Laycan', $this->dates($source->laycan_from, $source->laycan_to)],
                        ['Cargo / service', $source->getAttribute('cargo_description')],
                    ]],
                    ['title' => 'Commissions', 'rows' => $this->commissions($source->commissions ?? [])],
                ],
                'itinerary' => $this->itinerary($source->getAttribute('ports') ?? []),
                'terms' => $source->getAttribute('terms'), 'rates' => [], 'clauses' => [], 'signatures' => false,
            ];
        });

        return $this->generate($fixture, $data, 'fixture_pdf', 'Fixture recap PDF', $actor);
    }

    public function contract(Contract $contract, User $actor): Document
    {
        $this->authorize($contract, $actor);
        Gate::forUser($actor)->authorize(Permission::ContractsRatesView->value);
        $data = DB::transaction(function () use ($contract) {
            $source = Contract::query()->lockForUpdate()->with(['customer', 'vessel', 'fixture'])->findOrFail($contract->id);
            $version = $source->versions()->where('version_no', $source->current_version)->first();
            // Approved versions use their frozen header; drafts use the latest saved header.
            $header = array_replace($source->attributesToArray(), $version?->header_snapshot ?? []);
            $rates = $source->rates()->where('version_no', $source->current_version)->with('activityType')->get();
            $clauses = $source->clauses()->where('version_no', $source->current_version)->get();

            return [
                'title' => 'Contract', 'reference' => "{$source->contract_number}-v{$source->current_version}",
                'status' => $source->status, 'subtitle' => $header['title'] ?? $source->contract_number,
                'sections' => [
                    ['title' => 'Parties', 'rows' => [['Customer', $this->company($source->customer)]]],
                    ['title' => 'Vessel', 'rows' => $this->vessel($source->vessel)],
                    ['title' => 'Contract details', 'rows' => [
                        ['Contract number', $source->contract_number], ['Version', (string) $source->current_version],
                        ['Type', Str::headline($source->contract_type)], ['Currency', $source->currency],
                        ['Start date', $this->date($header['start_date'] ?? null)], ['End date', $this->date($header['end_date'] ?? null)],
                        ['Version effective from', $this->date($version?->effective_from)],
                        ['Payment terms', isset($header['payment_terms_days']) ? $header['payment_terms_days'].' days' : null],
                        ['Payment notes', $header['payment_terms_text'] ?? null], ['Extension options', $header['extension_options'] ?? null],
                    ]],
                    ['title' => 'Commissions', 'rows' => $this->commissions($header['commissions'] ?? $source->commissions ?? [])],
                ],
                'itinerary' => $this->itinerary($source->fixture?->getAttribute('ports') ?? []),
                'terms' => $header['terms'] ?? null,
                'rates' => $rates->map(fn ($r) => [
                    'type' => Str::headline($r->rate_type), 'description' => $r->getAttribute('description'),
                    'activity' => $r->activityType?->getAttribute('name'), 'amount' => $this->number($r->amount).' '.$r->currency,
                    'unit' => $this->basis($r->unit), 'dates' => $this->dates($r->effective_from, $r->effective_to),
                ])->all(),
                'clauses' => $clauses->map(fn ($c) => ['reference' => $c->clause_ref, 'title' => $c->title, 'body' => $c->body])->all(),
                'signatures' => true,
            ];
        });

        return $this->generate($contract, $data, 'contract_pdf', 'Contract PDF', $actor);
    }

    private function authorize(Model $parent, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAnyFor', [Document::class, $parent]);
        Gate::forUser($actor)->authorize('createFor', [Document::class, $parent]);
    }

    /** @param array<string, mixed> $data */
    private function generate(Model $parent, array $data, string $type, string $typeName, User $actor): Document
    {
        $data['company'] = collect(['name', 'address', 'tax_number', 'email', 'phone'])
            ->mapWithKeys(fn ($key) => [$key => (string) $this->settings->get("company.{$key}", '')])->all();
        $data['generated_at'] = now()->utc()->format('d M Y H:i').' UTC';
        $binary = Pdf::loadView('commercial.document-pdf', $data)
            ->setPaper('a4', ! empty($data['landscape']) ? 'landscape' : 'portrait')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false])->output();
        $documentType = DocumentType::query()->firstOrCreate(['code' => $type], [
            'name' => $typeName, 'requires_expiry' => false, 'status' => 'active',
        ]);

        return $this->documents->storeGeneratedPdf($parent, $binary, [
            'document_type_id' => $documentType->id, 'title' => $data['title'].' · '.$data['reference'],
            'document_number' => $data['reference'], 'original_filename' => $data['reference'].'.pdf',
            'remarks' => 'Generated from saved commercial terms ('.$data['status'].').',
        ], $actor);
    }

    private function company(?Company $company): ?string
    {
        if (! $company) {
            return null;
        }

        $address = implode(', ', array_filter($company->only(['address_line1', 'address_line2', 'city', 'postal_code', 'country'])));

        return implode("\n", array_filter([$company->legal_name, $address, $company->getAttribute('email')]));
    }

    /** @param array<string, mixed> $inputs @return list<array<string, mixed>> */
    private function estimationTables(array $inputs): array
    {
        $n = fn ($v) => $v !== null && $v !== '' ? $this->number((string) $v) : '—';
        $money = fn ($m) => is_array($m) ? $n($m['amount'] ?? null).' '.($m['currency'] ?? '').' · '.Str::headline($m['account'] ?? 'owner') : '—';

        return [
            ['title' => 'Sea legs', 'columns' => ['Route', 'Condition', 'Distance NM', 'ECA NM', 'Speed kn', 'Margin %'],
                'rows' => array_map(fn ($l) => [$l['label'] ?? '', Str::headline($l['condition'] ?? ''), $n($l['distance_nm'] ?? null),
                    $n($l['eca_distance_nm'] ?? null), $n($l['speed_kn'] ?? null), isset($l['sea_margin_pct']) ? $n($l['sea_margin_pct']) : 'General'], $inputs['legs'] ?? [])],
            ['title' => 'Port calls & offshore durations (days)', 'columns' => ['Call', 'Kind', 'Working', 'Idle', 'Waiting', 'DP', 'Standby'],
                'rows' => array_map(fn ($c) => [$c['label'] ?? '', Str::headline($c['kind'] ?? 'port'), $n($c['working_days'] ?? null),
                    $n($c['idle_days'] ?? null), $n($c['waiting_days'] ?? null), $n($c['dp_days'] ?? null), $n($c['standby_days'] ?? null)], $inputs['calls'] ?? [])],
            ['title' => 'Port & agency charges', 'columns' => ['Call', 'Port cost / account', 'Agency cost / account'],
                'rows' => array_map(fn ($c) => [$c['label'] ?? '', $money($c['port_cost'] ?? null), $money($c['agency_cost'] ?? null)], $inputs['calls'] ?? [])],
            ['title' => 'Consumption snapshot', 'columns' => ['Mode', 'Speed kn', 'Fuel', 'MT / day', 'ECA compliant'],
                'rows' => array_map(fn ($c) => [Str::headline($c['mode'] ?? ''), $n($c['speed_kn'] ?? null),
                    $c['fuel_code'] ?? '#'.($c['fuel_type_id'] ?? ''), $n($c['mt_per_day'] ?? null), ! empty($c['eca_compliant']) ? 'Yes' : 'No'], $inputs['consumption'] ?? [])],
            ['title' => 'Bunker prices', 'columns' => ['Fuel', 'Price / MT', 'Currency', 'FX rate', 'Account'],
                'rows' => array_map(fn ($p) => [$p['fuel_code'] ?? '#'.($p['fuel_type_id'] ?? ''), $n($p['price_per_mt'] ?? null),
                    $p['currency'] ?? '', $n($p['fx_rate'] ?? null), Str::headline($p['account'] ?? 'owner')], $inputs['fuel_prices'] ?? [])],
            ['title' => 'Revenue items', 'columns' => ['Description', 'Basis', 'Quantity', 'Rate', 'Currency', 'FX', 'Commission %', 'Break-even item'],
                'rows' => array_map(fn ($r) => [$r['description'] ?? '', $this->basis($r['basis'] ?? ''), $n($r['quantity'] ?? null),
                    $n($r['rate'] ?? null), $r['currency'] ?? '', $n($r['fx_rate'] ?? null),
                    ! empty($r['commissionable']) ? 'Address '.$n($r['address_pct'] ?? '0').' / Brokerage '.$n($r['brokerage_pct'] ?? '0').' / Other '.$n($r['other_pct'] ?? '0') : 'None',
                    ! empty($r['primary']) ? 'Yes' : 'No'], $inputs['revenue_items'] ?? [])],
            ['title' => 'Additional cost items', 'columns' => ['Description', 'Basis', 'Quantity', 'Rate', 'Currency', 'FX', 'Account'],
                'rows' => array_map(fn ($c) => [$c['description'] ?? '', $this->basis($c['basis'] ?? ''), $n($c['quantity'] ?? null),
                    $n($c['rate'] ?? null), $c['currency'] ?? '', $n($c['fx_rate'] ?? null), Str::headline($c['account'] ?? 'owner')], $inputs['cost_items'] ?? [])],
        ];
    }

    /** @param array<string, mixed> $inputs */
    private function fuelLabel(array $inputs, int $id): string
    {
        foreach (array_merge($inputs['consumption'] ?? [], $inputs['fuel_prices'] ?? []) as $row) {
            if ((int) ($row['fuel_type_id'] ?? 0) === $id && ! empty($row['fuel_code'])) {
                return $row['fuel_code'];
            }
        }

        return 'Fuel #'.$id;
    }

    /** @param array<string, mixed> $snapshot @return list<array{0:string,1:mixed}> */
    private function vessel(?Vessel $vessel, array $snapshot = []): array
    {
        return [
            ['Name', $snapshot['name'] ?? $vessel?->name],
            ['IMO number', $snapshot['imo_number'] ?? $vessel?->getAttribute('imo_number')],
            ['Flag', $vessel?->getAttribute('flag_country')],
        ];
    }

    /** @param array<string, mixed> $commissions @return list<array{0:string,1:string}> */
    private function commissions(array $commissions): array
    {
        return [
            ['Address commission', $this->number($commissions['address_pct'] ?? '0').'%'],
            ['Brokerage', $this->number($commissions['brokerage_pct'] ?? '0').'%'],
            ['Other commission', $this->number($commissions['other_pct'] ?? '0').'%'],
        ];
    }

    /** @param array<int, array<string, mixed>> $ports @return list<array<string, string>> */
    private function itinerary(array $ports): array
    {
        return array_values(array_map(fn ($p) => [
            'label' => (string) ($p['label'] ?? ''), 'purpose' => Str::headline((string) ($p['purpose'] ?? '')),
        ], $ports));
    }

    private function date(Carbon|string|null $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d M Y') : null;
    }

    private function dates(Carbon|string|null $from, Carbon|string|null $to): ?string
    {
        if (! $from && ! $to) {
            return null;
        }

        return ($this->date($from) ?? 'Open').' – '.($this->date($to) ?? 'Open');
    }

    private function number(string|int $number): string
    {
        $parts = explode('.', Decimal::trim($number));
        $parts[0] = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);

        return implode('.', $parts);
    }

    private function quantity(?string $quantity, ?string $unit): ?string
    {
        return $quantity !== null ? trim($this->number($quantity).' '.strtoupper($unit ?? '')) : null;
    }

    private function period(?string $days): ?string
    {
        return $days !== null ? $this->number($days).' days' : null;
    }

    private function rate(string $rate, string $currency, string $basis): string
    {
        return $this->number($rate).' '.$currency.' · '.$this->basis($basis);
    }

    private function basis(string $basis): string
    {
        return match ($basis) {
            'per_mt' => 'per MT', 'per_day' => 'per day', 'per_hour' => 'per hour', 'lump_sum' => 'lump sum',
            default => Str::headline($basis),
        };
    }
}
