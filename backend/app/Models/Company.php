<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Organisation in the address book. People are Contacts.
 *
 * @property int $id
 * @property string $code
 * @property string $legal_name
 * @property string|null $country
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Company extends Model
{
    use HasAuditLog, HasLockVersion, SoftDeletes;

    protected $attributes = ['status' => 'active', 'vat_registered' => false, 'lock_version' => 0];

    protected $fillable = [
        'code', 'legal_name', 'normalized_name', 'trading_name', 'country', 'city', 'address_line1', 'address_line2',
        'postal_code', 'email', 'phone', 'website', 'tax_number', 'vat_registered', 'default_currency',
        'payment_terms_days', 'credit_limit', 'status', 'remarks', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'payment_terms_days' => 'integer',
            'credit_limit' => 'decimal:2',
            'lock_version' => 'integer',
        ];
    }

    protected function auditExcept(): array
    {
        return ['normalized_name'];
    }

    /** @return HasMany<CompanyRoleAssignment, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(CompanyRoleAssignment::class);
    }

    /** @return HasMany<CompanyAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(CompanyAlias::class)->latest('id');
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('is_primary')->orderBy('first_name');
    }

    /** @return HasMany<CompanyBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class)->orderByDesc('is_primary');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return $this->roles->pluck('role')->sort()->values()->all();
    }

    /** @param Builder<Company> $query */
    public function scopeWithRole(Builder $query, string $role): void
    {
        $query->whereHas('roles', fn ($q) => $q->where('role', $role));
    }
}
