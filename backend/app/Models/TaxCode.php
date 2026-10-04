<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxCode extends Model
{
    use HasAuditLog, HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'rate_pct',
        'country_code',
        'is_default',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'rate_pct' => 'string',
        'is_default' => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
