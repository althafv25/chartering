<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Standard audit logging: fillable attributes, only changed values,
 * log name = table. IP / user agent / request id are added globally in
 * AppServiceProvider (Activity::creating).
 */
trait HasAuditLog
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept($this->auditExcept())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName($this->getTable());
    }

    /** @return list<string> attributes that must never be written to the audit log */
    protected function auditExcept(): array
    {
        return ['password', 'remember_token'];
    }
}
