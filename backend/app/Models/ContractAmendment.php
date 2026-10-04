<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $contract_id
 * @property int $amendment_no
 * @property string $status
 * @property string $summary
 * @property array<string, mixed> $proposal
 * @property array<string, mixed>|null $changes
 * @property int|null $resulting_version
 * @property int|null $submitted_by
 * @property Carbon $effective_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 */
class ContractAmendment extends Model
{
    use HasAuditLog, HasLockVersion;

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    protected $fillable = ['contract_id', 'amendment_no', 'effective_date', 'summary', 'proposal', 'changes', 'status', 'resulting_version',
        'submitted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_comment', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'proposal' => 'array', 'changes' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime',
            'lock_version' => 'integer', 'resulting_version' => 'integer'];
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
