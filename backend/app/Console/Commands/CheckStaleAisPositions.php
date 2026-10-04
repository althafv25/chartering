<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Services\Ais\AisService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Hourly: a vessel on an active voyage with no AIS position for more than ais.stale_hours
 * (AIS-02) notifies users who can update voyages. One notification per vessel per day (dedupe key).
 */
class CheckStaleAisPositions extends Command
{
    protected $signature = 'ais:check-stale';

    protected $description = 'Notify about vessels on active voyages without a recent AIS position';

    public function handle(AisService $ais, NotificationService $notifications): int
    {
        if (! $ais->isEnabled()) {
            $this->info('AIS is disabled; nothing to do.');

            return self::SUCCESS;
        }
        $recipients = $notifications->usersWithPermission('operations.voyages.update');
        $sent = 0;
        $stale = $ais->staleVessels();
        foreach ($stale as $row) {
            $since = $row['observed_at'] ? 'last seen '.$row['observed_at']->toDateTimeString().' UTC' : 'no position received yet';
            $sent += $notifications->sendToMany(
                $recipients, 'ais_stale', "No recent AIS position: {$row['vessel']->name}",
                "{$row['vessel']->name} is on voyage {$row['voyage']->voyage_number} but has {$since} (limit {$ais->staleHours()} h).",
                NotificationType::Warning, "/operations/voyages/{$row['voyage']->id}", 'voyages', $row['voyage']->id,
                "ais-stale:{$row['vessel']->id}:".now()->toDateString(),
            )->count();
        }
        $this->info(count($stale).' stale vessel(s); sent '.$sent.' notification(s).');

        return self::SUCCESS;
    }
}
