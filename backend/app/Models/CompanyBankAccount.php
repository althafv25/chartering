<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $account_number
 * @property string|null $iban
 */
class CompanyBankAccount extends Model
{
    use HasAuditLog;

    protected $attributes = ['is_primary' => false];

    protected $fillable = ['company_id', 'bank_name', 'account_name', 'account_number', 'iban', 'swift_bic', 'currency', 'is_primary', 'created_by'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
