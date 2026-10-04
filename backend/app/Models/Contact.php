<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $company_id
 * @property string $first_name
 * @property string|null $last_name
 */
class Contact extends Model
{
    use HasAuditLog, SoftDeletes;

    protected $attributes = ['is_primary' => false];

    protected $fillable = [
        'company_id', 'first_name', 'last_name', 'job_title', 'department', 'email', 'phone', 'mobile',
        'is_primary', 'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }
}
