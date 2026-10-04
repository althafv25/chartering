<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Immutable record of a contract version (header snapshot + effective date).
 *
 * @property int $version_no
 * @property int|null $amendment_id
 * @property array<string, mixed> $header_snapshot
 * @property Carbon $effective_from
 * @property Carbon|null $created_at
 */
class ContractVersion extends Model
{
    protected $fillable = ['contract_id', 'version_no', 'effective_from', 'amendment_id', 'header_snapshot', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'header_snapshot' => 'array', 'version_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new BusinessRuleException('Contract versions are immutable.', 'version_immutable'));
        static::deleting(fn () => throw new BusinessRuleException('Contract versions are immutable.', 'version_immutable'));
    }
}
