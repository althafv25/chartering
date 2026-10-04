<?php

namespace App\Models\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Models\Voyage;
use Illuminate\Database\Eloquent\Model;

/** Protect manual and automatically generated P&L lines under the finalization row lock. */
trait GuardsVoyageFinancials
{
    public static function bootGuardsVoyageFinancials(): void
    {
        $guard = function (Model $line): void {
            $ids = array_unique(array_filter([$line->getOriginal('voyage_id'), $line->getAttribute('voyage_id')]));
            foreach (Voyage::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $voyage) {
                if (! $voyage->isOperationallyOpen()) {
                    throw new BusinessRuleException("A {$voyage->status} voyage's financial lines are read-only. Reopen the voyage before making corrections.", 'voyage_read_only');
                }
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }
}
