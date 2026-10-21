# Current Status

**Project:** Offshore Chartering & Vessel Operations System
**As of:** 2026-10-10
**Summary:** Phases 2–13 are built and tested. Phase 14 (hardening) is largely done. What remains is business sign-off, CII figure confirmation and production readiness work (see `15-PENDING-ITEMS.md`). Phase-by-phase history is in `13-DEVELOPMENT-PROGRESS.md`.

## Verification

| Check | Result |
|---|---|
| Backend PHPUnit | **333 tests pass** (`cd backend && php artisan test`) |
| Larastan | 0 errors |
| Pint | Clean, except the style-only finding in `database/seeders/InitialDemoDataSeeder.php` |
| Frontend Vitest | **59 tests pass** |
| Frontend `tsc`, ESLint, Vite build | Clean |
| API | 313 routes under `/api/v1`; all but login / forgot / reset require authentication |
| Browser E2E | No suite at present (removed from the repository) |
| Documented walkthroughs | Replayed by `DataflowWalkthroughTest` and `OffshoreDataflowWalkthroughTest` |

## What is built

1. **Masters:** vessels (consumption profiles, status), companies, ports, offshore locations, distances, reference data, currencies and FX
2. **Chartering:** enquiries, estimations (voyage, time charter, offshore day-rate, cargo relet), offers with revisions, fixtures, contracts with versioned rates, clauses and amendments
3. **Operations:** voyages, port calls, milestones, off-hire, captain reports, bunkers and ROB ledger, Port DA, laytime, offshore projects and activities
4. **Finance:** revenue and expense ledger, invoices and credit notes, payables, payments and allocation, aging, balancing, voyage P&L (voyage selectors on every finance form)
5. **Reports and statistics:** 16 reports with CSV / Excel / PDF export, dashboard, statistics
6. **AIS:** provider abstraction (none / manual), fleet map, tracks
7. **CII:** formula sets (draft until confirmed), vessel-year calculation and verification
8. **Documents:** private register by record type

## Known limits

- **Performance:** voyage P&L, estimated-vs-actual and vessel profitability take about 1.2 s on 160,000 ledger lines. A per-voyage totals table would be the next step if the ledger grows tenfold (`12-DEPLOYMENT.md` §6).
- **No queue:** nothing is queued; PDF generation and mail run synchronously. Mail defaults to the log driver.
- **Single database node:** no read replicas or sharding.
- **Reports are not cached.**
- **Business rules:** about 50 rules are marked `[CONFIRM]` in `07-BUSINESS-RULES.md` and are implemented as configurable proposals.
- **Laytime** applies to cargo voyages; whether it applies to offshore contracts is still an open rule (BR-LT-07).
- **CII:** `CiiCalculator` does not yet apply the year-based reduction factors; the seeded `imo-2023-draft` formula set stays `draft` until Marine Operations signs off.

## Business sign-off

Every module is TESTED but **not APPROVED**. Demos and sign-off are still needed for: Masters, Chartering, Operations, Finance, Reports and AIS.

## Documentation map

See `INDEX.md`. Hands-on, number-checked walkthroughs: `dataflow.md` (voyage charter) and `offshore-dataflow.md` (offshore day-rate charter).
