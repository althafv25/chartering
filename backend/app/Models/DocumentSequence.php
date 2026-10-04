<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $sequence_key
 * @property int $next_number
 */
class DocumentSequence extends Model
{
    protected $fillable = ['sequence_key', 'next_number'];

    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
