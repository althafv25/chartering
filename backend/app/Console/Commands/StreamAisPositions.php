<?php

namespace App\Console\Commands;

use App\Models\Vessel;
use App\Services\Ais\AisService;
use App\Services\Ais\AisStreamMessageParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Phrity\Net\Uri;
use Throwable;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * Long-running worker for the aisstream.io push feed (docs/09-AIS-INTEGRATION.md).
 *
 * aisstream.io is a WebSocket that stays open and pushes AIS messages as they occur — it is
 * not a REST endpoint you poll. This command therefore does not run from the scheduler like
 * `ais:ingest`; it is a persistent process (run it under Supervisor — see docs/09 §"Running
 * ais:stream in production"). It:
 *   1. Connects to wss://stream.aisstream.io/v0/stream.
 *   2. Sends one subscription message with the API key, a required bounding box, and an MMSI
 *      filter built from the vessels currently on an active voyage (AisService::monitoredVessels()).
 *   3. For every PositionReport it receives, AisStreamMessageParser::toPositionDto() builds an
 *      AisPositionDTO (pure, unit-tested without a live connection) and AisService::ingest()
 *      stores it — the exact same storage/dedup path used by manual entry and by ais:ingest,
 *      so the fleet map and position history work unchanged.
 *   4. Refreshes the MMSI subscription periodically (so a newly started voyage starts being
 *      tracked without restarting the process) and reconnects with backoff on any failure.
 *
 * The API key is a server-side secret: it lives only in `.env` (AISSTREAM_API_KEY), is read via
 * config('offshore.ais.aisstream_api_key'), and is never sent to the frontend or stored in the
 * database. Positions, by contrast, are advisory data (AIS-01) — this command never touches
 * captain reports, milestones or voyage/vessel status.
 */
class StreamAisPositions extends Command
{
    protected $signature = 'ais:stream {--once : Process a single subscribe-and-listen cycle then exit (for tests/diagnostics)}';

    protected $description = 'Connect to the aisstream.io WebSocket and ingest live AIS positions until stopped';

    private const ENDPOINT = 'wss://stream.aisstream.io/v0/stream';

    private const RESUBSCRIBE_SECONDS = 300; // refresh the MMSI list every 5 minutes

    private const MAX_BACKOFF_SECONDS = 60;

    public function handle(AisService $ais): int
    {
        if (($provider = config('offshore.ais.provider')) !== 'aisstream') {
            $this->error("AIS_PROVIDER is \"{$provider}\", not \"aisstream\". Set AIS_PROVIDER=aisstream in .env to run this command.");

            return self::FAILURE;
        }
        $apiKey = (string) config('offshore.ais.aisstream_api_key');
        if ($apiKey === '') {
            $this->error('AISSTREAM_API_KEY is not set in .env. Get a free key at https://aisstream.io/authenticate and set it there.');

            return self::FAILURE;
        }
        if (! $ais->isEnabled()) {
            $this->error('AIS is disabled (Settings → AIS → ais.enabled). Enable it before running this command.');

            return self::FAILURE;
        }

        $attempt = 0;
        do {
            try {
                $this->runOnce($ais, $apiKey);
                $attempt = 0; // a clean disconnect resets backoff
            } catch (Throwable $e) {
                $attempt++;
                $wait = min(self::MAX_BACKOFF_SECONDS, 2 ** $attempt);
                Log::warning('ais:stream connection failed, reconnecting', ['attempt' => $attempt, 'wait_seconds' => $wait, 'message' => $e->getMessage()]);
                $this->warn("Connection lost ({$e->getMessage()}). Reconnecting in {$wait}s...");
                sleep($wait);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** @throws Throwable propagates to the caller's reconnect/backoff loop */
    private function runOnce(AisService $ais, string $apiKey): void
    {
        $client = new Client(new Uri(self::ENDPOINT));
        $client->setTimeout(30);

        $subscribe = function () use ($client, $apiKey, $ais) {
            $mmsiList = AisStreamMessageParser::mmsiFilter($ais->monitoredVessels());
            $message = [
                'APIKey' => $apiKey,
                'BoundingBoxes' => [AisStreamMessageParser::boundingBox((string) config('offshore.ais.aisstream_bounding_box'))],
                'FilterMessageTypes' => ['PositionReport'],
            ];
            if ($mmsiList !== []) {
                $message['FiltersShipMMSI'] = $mmsiList;
            }
            $client->text(json_encode($message, JSON_THROW_ON_ERROR));
            $this->info('Subscribed for '.count($mmsiList).' vessel(s) by MMSI (plus the configured bounding box).');
        };

        $lastResubscribe = time();
        $ingested = 0;

        $client->onHandshake(function () use ($subscribe) {
            $this->info('Connected to aisstream.io.');
            $subscribe();
        });

        $client->onText(function (Client $cl, $connection, Text $message) use ($ais, &$ingested) {
            $payload = json_decode($message->getContent(), true);
            if (! is_array($payload)) {
                return;
            }
            if (($payload['MessageType'] ?? null) === 'SubscriptionConfirmation') {
                $this->info('Subscription confirmed by aisstream.io.');

                return;
            }
            $dto = AisStreamMessageParser::toPositionDto($payload, $this->mmsiToVesselId());
            if ($dto !== null && $ais->ingest([$dto]) > 0) {
                $ingested++;
            }
        });

        $client->onError(function (Client $cl, $connection, $exception) {
            Log::warning('ais:stream reported an error frame', ['message' => $exception->getMessage()]);
        });

        $client->onTick(function (Client $cl) use (&$lastResubscribe, $subscribe, &$ingested) {
            if ($this->option('once')) {
                $cl->stop();

                return;
            }
            if (time() - $lastResubscribe >= self::RESUBSCRIBE_SECONDS) {
                $lastResubscribe = time();
                $subscribe();
                $this->info("Ingested {$ingested} position(s) since last resubscribe.");
                $ingested = 0;
            }
        });

        $client->onDisconnect(function () {
            $this->warn('Disconnected from aisstream.io.');
        });

        $client->start();
    }

    /** @return array<string, int> normalized MMSI (no leading zeros) => vessel_id, cached for the process lifetime */
    private function mmsiToVesselId(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (Vessel::query()->whereNotNull('mmsi')->pluck('mmsi', 'id') as $id => $mmsi) {
                $map[AisStreamMessageParser::normalizeMmsi((string) $mmsi)] = (int) $id;
            }
        }

        return $map;
    }
}
