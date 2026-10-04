<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persisted calculator output (one per scenario, replaced on recalculation).
 *
 * @property string $inputs_hash
 * @property string $calculation_version
 * @property string $profit
 * @property string|null $tce_per_day
 * @property array<string, mixed> $breakdown
 * @property list<array<string, mixed>> $trace
 * @property list<string>|null $warnings
 * @property Carbon $calculated_at
 */
class ScenarioResultRecord extends Model
{
    protected $table = 'scenario_results';

    public const MONEY = ['fuel_cost', 'port_costs', 'agency_costs', 'canal_costs', 'other_costs', 'operational_costs', 'tonnage_cost',
        'gross_revenue', 'total_commission', 'net_revenue', 'voyage_costs', 'total_costs', 'profit', 'profit_per_day', 'tce_per_day'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        $casts = ['breakdown' => 'array', 'trace' => 'array', 'warnings' => 'array', 'calculated_at' => 'datetime',
            'sea_distance_nm' => 'decimal:2', 'eca_distance_nm' => 'decimal:2', 'sea_days' => 'decimal:6', 'eca_sea_days' => 'decimal:6',
            'port_days' => 'decimal:6', 'total_days' => 'decimal:6', 'fuel_total_mt' => 'decimal:3', 'profit_margin_pct' => 'decimal:4',
            'breakeven_rate' => 'decimal:4'];
        foreach (self::MONEY as $c) {
            $casts[$c] = 'decimal:2';
        }

        return $casts;
    }
}
