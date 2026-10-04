<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free business numbering (e.g. INV-2026-00001).
 *
 * Must be called inside the caller's transaction so a rolled-back business
 * operation also rolls back the consumed number.
 */
class SequenceService
{
    public function next(string $key, string $prefix, int $padding = 5): string
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->next($key, $prefix, $padding));
        }

        // Ensure the row exists (unique key makes concurrent inserts safe).
        DocumentSequence::query()->insertOrIgnore([
            'sequence_key' => $key,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DocumentSequence $sequence */
        $sequence = DocumentSequence::query()->where('sequence_key', $key)->lockForUpdate()->firstOrFail();

        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }
}
