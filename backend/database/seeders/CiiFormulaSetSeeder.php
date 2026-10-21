<?php

namespace Database\Seeders;

use App\Models\CiiFormulaSet;
use Illuminate\Database\Seeder;

/**
 * Seeds one DRAFT CII formula set from the published IMO 2023 CII Guidelines
 * (MEPC.337(76) reference lines, MEPC.338(76) rating boundaries, MEPC.336(76)
 * emission factors) so the module is usable out of the box. Status is
 * deliberately `draft`, not `verified` — per docs/CII-REQUIREMENTS.md BR-CII-02,
 * Marine Operations must confirm the figures that apply to this fleet (ship
 * types in scope, any flag/regional adjustments) before marking it `verified`
 * and relying on it for regulatory filing. Idempotent (firstOrCreate by code).
 */
class CiiFormulaSetSeeder extends Seeder
{
    public function run(): void
    {
        CiiFormulaSet::query()->firstOrCreate(
            ['code' => 'imo-2023-draft'],
            [
                'name' => 'IMO MARPOL Annex VI CII 2023 (MEPC.337(76)/MEPC.338(76)) — draft, pending Marine Operations confirmation',
                'status' => 'draft',
                'effective_date' => '2023-01-01',
                'expiration_date' => null,
                'remarks' => 'Seeded with published IMO reference figures. Confirm ship-type coverage, reduction-factor schedule and any fleet-specific adjustments before marking verified (BR-CII-01 through BR-CII-04).',
                'parameters' => [
                    // a, c per ship type (IMO MEPC.337(76), Table 1), keyed by this fleet's vessel_types.code
                    // (seeded in ReferenceDataSeeder). Offshore support types share the general-cargo-like
                    // reference line pending a fleet-specific confirmation from Marine Operations (BR-CII-01/03) —
                    // add or correct entries here once ship-type coverage is confirmed.
                    'reference_lines' => [
                        'BULK' => ['a' => 961.79, 'c' => 0.477],
                        'TANKER' => ['a' => 1218.80, 'c' => 0.488],
                        'GENCARGO' => ['a' => 107.48, 'c' => 0.216],
                        'PSV' => ['a' => 69.72, 'c' => 0.195],
                        'AHTS' => ['a' => 69.72, 'c' => 0.195],
                        'CREW' => ['a' => 69.72, 'c' => 0.195],
                        'UTIL' => ['a' => 69.72, 'c' => 0.195],
                        'DSV' => ['a' => 69.72, 'c' => 0.195],
                        'ROVSV' => ['a' => 69.72, 'c' => 0.195],
                        'SURVEY' => ['a' => 69.72, 'c' => 0.195],
                        'TUG' => ['a' => 69.72, 'c' => 0.195],
                    ],
                    // Annual reduction vs the 2008 reference line (MEPC.338(76)).
                    'reduction_factors' => [
                        '2023' => 0.05,
                        '2024' => 0.07,
                        '2025' => 0.09,
                        '2026' => 0.11,
                    ],
                    // Attained/required ratio boundaries (MEPC.338(76)).
                    'rating_boundaries' => [
                        'A' => 0.50,
                        'B' => 0.72,
                        'C' => 0.83,
                        'D' => 1.00,
                    ],
                    // kg CO2 per kg fuel (MEPC.336(76), Table 1).
                    'emission_factors' => [
                        'HFO' => 3.151,
                        'MGO' => 3.206,
                        'MDO' => 3.206,
                        'LNG' => 2.750,
                        'LPG' => 3.000,
                        'biofuel' => 1.500,
                    ],
                ],
            ],
        );
    }
}
