# 09 — AIS Integration

Status: DRAFT v0.1. AIS is **optional**. With no provider configured, every module still works (positions come from captain reports and manual entries). Fleet Map and AIS Tracking menu items are hidden while `ais.enabled = false`.

## 1. Components

```
AisProviderInterface
  ├─ NullAisProvider            (default; returns nothing)
  ├─ ManualAisProvider          (positions entered/verified by users)
  └─ <Commercial>AisProvider    (future; chosen after BR-AIS-01)

AisService           — query latest, track, history; staleness
AisIngestJob         — scheduled every N minutes (queue `ais`), per batch of vessels
AisPositionNormalizer — maps provider payloads → AisPositionDTO
TrackSimplifier      — Douglas–Peucker on the server
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

The vessel identifier sent to the provider is the MMSI when present, otherwise the IMO.

## 2. Data

- `ais_positions` (see 05): append-only. Unique on (vessel_id, observed_at, provider) so re-polling never creates duplicates. Raw payload is kept 30 days (pruned by a job).
- `vessel_latest_positions`: one row per vessel, upserted on ingest. The fleet map reads only this table.
- Positions are kept separate from captain reports and milestones. They are never merged into operational records (AIS-01).

## 3. Functions

| Function | Implementation |
|---|---|
| Fleet map | `GET /ais/fleet` → latest positions plus voyage/status; client-side marker clustering |
| Current position / speed / destination / ETA | from `vessel_latest_positions` |
| Position history | paginated `GET /ais/vessels/{id}/positions` |
| Track | `GET /ais/vessels/{id}/track?from&to` — max 31-day window, simplified to ≤ 2,000 points |
| Distance travelled | Σ haversine between consecutive points in the window (server) |
| Remaining distance | distance from the latest position to the next port call, via DistanceService (provider) or great-circle as a labelled fallback |
| Stale detection | hourly job: vessel on an active voyage with no position for more than `ais.stale_hours` → notification |
| Speed/consumption comparison | report: AIS SOG vs scenario speed; captain report consumption vs scenario |

## 4. Reliability and security

- Provider credentials live in backend `.env` only. The settings UI shows only whether the provider is configured.
- HTTP client: timeouts (10 s), retry with backoff (3), circuit breaker (skip the provider for 15 min after 5 consecutive failures). Failures are logged as `IntegrationException`, and the last error is shown in `GET /ais/status`.
- Rate limits and cost: poll only vessels that are active or explicitly monitored. The interval is configurable.

## 5. Open items

- **BR-AIS-01 [CONFIRM]** Provider selection, licence and coverage (satellite vs terrestrial), and cost model.
- **BR-AIS-02 [CONFIRM]** Retention period for positions (proposed: 3 years in full, then downsample to hourly).
- **BR-AIS-03 [CONFIRM]** Is an onboard AIS gateway feed available?
