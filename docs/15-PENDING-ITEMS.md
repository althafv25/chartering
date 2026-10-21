# Pending Items and Go-Live Checklist

Updated: 2026-10-10

## 1. Blocking go-live

### 1.1 Business approvals
Each module is tested but needs a business demo and sign-off. Suggested demo material: `dataflow.md` and `offshore-dataflow.md`.

- [ ] Masters (vessels, companies, ports, offshore locations)
- [ ] Chartering (enquiry → offer → fixture → contract)
- [ ] Operations (voyages, bunkers, port calls, Port DA, laytime, offshore activities)
- [ ] Finance (ledger, invoices, payables, payments, approvals)
- [ ] Reports (aging, balancing, P&L, statistics)
- [ ] AIS (fleet map, position recording)

### 1.2 CII figures (BR-CII-01..04)
Code is complete; the seeded formula set `imo-2023-draft` stays `draft` until Marine Operations confirms vessel applicability, reference lines, rating boundaries, emission factors and the capacity metric.
Also decide whether the year-based reduction factors must adjust the reference line (the calculator does not apply them yet). Details: `CII-REQUIREMENTS.md`.

## 2. Before production

- [ ] Penetration test by an external firm (recommended, not technically blocking)
- [ ] Automated backups: add `scripts/backup-database.sh` to cron, verify the database + documents pair, test a restore (`BACKUP-SETUP.md`)
- [ ] Real mail transport configured (password-reset mail is sent synchronously)
- [ ] Licensed or self-hosted map tiles (`VITE_MAP_TILE_URL`)
- [ ] Monitoring / error tracking and a rollback plan (`DEPLOYMENT-RUNBOOK.md`)
- [ ] Data migration plan if replacing a legacy system

Production configuration checklist: `12-DEPLOYMENT.md` §5.
Go-live prerequisites: `APP_DEBUG=false`, CORS origin set, HSTS/CSP in Nginx, TLS certificate, production admin credentials changed, scheduler cron, least-privilege database user.

## 3. Product backlog (post-launch candidates)

| Item | Note |
|---|---|
| Caching reports (Redis) | Only if more than about 50 concurrent users |
| Per-voyage totals table | If the ledger grows about tenfold |
| Read replicas | Only at single-node limits |
| Actual TCE and financial snapshots from actuals | P&L endpoint is the source of actuals today; snapshots still carry estimate figures |
| Contractual laytime rules (SHEX calendars, reversible laytime, terms builder) | BR-LT-01..07 |
| Offshore fuel recharge and standby caps | BR-OA-04 |
| Idempotency-Key handling for payments and invoice issue | Conversions already unique |
| Remaining notifications (certificate expiry, low ROB, port-call reminders, approval alerts) | See `03-MODULES.md` M20 |
| Live AIS provider and retention | BR-AIS-01/02 |
| Company / port CSV import | |
| More frontend page tests | Component tests exist for the shared pieces; most pages have none |
| Browser E2E suite | Removed; consider reintroducing for the main journeys |

## 4. Known issues

- OpenStreetMap public tiles are used by default for the fleet map; their usage policy applies.
- Mobile layout is untested on real devices.
- `database/seeders/InitialDemoDataSeeder.php` fails `pint --test` (style only).
- `docs/` is listed in `.gitignore`, so documents there are not versioned unless they were tracked before; decide whether to keep it ignored.
