<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\FuelType;
use App\Models\OffshoreLocation;
use App\Models\Port;
use App\Models\PortDistance;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselConsumptionProfile;
use App\Models\VesselConsumptionRate;
use App\Models\VesselStatusHistory;
use App\Models\VesselType;
use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Small, realistic local workspace for exploring the application.
 *
 * It seeds master data only (companies, ports, offshore locations, vessels with consumption profiles, distances).
 * No enquiries, estimations, offers, fixtures, contracts or voyages are seeded: create those through the application.
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
            $type = VesselType::query()->where('code', $typeCode)->firstOrFail();
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
            // Modes must be values of App\Enums\ConsumptionMode, the only ones an estimation accepts.
            foreach ([['sea_laden', 12, $fuel->id, 7.5], ['sea_ballast', 12, $fuel->id, 7.0], ['port_working', 0, $mgo->id, 1.2], ['dp_operation', 0, $mgo->id, 2.5], ['standby', 0, $mgo->id, 2.0]] as [$mode, $speed, $fuelId, $consumption]) {
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
}
