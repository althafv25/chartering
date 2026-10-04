<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Immutable point-in-time copy of estimate/actual figures.
 *
 * @property string $type
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 */
class VoyageSnapshot extends Model
{
    protected $fillable = ['voyage_id', 'type', 'name', 'payload', 'calculation_version', 'inputs_hash', 'created_by'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new BusinessRuleException('Voyage snapshots are immutable.', 'snapshot_immutable'));
    }
}
