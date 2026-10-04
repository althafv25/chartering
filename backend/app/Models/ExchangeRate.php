<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 1 base_currency = rate × quote_currency, effective from rate_date.
 *
 * @property int $id
 * @property Carbon $rate_date
 * @property string $base_currency
 * @property string $quote_currency
 * @property string $rate
 * @property string $source
 */
class ExchangeRate extends Model
{
    use HasAuditLog;

    protected $fillable = ['rate_date', 'base_currency', 'quote_currency', 'rate', 'source', 'remarks', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['rate_date' => 'date', 'rate' => 'decimal:8'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
