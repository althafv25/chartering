<?php

namespace Database\Seeders;

use App\Models\CargoType;
use App\Models\Currency;
use App\Models\DaCostCategory;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\MilestoneType;
use App\Models\OffshoreActivityType;
use App\Models\RevenueCategory;
use App\Models\VesselType;
use Illuminate\Database\Seeder;

/**
 * Baseline lookup values (idempotent, by code). Only generic industry terms —
 * no commercial or regulatory figures (emission factors, rates) are seeded.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['USD', 'US Dollar', '$', 2], ['AED', 'UAE Dirham', 'AED', 2], ['EUR', 'Euro', '€', 2], ['GBP', 'Pound Sterling', '£', 2], ['SAR', 'Saudi Riyal', 'SAR', 2]] as [$code, $name, $symbol, $dec]) {
            Currency::query()->firstOrCreate(['code' => $code], ['name' => $name, 'symbol' => $symbol, 'decimals' => $dec, 'status' => 'active']);
        }

        $psv = [
            ['key' => 'liquid_mud_m3', 'label' => 'Liquid mud', 'data_type' => 'decimal', 'unit' => 'm³', 'options' => null],
            ['key' => 'brine_m3', 'label' => 'Brine', 'data_type' => 'decimal', 'unit' => 'm³', 'options' => null],
            ['key' => 'dry_bulk_m3', 'label' => 'Dry bulk', 'data_type' => 'decimal', 'unit' => 'm³', 'options' => null],
            ['key' => 'fuel_oil_m3', 'label' => 'Fuel oil (cargo)', 'data_type' => 'decimal', 'unit' => 'm³', 'options' => null],
            ['key' => 'fresh_water_m3', 'label' => 'Fresh water', 'data_type' => 'decimal', 'unit' => 'm³', 'options' => null],
            ['key' => 'drill_water_m3', 'label' => 'Drill water', 'data_type' => 'decimal', 'unit' => 'm³', 'options' => null],
            ['key' => 'fifi_class', 'label' => 'FiFi class', 'data_type' => 'select', 'unit' => null, 'options' => ['None', 'FiFi 1', 'FiFi 2', 'FiFi 3']],
        ];
        $ahts = [
            ['key' => 'winch_pull_t', 'label' => 'Winch line pull', 'data_type' => 'decimal', 'unit' => 't', 'options' => null],
            ['key' => 'winch_brake_t', 'label' => 'Winch brake holding', 'data_type' => 'decimal', 'unit' => 't', 'options' => null],
            ['key' => 'chain_lockers_m3', 'label' => 'Chain locker capacity', 'data_type' => 'decimal', 'unit' => 'm³', 'options' => null],
            ['key' => 'rov_capable', 'label' => 'ROV capable', 'data_type' => 'bool', 'unit' => null, 'options' => null],
            ...array_slice($psv, 3, 4),
        ];
        $types = [
            ['PSV', 'Platform Supply Vessel', 'offshore', $psv], ['AHTS', 'Anchor Handling Tug Supply', 'offshore', $ahts],
            ['CREW', 'Crew Boat / Fast Supply', 'offshore', null], ['UTIL', 'Utility Vessel', 'offshore', null],
            ['DSV', 'Diving Support Vessel', 'offshore', null], ['ROVSV', 'ROV Support Vessel', 'offshore', null],
            ['SURVEY', 'Survey Vessel', 'offshore', null], ['ACCOM', 'Accommodation Barge / Liftboat', 'offshore', null],
            ['TUG', 'Tug', 'offshore', null], ['BARGE', 'Barge', 'general', null],
            ['TANKER', 'Tanker', 'tanker', null], ['BULK', 'Bulk Carrier', 'bulk', null], ['GENCARGO', 'General Cargo', 'general', null], ['OTHER', 'Other', 'other', null],
        ];
        foreach ($types as $i => [$code, $name, $cat, $schema]) {
            VesselType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'category' => $cat, 'attribute_schema' => $schema, 'sort_order' => $i, 'status' => 'active']);
        }

        foreach ([['HFO', 'Heavy Fuel Oil', 'HFO', false], ['VLSFO', 'Very Low Sulphur Fuel Oil', 'VLSFO', false], ['ULSFO', 'Ultra Low Sulphur Fuel Oil', 'ULSFO', true],
            ['LSMGO', 'Low Sulphur Marine Gas Oil', 'LSMGO', true], ['MGO', 'Marine Gas Oil', 'MGO', false], ['MDO', 'Marine Diesel Oil', 'MDO', false], ['LNG', 'Liquefied Natural Gas', 'LNG', true]] as $i => [$code, $name, $cat, $eca]) {
            FuelType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'category' => $cat, 'is_eca_compliant' => $eca, 'sort_order' => $i, 'status' => 'active']);
        }

        foreach ([['DECK', 'Deck cargo', 'mt'], ['PIPE', 'Pipes / tubulars', 'mt'], ['CONT', 'Containers / baskets', 'units'], ['MUD', 'Liquid mud', 'm3'], ['BRINE', 'Brine', 'm3'],
            ['BASEOIL', 'Base oil', 'm3'], ['FUEL', 'Fuel oil (cargo)', 'm3'], ['FW', 'Fresh water', 'm3'], ['DW', 'Drill water', 'm3'], ['CEMENT', 'Cement', 'mt'], ['BARITE', 'Barite / bentonite', 'mt'],
            ['GENERAL', 'General cargo', 'mt'], ['BULK', 'Dry bulk', 'mt'], ['LIQBULK', 'Liquid bulk', 'mt']] as $i => [$code, $name, $unit]) {
            CargoType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'unit' => $unit, 'sort_order' => $i, 'status' => 'active']);
        }

        foreach ([['SUPPLY', 'Supply run', true], ['CREWTX', 'Crew transfer', true], ['AH', 'Anchor handling', true], ['TOW', 'Towing', true], ['STANDBY', 'Standby', true],
            ['ROV', 'ROV support', true], ['DIVE', 'Diving support', true], ['SURVEY', 'Survey', true], ['CONSTR', 'Construction support', true], ['PLATFORM', 'Platform support', true],
            ['FIELD', 'Field support', true], ['MOB', 'Mobilization', true], ['DEMOB', 'Demobilization', true], ['WAIT', 'Waiting on weather', false], ['OTHER', 'Other', false]] as $i => [$code, $name, $billable]) {
            OffshoreActivityType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_billable_default' => $billable, 'sort_order' => $i, 'status' => 'active']);
        }

        $milestones = [
            ['FIXTURE', 'Fixture date', 'both', false], ['NOMINATION', 'Nomination', 'voyage', false], ['NOR_TENDERED', 'NOR tendered', 'voyage', true], ['NOR_ACCEPTED', 'NOR accepted', 'voyage', true],
            ['ARRIVAL', 'Arrival', 'both', true], ['ALL_FAST', 'All fast', 'voyage', true], ['LOAD_START', 'Loading commenced', 'voyage', true], ['LOAD_END', 'Loading completed', 'voyage', true],
            ['DEPARTURE', 'Departure', 'both', false], ['DISCH_ARRIVAL', 'Discharge port arrival', 'voyage', true], ['DISCH_START', 'Discharge commenced', 'voyage', true], ['DISCH_END', 'Discharge completed', 'voyage', true],
            ['VOYAGE_END', 'Voyage completed', 'voyage', false],
            ['MOB_START', 'Mobilization started', 'offshore', false], ['ARRIVED_LOCATION', 'Arrived offshore location', 'offshore', false], ['OPS_START', 'Operation started', 'offshore', false],
            ['STANDBY', 'Standby', 'offshore', false], ['OPS_END', 'Operation completed', 'offshore', false], ['DEMOB_START', 'Demobilization started', 'offshore', false], ['DEMOB_END', 'Demobilization completed', 'offshore', false],
        ];
        foreach ($milestones as $i => [$code, $name, $applies, $lt]) {
            MilestoneType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'applies_to' => $applies, 'is_laytime_relevant' => $lt, 'sort_order' => $i, 'status' => 'active']);
        }

        foreach ([['BUNKER', 'Bunkers', 'bunker'], ['PORT', 'Port charges', 'port'], ['AGENCY', 'Agency fees', 'agency'], ['CANAL', 'Canal dues', 'canal'], ['BROKERAGE', 'Brokerage', 'brokerage'],
            ['COMMISSION', 'Address commission', 'commission'], ['OPEX', 'Operational expense', 'operational'], ['MOB', 'Mobilization cost', 'mobilization'], ['DEMOB', 'Demobilization cost', 'demobilization'],
            ['SUPPLIER', 'Supplier expense', 'supplier'], ['TONNAGE', 'Head charter / tonnage cost', 'tonnage'], ['OTHER', 'Other expense', 'other']] as $i => [$code, $name, $group]) {
            ExpenseCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'group' => $group, 'sort_order' => $i, 'status' => 'active']);
        }

        // BR-EST-03 (confirmed): freight, hire and offshore day-rate revenue are commissionable by default;
        // other categories are configurable (default off) per category and per item.
        foreach ([['FREIGHT', 'Freight', 'freight', true], ['HIRE', 'Hire', 'hire', true], ['DAYRATE', 'Offshore day rate', 'offshore_service', true],
            ['STANDBY', 'Standby rate', 'offshore_service', false], ['MOBFEE', 'Mobilization fee', 'offshore_service', false],
            ['DEMOBFEE', 'Demobilization fee', 'offshore_service', false], ['DEMURRAGE', 'Demurrage', 'demurrage', false],
            ['BALLASTBONUS', 'Ballast bonus', 'other', false], ['OTHER', 'Other revenue', 'other', false]] as $i => [$code, $name, $group, $com]) {
            RevenueCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'group' => $group, 'is_commissionable' => $com, 'sort_order' => $i, 'status' => 'active']);
        }

        $port = ExpenseCategory::query()->where('code', 'PORT')->value('id');
        $agency = ExpenseCategory::query()->where('code', 'AGENCY')->value('id');
        foreach ([['PILOT', 'Pilotage'], ['TOWAGE', 'Towage'], ['BERTH', 'Berth / dockage'], ['AGENCY', 'Agency fee'], ['CUSTOMS', 'Customs'], ['IMMIGRATION', 'Immigration'],
            ['LAUNCH', 'Launch hire'], ['GARBAGE', 'Garbage disposal'], ['FW', 'Fresh water'], ['SECURITY', 'Security'], ['OTHER', 'Other']] as $i => [$code, $name]) {
            DaCostCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'expense_category_id' => $code === 'AGENCY' ? $agency : $port, 'sort_order' => $i, 'status' => 'active']);
        }
    }
}
