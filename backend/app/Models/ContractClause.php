<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $version_no
 * @property int $sequence
 * @property string|null $clause_ref
 * @property string $title
 * @property string $body
 */
class ContractClause extends Model
{
    protected $fillable = ['contract_id', 'version_no', 'sequence', 'clause_ref', 'title', 'body'];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'sequence' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        return ['clause_ref' => $this->clause_ref, 'title' => $this->title, 'body' => $this->body];
    }
}
