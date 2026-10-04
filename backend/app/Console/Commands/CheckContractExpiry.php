<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contracts\ContractService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Daily: (1) expire approved/active contracts whose end date has passed
 * (setting contracts.auto_expire, BR-CT-03 proposal), (2) notify about
 * contracts ending within notifications.contract_expiry_days. Idempotent
 * via notification dedupe keys.
 */
class CheckContractExpiry extends Command
{
    protected $signature = 'contracts:check-expiry';

    protected $description = 'Expire ended contracts and notify about upcoming contract expiry';

    public function handle(ContractService $contracts, NotificationService $notifications, SettingsService $settings): int
    {
        $expired = 0;
        if ($settings->get('contracts.auto_expire', true)) {
            Contract::query()->whereIn('status', ['approved', 'active'])->whereDate('end_date', '<', today())
                ->each(function (Contract $c) use ($contracts, &$expired) {
                    $expired += $contracts->expire($c) ? 1 : 0;
                });
        }

        $days = (int) $settings->get('notifications.contract_expiry_days', 60);
        $recipients = $notifications->usersWithPermission('contracts.update');
        $sent = 0;
        Contract::query()->with('customer')->whereIn('status', ['approved', 'active'])
            ->whereDate('end_date', '>=', today())->whereDate('end_date', '<=', today()->addDays($days))
            ->each(function (Contract $c) use ($notifications, $recipients, &$sent) {
                $left = (int) today()->diffInDays($c->end_date);
                $users = $recipients->merge(User::query()->whereKey($c->getAttribute('created_by'))->get())->unique('id');
                $sent += $notifications->sendToMany($users, 'contract_expiry', "Contract {$c->contract_number} ends in {$left} days",
                    "{$c->getAttribute('title')} ({$c->customer?->legal_name}) ends on {$c->end_date->toDateString()}.",
                    $left <= 7 ? NotificationType::Warning : NotificationType::Info, "/contracts/{$c->id}", 'contracts', $c->id,
                    "contract-expiry:{$c->id}:{$c->end_date->toDateString()}")->count();
            });

        $this->info("Expired {$expired} contract(s); sent {$sent} notification(s).");

        return self::SUCCESS;
    }
}
