<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $unlocode
 * @property string $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string $timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Port extends Model
{
    use HasAuditLog, SoftDeletes;

    protected $attributes = ['status' => 'active', 'timezone' => 'UTC'];

    protected $fillable = [
        'name', 'normalized_name', 'unlocode', 'country', 'region', 'latitude', 'longitude', 'timezone',
        'max_draft_m', 'max_loa_m', 'max_beam_m', 'restrictions', 'notes', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'max_draft_m' => 'decimal:2',
            'max_loa_m' => 'decimal:2',
            'max_beam_m' => 'decimal:2',
        ];
    }

    protected function auditExcept(): array
    {
        return ['normalized_name'];
    }

    /** @return BelongsToMany<Company, $this> */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'port_agents')
            ->withPivot(['id', 'is_default', 'remarks'])->withTimestamps()
            ->orderByPivot('is_default', 'desc');
    }

    public function label(): string
    {
        return $this->unlocode ? "{$this->name} ({$this->unlocode})" : $this->name;
    }
}
