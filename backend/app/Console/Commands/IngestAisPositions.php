<?php

namespace App\Console\Commands;

use App\Services\Ais\AisService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Polls the configured AIS provider for vessels on active voyages (docs/09 §4: only active or
 * monitored vessels are polled). A provider failure is reported and never aborts other work.
 */
class IngestAisPositions extends Command
{
    protected $signature = 'ais:ingest';

    protected $description = 'Fetch latest AIS positions for vessels on active voyages';

    public function handle(AisService $ais): int
    {
        if (! $ais->isEnabled()) {
            $this->info('AIS is disabled; nothing to do.');

            return self::SUCCESS;
        }
        $vessels = $ais->monitoredVessels();
        if ($vessels === []) {
            $this->info('No vessels on active voyages.');

            return self::SUCCESS;
        }

        try {
            $new = $ais->ingest($ais->provider()->latestPositions($vessels));
        } catch (Throwable $e) {
            report($e);
            $this->error('AIS provider failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Ingested {$new} new position(s) for ".count($vessels).' vessel(s) via '.$ais->provider()->name().'.');

        return self::SUCCESS;
    }
}
