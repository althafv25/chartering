<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'sequence',
        'voyage_revenue_id',
        'description',
        'quantity',
        'unit',
        'rate',
        'amount',
        'tax_code_id',
        'tax_rate_pct',
        'tax_amount',
        'line_total',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'quantity' => 'string',
        'rate' => 'string',
        'amount' => 'string',
        'tax_rate_pct' => 'string',
        'tax_amount' => 'string',
        'line_total' => 'string',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function voyageRevenue(): BelongsTo
    {
        return $this->belongsTo(VoyageRevenue::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
