<?php

namespace App\Services\Chartering;

use App\Exceptions\BusinessRuleException;
use App\Models\Enquiry;
use App\Models\User;

/**
 * Single owner of enquiry status rules (used by enquiry, estimation, offer and
 * fixture services — no duplicated status logic).
 *
 *   open → evaluating → offered → fixed (fixed only via fixture creation)
 *   any non-final → lost | cancelled ; lost/cancelled → open (reopen)
 */
class EnquiryWorkflow
{
    private const MANUAL = [
        'open' => ['evaluating', 'offered', 'lost', 'cancelled'],
        'evaluating' => ['open', 'offered', 'lost', 'cancelled'],
        'offered' => ['evaluating', 'lost', 'cancelled'],
        'fixed' => [],
        'lost' => ['open'],
        'cancelled' => ['open'],
    ];

    private const RANK = ['open' => 0, 'evaluating' => 1, 'offered' => 2, 'fixed' => 3];

    public function transition(Enquiry $enquiry, string $to, ?string $reason, User $actor): Enquiry
    {
        $from = $enquiry->status;
        if (! in_array($to, self::MANUAL[$from] ?? [], true)) {
            throw new BusinessRuleException(
                $to === 'fixed' ? 'An enquiry becomes Fixed only when a fixture is created.' : "Enquiry cannot move from {$from} to {$to}.",
                'invalid_status_transition',
            );
        }
        if ($to === 'lost' && blank($reason)) {
            throw new BusinessRuleException('A reason is required when marking an enquiry as lost.', 'reason_required', ['reason' => ['Required.']], 422);
        }

        $enquiry->forceFill(['status' => $to, 'lost_reason' => $to === 'lost' ? $reason : ($to === 'open' ? null : $enquiry->getAttribute('lost_reason')), 'updated_by' => $actor->id])->save();
        activity('enquiries')->performedOn($enquiry)->causedBy($actor)->event('status_changed')
            ->withProperties(['old' => ['status' => $from], 'attributes' => ['status' => $to, 'reason' => $reason]])->log("Enquiry {$from} → {$to}");

        return $enquiry;
    }

    /** Automatic forward-only progression triggered by downstream events. Never moves backwards or out of lost/cancelled. */
    public function advance(Enquiry $enquiry, string $to, ?User $actor, string $because): void
    {
        $from = $enquiry->status;
        if (! isset(self::RANK[$from]) || self::RANK[$from] >= self::RANK[$to]) {
            return;
        }
        $enquiry->forceFill(['status' => $to])->saveQuietly();
        activity('enquiries')->performedOn($enquiry)->causedBy($actor)->event('status_changed')
            ->withProperties(['old' => ['status' => $from], 'attributes' => ['status' => $to], 'trigger' => $because])->log("Enquiry {$from} → {$to} ({$because})");
    }

    /**
     * A failed/cancelled fixture re-opens negotiation on the enquiry (BR-FX-02 proposal):
     * fixed → evaluating, so new offers can be made. History stays in the audit log.
     */
    public function fixtureFell(Enquiry $enquiry, User $actor, string $because): void
    {
        if ($enquiry->status !== 'fixed') {
            return;
        }
        $enquiry->forceFill(['status' => 'evaluating'])->saveQuietly();
        activity('enquiries')->performedOn($enquiry)->causedBy($actor)->event('status_changed')
            ->withProperties(['old' => ['status' => 'fixed'], 'attributes' => ['status' => 'evaluating'], 'trigger' => $because])->log("Enquiry fixed → evaluating ({$because})");
    }

    public function assertAcceptsWork(Enquiry $enquiry, string $what): void
    {
        if ($enquiry->isClosed()) {
            throw new BusinessRuleException("Cannot add {$what} to an enquiry that is {$enquiry->status}.", 'enquiry_closed');
        }
    }
}
