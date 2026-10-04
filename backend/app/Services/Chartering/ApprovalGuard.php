<?php

namespace App\Services\Chartering;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\SettingsService;

/** Reusable approval rules (APR-02: no self-approval unless allowed by settings). */
class ApprovalGuard
{
    public function __construct(private readonly SettingsService $settings) {}

    public function assertNotSelfDecision(?int $submittedBy, User $actor): void
    {
        if ($submittedBy !== null && $submittedBy === $actor->id && ! $this->settings->get('approvals.self_approval_allowed', false)) {
            throw new BusinessRuleException('You cannot approve or reject a record you submitted. Ask another approver.', 'self_approval_not_allowed', status: 403);
        }
    }
}
