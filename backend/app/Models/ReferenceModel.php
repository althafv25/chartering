<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;

/**
 * Base for simple lookup tables (code, name, sort_order, status + extras).
 * Writes go only through ReferenceDataService with registry-validated input.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $status
 */
abstract class ReferenceModel extends Model
{
    use HasAuditLog;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('reference');
    }
}
