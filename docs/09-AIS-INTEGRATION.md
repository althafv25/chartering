# 09 — AIS Integration

Status: BUILT (manual entry + aisstream.io provider). AIS is **optional**. With no provider configured, every other module still works unaffected (positions come from captain reports and manual entries). The Fleet Map menu item and every `/api/v1/ais/*` endpoint except `GET /ais/status` are unavailable while `ais.enabled = false` (Settings → AIS).

## 1. Components (as built)

```
AisProviderInterface
  ├─ NullAisProvider        (default; returns nothing — positions.manual entry only)
  ├─ ManualAisProvider      (positions entered by a user with ais.manual-position)
  └─ AisStreamProvider      (aisstream.io; satisfies the interface for name()/healthCheck() —
                              the real ingestion happens in the long-running `ais:stream`
                              command, not through latestPositions(), see §3)

AisStreamMessageParser  — pure, unit-tested mapping: aisstream JSON → AisPositionDTO
AisService              — fleet/status/positions/track queries, ingest(), staleness, monitoredVessels()
TrackSimplifier         — Douglas–Peucker simplification of a track to ≤ 2,000 points, server-side
```

```php
interface AisProviderInterface {
    public function name(): string;
    /** @param list<VesselIdentifier> $vessels  @return list<AisPositionDTO> */
    public function latestPositions(array $vessels): array;
    /** @return list<AisPositionDTO> */
    public function history(VesselIdentifier $vessel, CarbonImmutable $from, CarbonImmutable $to): array;
    public function healthCheck(): ProviderHealth;
}
```

The vessel identifier sent to a provider is the MMSI when present, otherwise the IMO (`VesselIdentifier::value()`).

## 2. Data

- `ais_positions`: append-only. Unique on `(vessel_id, observed_at, provider)` so re-ingesting the same observation never creates a duplicate row.
- `vessel_latest_positions`: one row per vessel, upserted on every successful ingest. The fleet map and `GET /ais/fleet` read only this table.
- Positions are kept strictly separate from captain reports and milestones and are **never** merged into operational records (rule AIS-01) — AIS is advisory data only, shown on the map and in position history, nothing more.

## 3. Providers (verified against code)

### 3.1 `none` (default)

Nothing is configured. `NullAisProvider::latestPositions()` returns an empty array; `healthCheck()` reports *"No AIS provider configured; positions are entered manually."* The scheduled `ais:ingest` command runs every 5 minutes but does nothing.

### 3.2 `manual`

A user with `ais.manual-position` enters a position by hand via `POST /ais/positions/manual` (the "Record position" button on the Fleet Map page). `ManualAisProvider::latestPositions()` still returns nothing — there is no polling, only the manual-entry endpoint, which calls `AisService::recordManual()` directly.

### 3.3 `aisstream` — real live feed (built)

