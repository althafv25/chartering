<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\ContractRate;
use App\Models\ContractVersion;
use App\Models\Enquiry;
use App\Models\EnquiryPort;
use App\Models\EnquiryVessel;
use App\Models\Estimation;
use App\Models\EstimationScenario;
use App\Models\Fixture;
use App\Models\FuelType;
use App\Models\OffshoreLocation;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\Port;
use App\Models\PortCall;
use App\Models\PortDistance;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselConsumptionProfile;
use App\Models\VesselConsumptionRate;
use App\Models\VesselStatusHistory;
use App\Models\Voyage;
use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Small, realistic local workspace for exploring the application.
 *
 * All records use stable DEMO identifiers and firstOrCreate, so running
 * `php artisan db:seed` again does not overwrite user-created records.
 */
class InitialDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->where('email', env('ADMIN_EMAIL', 'admin@offshore.local'))->first() ?? User::query()->first();
        $actorId = $actor?->id;

        DB::transaction(function () use ($actorId): void {
            $companies = $this->seedCompanies($actorId);
            $ports = $this->seedPorts($actorId);
            $locations = $this->seedOffshoreLocations($companies, $ports, $actorId);
            $this->seedPortAgents($ports, $companies);
            $vessels = $this->seedVessels($companies, $actorId);
            $this->seedVesselProfiles($vessels, $actorId);
            $this->seedVesselStatuses($vessels, $ports, $locations, $actorId);
            $this->seedDistances($ports, $locations, $actorId);

            [$enquiry, $estimation, $scenario] = $this->seedChartering($companies, $ports, $locations, $vessels, $actorId);
            [$fixture, $contract] = $this->seedCommercialChain($enquiry, $estimation, $scenario, $companies, $vessels, $ports, $actorId);
            $this->seedVoyage($fixture, $contract, $enquiry, $estimation, $scenario, $companies, $vessels, $ports, $locations, $actorId);
        });
    }

    /** @return array<string, Company> */
    private function seedCompanies(?int $actorId): array
    {
        $rows = [
            ['DEMO-CHARTERER', 'Gulf Offshore Energy LLC', 'Gulf Offshore', 'AE', 'Dubai', ['charterer', 'customer'], 'charterer@gulf-offshore.test'],
            ['DEMO-OWNER', 'Bluewater Marine Holdings Ltd', 'Bluewater Marine', 'AE', 'Abu Dhabi', ['owner'], 'operations@bluewater-marine.test'],
            ['DEMO-BROKER', 'Harborline Chartering FZE', 'Harborline', 'AE', 'Dubai', ['broker'], 'desk@harborline.test'],
            ['DEMO-AGENT', 'Seaway Port Agency LLC', 'Seaway Agency', 'AE', 'Fujairah', ['agent'], 'ops@seaway-agency.test'],
            ['DEMO-SUPPLIER', 'Oceanic Fuels & Services', 'Oceanic Fuels', 'AE', 'Fujairah', ['supplier'], 'commercial@oceanic-fuels.test'],
            ['DEMO-OPERATOR', 'Northstar Offshore Operations', 'Northstar Offshore', 'QA', 'Doha', ['operator', 'customer'], 'marine@northstar-offshore.test'],
            ['DEMO-SURVEYOR', 'Meridian Marine Surveyors', 'Meridian Surveyors', 'SG', 'Singapore', ['surveyor'], 'desk@meridian-surveyors.test'],
        ];

        $companies = [];
        foreach ($rows as [$code, $legalName, $tradingName, $country, $city, $roles, $email]) {
            $company = Company::query()->firstOrCreate(
                ['code' => $code],
                [
                    'legal_name' => $legalName,
                    'normalized_name' => NameNormalizer::normalize($legalName),
                    'trading_name' => $tradingName,
                    'country' => $country,
                    'city' => $city,
                    'address_line1' => 'Demo Business Centre',
                    'postal_code' => '00000',
                    'email' => $email,
                    'phone' => '+000 0000 0000',
                    'website' => 'https://example.test',
                    'vat_registered' => true,
                    'default_currency' => 'USD',
                    'payment_terms_days' => 30,
                    'status' => 'active',
                    'remarks' => 'Seeded demonstration company. Safe to edit or remove in local development.',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ],
            );

            foreach ($roles as $role) {
                $company->roles()->firstOrCreate(['role' => $role]);
            }

            Contact::query()->firstOrCreate(
                ['company_id' => $company->id, 'email' => $email],
                [
                    'first_name' => 'Demo',
                    'last_name' => 'Contact',
                    'job_title' => 'Operations Manager',
                    'department' => 'Marine Operations',
                    'phone' => '+000 0000 0000',
                    'is_primary' => true,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ],
            );

            $companies[$code] = $company;
        }

        return $companies;
    }

    /** @return array<string, Port> */
    private function seedPorts(?int $actorId): array
    {
        $rows = [
            ['AEJEA', 'Jebel Ali', 'AE', 'Dubai', 'Asia/Dubai', '25.0112', '55.0612', 14.5],
            ['AEFJR', 'Fujairah', 'AE', 'Fujairah', 'Asia/Dubai', '25.1288', '56.3265', 18.0],
            ['AEAUH', 'Abu Dhabi', 'AE', 'Abu Dhabi', 'Asia/Dubai', '24.4539', '54.3773', 13.0],
            ['QADOH', 'Doha', 'QA', 'Doha', 'Asia/Qatar', '25.2854', '51.5310', 12.0],
            ['SGSIN', 'Singapore', 'SG', 'Singapore', 'Asia/Singapore', '1.2644', '103.8200', 20.0],
            ['NLRTM', 'Rotterdam', 'NL', 'Europe', 'Europe/Amsterdam', '51.9244', '4.4777', 15.0],
        ];

        $ports = [];
        foreach ($rows as [$unlocode, $name, $country, $region, $timezone, $lat, $lon, $maxDraft]) {
            $ports[$unlocode] = Port::query()->firstOrCreate(
                ['unlocode' => $unlocode],
                [
                    'name' => $name,
                    'normalized_name' => NameNormalizer::normalize($name),
                    'country' => $country,
                    'region' => $region,
                    'timezone' => $timezone,
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'max_draft_m' => $maxDraft,
                    'max_loa_m' => 180,
                    'max_beam_m' => 32,
                    'notes' => 'Seeded demonstration port.',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ],
            );
        }

        return $ports;
    }

    /** @param array<string, Company> $companies @param array<string, Port> $ports @return array<string, OffshoreLocation> */
    private function seedOffshoreLocations(array $companies, array $ports, ?int $actorId): array
    {
        $rows = [
            ['DEMO-ALPHA', 'Alpha Field', 'Alpha Field', 'Block 12', 'DEMO-OPERATOR', 'AEFJR', '25.6600', '56.9100', 82],
            ['DEMO-OMEGA', 'Omega Platform', 'Omega Field', 'Block 7', 'DEMO-OPERATOR', 'QADOH', '25.9200', '52.6200', 96],
            ['DEMO-SIGMA', 'Sigma Wind Farm', 'Sigma Development', 'Sector 3', 'DEMO-CHARTERER', 'AEJEA', '24.1500', '54.9800', 42],
        ];

        $locations = [];
        foreach ($rows as [$code, $name, $field, $block, $operatorCode, $portCode, $lat, $lon, $depth]) {
            $locations[$code] = OffshoreLocation::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'field_name' => $field,
                    'block' => $block,
                    'operator_company_id' => $companies[$operatorCode]->id,
                    'nearest_port_id' => $ports[$portCode]->id,
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'water_depth_m' => $depth,
                    'timezone' => $ports[$portCode]->timezone,
                    'remarks' => 'Seeded demonstration offshore location.',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ],
            );
        }

        return $locations;
    }

    /** @param array<string, Port> $ports @param array<string, Company> $companies */
    private function seedPortAgents(array $ports, array $companies): void
    {
        foreach (['AEJEA' => 'DEMO-AGENT', 'AEFJR' => 'DEMO-AGENT', 'QADOH' => 'DEMO-AGENT', 'SGSIN' => 'DEMO-AGENT', 'NLRTM' => 'DEMO-AGENT'] as $portCode => $agentCode) {
            $ports[$portCode]->agents()->syncWithoutDetaching([
                $companies[$agentCode]->id => ['is_default' => true, 'remarks' => 'Seeded default demo agent'],
            ]);
        }
    }

    /** @param array<string, Company> $companies @return array<string, Vessel> */
    private function seedVessels(array $companies, ?int $actorId): array
    {
        $types = [
            'DEMO-PSV1' => ['PSV001', 'Gulf Endeavour', 'PSV', 'AE', 'DEMO-OWNER', 'DEMO-OPERATOR', 'available', 'at_port', 2018, '1200.000', '54.000', 16.2, 12],
            'DEMO-PSV2' => ['PSV002', 'Gulf Pioneer', 'PSV', 'AE', 'DEMO-OWNER', 'DEMO-OPERATOR', 'under_charter', 'sailing', 2020, '1500.000', '58.000', 16.8, 12],
            'DEMO-AHTS' => ['AHTS01', 'Northstar Guardian', 'AHTS', 'QA', 'DEMO-OWNER', 'DEMO-OPERATOR', 'available', 'at_port', 2016, '2200.000', '72.000', 15.0, 28],
            'DEMO-CREW' => ['CREW01', 'Seaway Swift', 'CREW', 'AE', 'DEMO-OWNER', 'DEMO-OPERATOR', 'available', 'at_sea', 2022, '480.000', '42.000', 28.0, 3],
            'DEMO-DSV' => ['DSV001', 'Meridian Explorer', 'DSV', 'SG', 'DEMO-OWNER', 'DEMO-OPERATOR', 'under_charter', 'offshore_operation', 2019, '1800.000', '68.000', 14.0, 24],
        ];

        $vessels = [];
        foreach ($types as $key => [$code, $name, $typeCode, $flag, $ownerCode, $managerCode, $commercial, $operational, $year, $dwt, $loa, $speed, $crew]) {
            $type = \App\Models\VesselType::query()->where('code', $typeCode)->firstOrFail();
            $vessels[$key] = Vessel::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'imo_number' => (string) (9500000 + count($vessels) + 1),
                    'mmsi' => (string) (636000000 + count($vessels) + 100),
                    'call_sign' => 'DEMO'.str_pad((string) (count($vessels) + 1), 2, '0', STR_PAD_LEFT),
                    'vessel_type_id' => $type->id,
                    'subtype' => $typeCode === 'PSV' ? 'Large PSV' : null,
                    'flag_country' => $flag,
                    'port_of_registry' => $flag === 'AE' ? 'Fujairah' : 'Doha',
                    'year_built' => $year,
                    'builder' => 'Demo Marine Works',
                    'class_society' => 'DNV',
                    'class_notation' => '✠1A1 Offshore Support Vessel',
                    'ownership_type' => 'owned',
                    'owner_company_id' => $companies[$ownerCode]->id,
                    'manager_company_id' => $companies[$managerCode]->id,
                    'commercial_manager_company_id' => $companies[$managerCode]->id,
                    'technical_manager_company_id' => $companies[$managerCode]->id,
                    'loa_m' => $loa,
                    'lbp_m' => ((float) $loa) - 3,
                    'beam_m' => 15.5,
                    'depth_m' => 7.2,
                    'summer_draft_m' => 5.8,
                    'dwt_mt' => $dwt,
                    'gt' => 1900,
                    'nt' => 700,
                    'main_engine' => 'Wartsila demo propulsion package',
                    'main_engine_power_kw' => 7200,
                    'aux_engines' => '2 x auxiliary generator',
                    'aux_engine_power_kw' => 900,
                    'propulsion' => 'Twin screw CPP',
                    'service_speed_kn' => $speed,
                    'max_speed_kn' => ((float) $speed) + 2,
                    'eco_speed_kn' => ((float) $speed) - 2,
                    'deck_area_m2' => 650,
                    'deck_strength_t_m2' => 5,
                    'bollard_pull_t' => $typeCode === 'AHTS' ? 85 : null,
                    'dp_class' => in_array($typeCode, ['PSV', 'DSV'], true) ? 'DP2' : null,
                    'crane_swl_t' => in_array($typeCode, ['PSV', 'DSV'], true) ? 10 : null,
                    'crew_capacity' => $crew,
                    'passenger_capacity' => $typeCode === 'CREW' ? 80 : 20,
                    'custom_attributes' => $typeCode === 'PSV' ? ['liquid_mud_m3' => 350, 'brine_m3' => 250, 'dry_bulk_m3' => 180, 'fuel_oil_m3' => 300, 'fresh_water_m3' => 200, 'drill_water_m3' => 300, 'fifi_class' => 'FiFi 1'] : null,
                    'commercial_status' => $commercial,
                    'operational_status' => $operational,
                    'status' => 'active',
                    'remarks' => 'Seeded demonstration vessel. Safe to edit or remove in local development.',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ],
            );
        }

        return $vessels;
    }

    /** @param array<string, Vessel> $vessels */
    private function seedVesselProfiles(array $vessels, ?int $actorId): void
    {
        $fuel = FuelType::query()->where('code', 'VLSFO')->firstOrFail();
        $mgo = FuelType::query()->where('code', 'MGO')->firstOrFail();

        foreach ($vessels as $vessel) {
            $profile = VesselConsumptionProfile::query()->firstOrCreate(
                ['vessel_id' => $vessel->id, 'name' => 'Demo design profile'],
                ['source' => 'design', 'effective_from' => '2026-01-01', 'is_default' => true, 'remarks' => 'Seeded demonstration assumptions.', 'created_by' => $actorId, 'updated_by' => $actorId],
            );
            foreach ([['sea', 12, $fuel->id, 7.5], ['port', 0, $mgo->id, 1.2], ['standby', 0, $mgo->id, 2.0]] as [$mode, $speed, $fuelId, $consumption]) {
                VesselConsumptionRate::query()->firstOrCreate(
                    ['profile_id' => $profile->id, 'mode' => $mode, 'speed_kn' => $speed, 'fuel_type_id' => $fuelId],
                    ['consumption_mt_per_day' => $consumption],
                );
            }
        }
    }

    /** @param array<string, Vessel> $vessels @param array<string, Port> $ports @param array<string, OffshoreLocation> $locations */
    private function seedVesselStatuses(array $vessels, array $ports, array $locations, ?int $actorId): void
    {
        $now = Carbon::now()->subDays(2);
        $statusRows = [
            ['DEMO-PSV1', 'commercial', 'available', $ports['AEFJR']->id, null],
            ['DEMO-PSV1', 'operational', 'at_port', $ports['AEFJR']->id, null],
            ['DEMO-PSV2', 'commercial', 'under_charter', null, $locations['DEMO-ALPHA']->id],
            ['DEMO-PSV2', 'operational', 'sailing', null, $locations['DEMO-ALPHA']->id],
            ['DEMO-AHTS', 'commercial', 'available', $ports['AEAUH']->id, null],
            ['DEMO-AHTS', 'operational', 'at_port', $ports['AEAUH']->id, null],
            ['DEMO-CREW', 'commercial', 'available', $ports['QADOH']->id, null],
            ['DEMO-CREW', 'operational', 'at_sea', null, $locations['DEMO-OMEGA']->id],
            ['DEMO-DSV', 'commercial', 'under_charter', null, $locations['DEMO-SIGMA']->id],
            ['DEMO-DSV', 'operational', 'offshore_operation', null, $locations['DEMO-SIGMA']->id],
        ];

        foreach ($statusRows as [$vesselKey, $track, $status, $portId, $locationId]) {
            VesselStatusHistory::query()->firstOrCreate(
                ['vessel_id' => $vessels[$vesselKey]->id, 'track' => $track, 'status' => $status, 'effective_from' => $now],
                ['port_id' => $portId, 'offshore_location_id' => $locationId, 'reason' => 'Initial demo data', 'changed_by' => $actorId],
            );
        }
    }

    /** @param array<string, Port> $ports @param array<string, OffshoreLocation> $locations */
    private function seedDistances(array $ports, array $locations, ?int $actorId): void
    {
        $rows = [
            [$ports['AEJEA'], $ports['AEFJR'], 72], [$ports['AEFJR'], $locations['DEMO-ALPHA'], 88],
            [$ports['QADOH'], $locations['DEMO-OMEGA'], 76], [$ports['AEJEA'], $locations['DEMO-SIGMA'], 112],
            [$ports['SGSIN'], $ports['NLRTM'], 8300],
        ];
        foreach ($rows as [$from, $to, $distance]) {
            PortDistance::query()->firstOrCreate(
                ['from_type' => 'port', 'from_id' => $from->id, 'to_type' => $to instanceof Port ? 'port' : 'location', 'to_id' => $to->id, 'route_key' => '', 'provider' => 'demo'],
                ['distance_nm' => $distance, 'eca_distance_nm' => 0, 'notes' => 'Seeded demonstration distance.', 'calculated_at' => Carbon::now(), 'created_by' => $actorId],
            );
        }
    }

    /** @return array{0: Enquiry, 1: Estimation, 2: EstimationScenario} */
    private function seedChartering(array $companies, array $ports, array $locations, array $vessels, ?int $actorId): array
    {
        $cargo = \App\Models\CargoType::query()->where('code', 'DECK')->firstOrFail();
        $now = Carbon::now();
        $enquiry = Enquiry::query()->firstOrCreate(
            ['enquiry_number' => 'ENQ-DEMO-001'],
            [
                'received_at' => $now->copy()->subDays(4), 'source' => 'direct', 'business_type' => 'voyage_charter',
                'charterer_company_id' => $companies['DEMO-CHARTERER']->id, 'broker_company_id' => $companies['DEMO-BROKER']->id,
                'cargo_type_id' => $cargo->id, 'cargo_description' => 'Offshore construction materials and deck cargo', 'quantity' => 25000,
                'quantity_unit' => 'mt', 'quantity_tolerance_pct' => 10, 'laycan_from' => $now->copy()->addDays(14)->toDateString(),
                'laycan_to' => $now->copy()->addDays(28)->toDateString(), 'rate_idea' => 300000, 'rate_basis' => 'lump_sum',
                'currency' => 'USD', 'commission_terms' => '3.75% brokerage', 'terms' => 'Demo enquiry for local workflow exploration.',
                'status' => 'evaluating', 'assigned_to' => $actorId, 'created_by' => $actorId, 'updated_by' => $actorId,
            ],
        );

        foreach ([[$ports['AEJEA']->id, null, 'load'], [null, $locations['DEMO-ALPHA']->id, 'offshore_ops'], [$ports['AEFJR']->id, null, 'discharge']] as $sequence => [$portId, $locationId, $purpose]) {
            EnquiryPort::query()->firstOrCreate(
                ['enquiry_id' => $enquiry->id, 'sequence' => $sequence + 1],
                ['port_id' => $portId, 'offshore_location_id' => $locationId, 'purpose' => $purpose, 'notes' => 'Demo route point'],
            );
        }
        foreach ([$vessels['DEMO-PSV1'], $vessels['DEMO-PSV2'], $vessels['DEMO-AHTS']] as $vessel) {
            EnquiryVessel::query()->firstOrCreate(['enquiry_id' => $enquiry->id, 'vessel_id' => $vessel->id], ['shortlist_status' => 'candidate', 'notes' => 'Demo shortlist']);
        }

        $estimation = Estimation::query()->firstOrCreate(
            ['estimation_number' => 'EST-DEMO-001'],
            ['enquiry_id' => $enquiry->id, 'estimation_type' => 'voyage_charter', 'title' => 'Demo Gulf offshore supply voyage', 'vessel_id' => $vessels['DEMO-PSV1']->id, 'currency' => 'USD', 'status' => 'submitted', 'submitted_by' => $actorId, 'submitted_at' => $now->copy()->subDay(), 'created_by' => $actorId, 'updated_by' => $actorId],
        );
        $vlsfo = FuelType::query()->where('code', 'VLSFO')->firstOrFail();
        $mgo = FuelType::query()->where('code', 'MGO')->firstOrFail();
        $freight = \App\Models\RevenueCategory::query()->where('code', 'FREIGHT')->firstOrFail();
        $bunkerCosts = \App\Models\ExpenseCategory::query()->where('code', 'BUNKER')->firstOrFail();
        $portCosts = \App\Models\ExpenseCategory::query()->where('code', 'PORT')->firstOrFail();
        $demoInputs = [
            'sea_margin_pct' => '0', 'eca_fuel_type_id' => $mgo->id,
            'legs' => [['label' => 'Jebel Ali → Alpha Field', 'from' => ['type' => 'port', 'id' => $ports['AEJEA']->id, 'label' => $ports['AEJEA']->label()], 'to' => ['type' => 'location', 'id' => $locations['DEMO-ALPHA']->id, 'label' => $locations['DEMO-ALPHA']->name], 'condition' => 'laden', 'distance_nm' => '88.00', 'eca_distance_nm' => '0', 'speed_kn' => '12.00', 'sea_margin_pct' => null, 'distance_source' => 'demo']],
            'calls' => [['label' => $ports['AEJEA']->label(), 'point' => ['type' => 'port', 'id' => $ports['AEJEA']->id, 'label' => $ports['AEJEA']->label()], 'purpose' => 'load', 'kind' => 'port', 'working_days' => '1', 'idle_days' => '0', 'waiting_days' => '0', 'dp_days' => '0', 'standby_days' => '0', 'port_cost' => ['amount' => '80000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'], 'agency_cost' => ['amount' => '15000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner']], ['label' => $locations['DEMO-ALPHA']->name, 'point' => ['type' => 'location', 'id' => $locations['DEMO-ALPHA']->id, 'label' => $locations['DEMO-ALPHA']->name], 'purpose' => 'offshore_ops', 'kind' => 'offshore', 'working_days' => '2', 'idle_days' => '0', 'waiting_days' => '0', 'dp_days' => '1', 'standby_days' => '0', 'port_cost' => ['amount' => '0', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'], 'agency_cost' => ['amount' => '0', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner']]],
            'consumption' => [['mode' => 'sea_laden', 'speed_kn' => '12.00', 'fuel_type_id' => $vlsfo->id, 'fuel_code' => 'VLSFO', 'eca_compliant' => false, 'mt_per_day' => '7.500'], ['mode' => 'port', 'speed_kn' => '0', 'fuel_type_id' => $mgo->id, 'fuel_code' => 'MGO', 'eca_compliant' => false, 'mt_per_day' => '1.200']],
            'fuel_prices' => [['fuel_type_id' => $vlsfo->id, 'fuel_code' => 'VLSFO', 'price_per_mt' => '650.00', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'], ['fuel_type_id' => $mgo->id, 'fuel_code' => 'MGO', 'price_per_mt' => '900.00', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner']],
            'revenue_items' => [['key' => 'r1', 'revenue_category_id' => $freight->id, 'category_code' => 'FREIGHT', 'group' => 'freight', 'description' => 'Voyage freight', 'basis' => 'lump_sum', 'quantity' => null, 'rate' => '300000', 'currency' => 'USD', 'fx_rate' => '1', 'commissionable' => true, 'address_pct' => '0', 'brokerage_pct' => '3.75', 'other_pct' => '0', 'primary' => true]],
            'cost_items' => [['key' => 'c1', 'expense_category_id' => $bunkerCosts->id, 'category_code' => 'BUNKER', 'group' => 'bunker', 'description' => 'Bunker fuel', 'basis' => 'lump_sum', 'quantity' => null, 'rate' => '150000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner'], ['key' => 'c2', 'expense_category_id' => $portCosts->id, 'category_code' => 'PORT', 'group' => 'port', 'description' => 'Port costs', 'basis' => 'lump_sum', 'quantity' => null, 'rate' => '80000', 'currency' => 'USD', 'fx_rate' => '1', 'account' => 'owner']],
        ];
        $scenario = EstimationScenario::query()->firstOrCreate(
            ['estimation_id' => $estimation->id, 'code' => 'A'],
            [
                'name' => 'Demo base case', 'is_selected' => true,
                'vessel_snapshot' => ['id' => $vessels['DEMO-PSV1']->id, 'code' => $vessels['DEMO-PSV1']->code, 'name' => $vessels['DEMO-PSV1']->name, 'dwt_mt' => $vessels['DEMO-PSV1']->dwt_mt],
                'inputs' => $demoInputs,
                'calc_status' => 'not_calculated', 'notes' => 'Run the estimation calculator to replace the demo assumptions.', 'created_by' => $actorId, 'updated_by' => $actorId,
            ],
        );
        if (! is_array($scenario->inputs) || ! array_key_exists('legs', $scenario->inputs)) {
            $scenario->forceFill(['inputs' => $demoInputs, 'updated_by' => $actorId])->save();
        }

        return [$enquiry, $estimation, $scenario];
    }

    /** @return array{0: Fixture, 1: Contract} */
    private function seedCommercialChain(Enquiry $enquiry, Estimation $estimation, EstimationScenario $scenario, array $companies, array $vessels, array $ports, ?int $actorId): array
    {
        $now = Carbon::now();
        $portSnapshot = [
            ['sequence' => 1, 'type' => 'port', 'id' => $ports['AEJEA']->id, 'label' => $ports['AEJEA']->label(), 'purpose' => 'load'],
            ['sequence' => 2, 'type' => 'location', 'id' => OffshoreLocation::query()->where('code', 'DEMO-ALPHA')->value('id'), 'label' => 'Alpha Field', 'purpose' => 'offshore_ops'],
            ['sequence' => 3, 'type' => 'port', 'id' => $ports['AEFJR']->id, 'label' => $ports['AEFJR']->label(), 'purpose' => 'discharge'],
        ];
        $commissions = ['address_pct' => '0.00', 'brokerage_pct' => '3.75', 'other_pct' => '0.00', 'broker_company_id' => $companies['DEMO-BROKER']->id];

        $offer = Offer::query()->firstOrCreate(
            ['offer_number' => 'OFF-DEMO-001'],
            ['enquiry_id' => $enquiry->id, 'vessel_id' => $vessels['DEMO-PSV1']->id, 'counterparty_company_id' => $companies['DEMO-CHARTERER']->id, 'status' => 'accepted', 'created_by' => $actorId],
        );
        $revision = OfferRevision::query()->firstOrCreate(
            ['offer_id' => $offer->id, 'revision_no' => 1],
            [
                'direction' => 'outbound', 'status' => 'accepted', 'estimation_scenario_id' => $scenario->id, 'rate' => 300000, 'rate_basis' => 'lump_sum', 'currency' => 'USD',
                'quantity' => 25000, 'quantity_unit' => 'mt', 'laycan_from' => $now->copy()->addDays(14)->toDateString(), 'laycan_to' => $now->copy()->addDays(28)->toDateString(),
                'ports' => $portSnapshot, 'commissions' => $commissions, 'terms' => 'Net 30 days. Demo accepted offer.', 'valid_until' => $now->copy()->addDays(10)->toDateString(),
                'decided_at' => $now->copy()->subDay(), 'decided_by' => $actorId, 'created_by' => $actorId,
            ],
        );

        $fixture = Fixture::query()->firstOrCreate(
            ['fixture_number' => 'FX-DEMO-001'],
            [
                'offer_revision_id' => $revision->id, 'estimation_scenario_id' => $scenario->id, 'enquiry_id' => $enquiry->id, 'vessel_id' => $vessels['DEMO-PSV1']->id,
                'charterer_company_id' => $companies['DEMO-CHARTERER']->id, 'owner_company_id' => $companies['DEMO-OWNER']->id, 'broker_company_id' => $companies['DEMO-BROKER']->id,
                'fixture_date' => $now->copy()->subDay()->toDateString(), 'business_type' => 'voyage_charter', 'cargo_description' => 'Offshore construction materials', 'quantity' => 25000,
                'quantity_unit' => 'mt', 'laycan_from' => $now->copy()->addDays(14)->toDateString(), 'laycan_to' => $now->copy()->addDays(28)->toDateString(), 'rate' => 300000,
                'rate_basis' => 'lump_sum', 'currency' => 'USD', 'ports' => $portSnapshot, 'commissions' => $commissions, 'terms' => 'Net 30 days.',
                'recap_snapshot' => ['source' => 'demo', 'offer_revision_id' => $revision->id, 'scenario_id' => $scenario->id], 'status' => 'approved', 'submitted_by' => $actorId,
                'submitted_at' => $now->copy()->subDays(2), 'decided_by' => $actorId, 'decided_at' => $now->copy()->subDay(), 'created_by' => $actorId, 'updated_by' => $actorId,
            ],
        );

        $contract = Contract::query()->firstOrCreate(
            ['contract_number' => 'CON-DEMO-001'],
            [
                'contract_type' => 'voyage_charter', 'title' => 'Gulf offshore supply demo contract', 'fixture_id' => $fixture->id,
                'customer_company_id' => $companies['DEMO-CHARTERER']->id, 'vessel_id' => $vessels['DEMO-PSV1']->id, 'start_date' => $now->copy()->addDays(14)->toDateString(),
                'end_date' => $now->copy()->addDays(28)->toDateString(), 'currency' => 'USD', 'payment_terms_days' => 30, 'payment_terms_text' => 'Net 30 days',
                'commissions' => $commissions, 'terms' => 'Demo contract for local workflow exploration.', 'status' => 'active', 'current_version' => 1,
                'submitted_by' => $actorId, 'submitted_at' => $now->copy()->subDays(2), 'decided_by' => $actorId, 'decided_at' => $now->copy()->subDay(),
                'activated_at' => $now->copy()->subDay(), 'created_by' => $actorId, 'updated_by' => $actorId,
            ],
        );

        ContractVersion::query()->firstOrCreate(
            ['contract_id' => $contract->id, 'version_no' => 1],
            ['effective_from' => $now->copy()->addDays(14)->toDateString(), 'header_snapshot' => ['title' => $contract->title, 'currency' => 'USD', 'payment_terms_days' => 30], 'created_by' => $actorId],
        );
        ContractRate::query()->firstOrCreate(
            ['contract_id' => $contract->id, 'version_no' => 1, 'rate_type' => 'lump_sum'],
            ['description' => 'Voyage freight', 'amount' => 300000, 'currency' => 'USD', 'unit' => 'lump_sum', 'effective_from' => $now->copy()->addDays(14)->toDateString()],
        );
        ContractClause::query()->firstOrCreate(
            ['contract_id' => $contract->id, 'version_no' => 1, 'sequence' => 1],
            ['clause_ref' => 'DEMO-01', 'title' => 'Payment terms', 'body' => 'Freight is payable within 30 days of invoice date.'],
        );

        return [$fixture, $contract];
    }

    private function seedVoyage(Fixture $fixture, Contract $contract, Enquiry $enquiry, Estimation $estimation, EstimationScenario $scenario, array $companies, array $vessels, array $ports, array $locations, ?int $actorId): void
    {
        $now = Carbon::now();
        $voyage = Voyage::query()->firstOrCreate(
            ['voyage_number' => 'VOY-DEMO-001'],
            [
                'vessel_id' => $vessels['DEMO-PSV1']->id, 'fixture_id' => $fixture->id, 'contract_id' => $contract->id, 'estimation_id' => $estimation->id,
                'estimation_scenario_id' => $scenario->id, 'conversion_type' => 'fixture', 'operation_type' => 'voyage', 'charterer_company_id' => $companies['DEMO-CHARTERER']->id,
                'currency' => 'USD', 'status' => 'sailing', 'commenced_at' => $now->copy()->subDay(), 'status_changed_at' => $now->copy()->subDay(),
                'remarks' => 'Seeded active demonstration voyage. Add port operations and finance records during testing.', 'created_by' => $actorId, 'updated_by' => $actorId,
            ],
        );

        $calls = [
            [1, $ports['AEJEA']->id, null, 'load', 'sailed', $now->copy()->subDays(2), $now->copy()->subDays(2)->addHours(2), $now->copy()->subDay()->addHours(6)],
            [2, null, $locations['DEMO-ALPHA']->id, 'offshore_ops', 'planned', $now->copy()->addHours(18), null, null],
            [3, $ports['AEFJR']->id, null, 'discharge', 'planned', $now->copy()->addDays(4), null, null],
        ];
        foreach ($calls as [$sequence, $portId, $locationId, $purpose, $status, $eta, $ata, $atd]) {
            PortCall::query()->firstOrCreate(
                ['voyage_id' => $voyage->id, 'sequence' => $sequence],
                ['port_id' => $portId, 'offshore_location_id' => $locationId, 'agent_company_id' => $companies['DEMO-AGENT']->id, 'purpose' => $purpose, 'status' => $status, 'eta' => $eta, 'ata' => $ata, 'atd' => $atd, 'created_by' => $actorId, 'updated_by' => $actorId],
            );
        }
    }
}
