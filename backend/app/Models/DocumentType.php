<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = ['code', 'name', 'requires_expiry', 'status'];

    protected function casts(): array
    {
        return ['requires_expiry' => 'boolean'];
    }
}