[aisstream.io](https://aisstream.io) is a **free-tier WebSocket push feed** (`wss://stream.aisstream.io/v0/stream`) — it is not a request/response REST API, so it does not fit the pull-based `latestPositions()` method the way a typical commercial vendor would. Instead:

- `App\Services\Ais\AisStreamProvider` implements the interface for naming/health-check consistency only (`latestPositions()` returns nothing, by design — the feed is push-based).
- `App\Console\Commands\StreamAisPositions` (**`php artisan ais:stream`**) is a **long-running process**, not a scheduled cron job. It:
  1. Opens one WebSocket connection to aisstream.io.
  2. Sends a single subscription message containing the API key, a required geographic bounding box, an MMSI filter built from `AisService::monitoredVessels()` (vessels on an active voyage), and `FilterMessageTypes: ["PositionReport"]`.
  3. For every `PositionReport` it receives, `App\Services\Ais\AisStreamMessageParser::toPositionDto()` (pure function, unit-tested in `tests/Unit/Services/Ais/AisStreamMessageParserTest.php` without any live network call) maps the message to an `AisPositionDTO`, ignoring any MMSI not in the tracked fleet.
  4. `AisService::ingest()` stores it — the exact same deduplicated storage path used by manual entry, so the fleet map, position history, track simplification and staleness checks all work unchanged, regardless of which provider fed the data.
  5. Re-sends the subscription every 5 minutes so a vessel that just started a new voyage gets picked up without restarting the process, and reconnects with exponential backoff (capped at 60 seconds) on any connection failure.

### Where the API key goes

**The key is a server-side secret. It is never put in frontend code, the database, or any URL — it lives only in the backend's `.env` file.**

1. Create a free account at **https://aisstream.io** and generate a key at **https://aisstream.io/authenticate**.
2. Open `backend/.env` and set:
   ```
   AIS_PROVIDER=aisstream
   AISSTREAM_API_KEY=paste-your-real-key-here
   ```
   (Both keys already exist as placeholders in `backend/.env.example` and in the current `backend/.env` — just fill in the value.)
3. Optionally restrict the geographic area with `AISSTREAM_BOUNDING_BOX` (format `lat1,lon1,lat2,lon2`, two opposite corners). aisstream requires a bounding box even when filtering by MMSI; if left unset it defaults to the whole globe (`-90,-180,90,180`), which is harmless since the MMSI filter already narrows the feed to your fleet.
4. Turn the feature on in the application itself: **Administration → Settings → AIS → `ais.enabled`** (or `PUT /api/v1/settings {"ais.enabled": true}` with `settings.update`). This is a separate switch from the provider choice — both must be set.
5. Make sure every vessel you want tracked has its **MMSI** filled in on the vessel record (`Fleet → Vessels`). aisstream filters by MMSI; a vessel with no MMSI on file will never be tracked by this provider (its positions can still be entered manually).

The key is read via `config('offshore.ais.aisstream_api_key')`, which resolves from the `.env` value — it is deliberately not exposed through the Settings API or any frontend-visible endpoint, matching how every other provider credential in this system is handled (`config/offshore.php`'s documented policy: *"Credentials for a... provider belong in .env only"*).

### Running `ais:stream` in production

This is **not** a scheduled command — it must run continuously as its own long-lived process, exactly like a queue worker. Running it under [Supervisor](http://supervisord.org/) is the simplest reliable option:

```ini
; /etc/supervisor/conf.d/offshore-ais-stream.conf
[program:offshore-ais-stream]
process_name=%(program_name)s
command=php /var/www/offshore/backend/artisan ais:stream
directory=/var/www/offshore/backend
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/offshore/backend/storage/logs/ais-stream.log
stopwaitsecs=10
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start offshore-ais-stream:*
```

The command already handles its own reconnect/backoff loop internally, so Supervisor's `autorestart` is a second safety net (e.g. if the PHP process itself crashes), not the primary reconnect mechanism.

For local development/testing, just run it in a terminal: `php artisan ais:stream`. Use `php artisan ais:stream --once` to run a single subscribe-and-listen cycle for diagnostics (it exits after the first scheduled resubscribe tick instead of running forever) — this is also what the automated test infrastructure would use if a live-connection smoke test is ever added.

### aisstream.io limits to design around (from their published documentation)

| Limit | Value | How this integration respects it |
|---|---|---|
| Connections per account | 3 | Run exactly one `ais:stream` process per environment |
| Connections per source IP | 3 | Same |
| Initial subscription | must arrive within 3 seconds of connecting | Sent immediately `onHandshake` |
| Subscription updates | 1 per second | Resubscribed only every 5 minutes, far under the limit |
| MMSI filters | max 200 per subscription | `AisStreamMessageParser::mmsiFilter()` caps the list at 200 |
| Bounding boxes | required | Always sent; defaults to the whole globe if not configured |

### What still does not happen automatically

- **Historical backfill**: the stream only delivers positions from the moment the connection is subscribed onward. There is no "catch up on what happened while the process was down" — if `ais:stream` is offline for an hour, that hour's positions are simply never received (this is inherent to a push feed, not a limitation specific to this implementation).
- **Vessels without an MMSI** are never tracked by this provider (manual entry remains available for them).
- **Status/milestone changes**: per rule AIS-01, nothing here ever touches captain reports, milestones, or vessel/voyage status — positions are advisory map data only, full stop.

## 4. Functions (unchanged regardless of provider)

| Function | Implementation |
|---|---|
| Fleet map | `GET /ais/fleet` → latest positions plus voyage/status; client-side marker rendering (Leaflet) |
| Current position / speed / destination / ETA | from `vessel_latest_positions` |
| Position history | paginated `GET /ais/vessels/{id}/positions` |
| Track | `GET /ais/vessels/{id}/track?from&to` — max 31-day window, simplified to ≤ 2,000 points |
| Distance travelled | Σ great-circle distance between consecutive stored points in the window (server-side, labelled as an estimate) |
| Stale detection | hourly job (`ais:check-stale`): a vessel on an active voyage with no position for more than `ais.stale_hours` → one notification per vessel per day |
| Manual entry | `POST /ais/positions/manual`, permission `ais.manual-position` |

## 5. Reliability and security

- Provider credentials live in backend `.env` only; the Settings UI only ever shows whether a provider is configured, never the key value itself.
- The `ais:stream` command never throws an unhandled exception to the shell on a connection drop — it logs the failure, waits with exponential backoff (capped at 60s), and reconnects, indefinitely, until the process itself is stopped.
- Startup is guarded: the command refuses to run (with a clear message, not a crash) if `AIS_PROVIDER` is not `aisstream`, if `AISSTREAM_API_KEY` is empty, or if `ais.enabled` is off.

## 6. Open items

- **BR-AIS-01 partially resolved**: a real, working provider (aisstream.io) now exists; whether it is the vendor the business wants to commit to for production (coverage, uptime, free-tier limits) is still a decision for Marine Operations/Management, not a technical blocker.
- **BR-AIS-02 [CONFIRM]** Retention period for positions (no pruning job currently exists — positions accumulate indefinitely in `ais_positions`).
- **BR-AIS-03 [CONFIRM]** Is an onboard AIS gateway feed available as an alternative/complement to a third-party stream?
- Historical backfill (see §3.3) is not implemented for any provider; if continuous coverage during planned maintenance windows becomes a requirement, a secondary REST-based provider (e.g. for backfill only) would need its own `AisProviderInterface` implementation.
