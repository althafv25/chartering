<?php

namespace App\Models\Concerns;

use App\Exceptions\BusinessRuleException;

/**
 * Optimistic locking. Clients send the lock_version they loaded; a mismatch
 * means someone else saved in between → 409 stale_record.
 *
 * @property int $lock_version
 */
trait HasLockVersion
{
    public static function bootHasLockVersion(): void
    {
        static::updating(function ($model) {
            $model->lock_version = (int) $model->getOriginal('lock_version') + 1;
        });
    }

    public function assertLockVersion(?int $expected): void
    {
        if ($expected === null) {
            throw new BusinessRuleException('lock_version is required to update this record.', 'lock_version_required', status: 422);
        }

        if ((int) $this->lock_version !== $expected) {
            throw new BusinessRuleException(
                'This record was changed by another user. Reload it and apply your changes again.',
                'stale_record',
            );
        }
    }
}
