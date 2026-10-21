# 11 — Testing

Status: current as of 2026-10-10.

## 1. Tooling and commands

| Layer | Tool | Command (from the project root) |
|---|---|---|
| Backend unit + feature | PHPUnit 11 on MySQL database `offshore_test` | `cd backend && php artisan test` (about 6 minutes) |
| Static analysis | Larastan | `cd backend && ./vendor/bin/phpstan analyse` |
| Code style | Laravel Pint | `cd backend && ./vendor/bin/pint --test` |
| Frontend unit | Vitest + Testing Library | `cd frontend && npm test` |
| Frontend types / lint / build | `tsc`, ESLint, Vite | `cd frontend && npm run lint && npm run build` |

Current totals: **333 PHPUnit tests**, **59 Vitest tests**. There is no browser (E2E) suite at present; it was removed from the repository.
The local MySQL server (ServBay) must be running for the backend tests.

## 2. Test categories

| Category | Scope |
|---|---|
| Calculator unit tests | `Domain/*`: estimation (E1–E14), offshore activity revenue, laytime, bunker ROB, decimals, great-circle |
| Service / workflow tests | transitions, snapshots, idempotency, optimistic locking, self-approval, conversions, finalization gates |
| Feature / API tests | per endpoint: validation 422, permission 403 per role, business rule 409, happy path envelope |
| Route and security sweep | anonymous callers refused, no-permission users get no 2xx/5xx, hardening headers, CORS, throttling |
| Performance guards | N+1 checks (`QueryCountTest`, `ListEndpointsMultiRowTest`) |
| Frontend component tests | formatting helpers, scenario editor inputs, finance pickers, results/trace rendering, dialogs |
| **Walkthrough tests** | replay the documented dummy data end to end and pin every headline figure (section 3) |

## 3. Walkthrough tests (documents that cannot drift)

| Test | Document | What it pins |
|---|---|---|
| `tests/Feature/Finance/DataflowWalkthroughTest.php` | `dataflow.md` | Voyage charter: estimate 71,050.00, laytime 4,000.00 demurrage, invoice 114,000.00, net profit 71,800.00 |
| `tests/Feature/Finance/OffshoreDataflowWalkthroughTest.php` | `offshore-dataflow.md` | Offshore day-rate charter: estimate 94,695.83, seven activities = 203,875.00 revenue, commission 5,620.31, net profit 85,854.69 |

If a calculation or workflow rule changes, the matching test fails; update the test and the document together.

## 4. Golden data

Golden fixtures (e.g. EST-TC-01, LT-TC-01) are approved by the Chartering/Operations leads. A golden test may change only when
`calculation_version` is bumped and the change is recorded in `14-DECISIONS.md`.

## 5. Definition of Done per module

Migrations reviewed; policies and permissions seeded; unit tests for calculations; feature tests for all endpoints (including 403); Larastan clean;
frontend builds and lints; docs 05/06/13 updated; demo approved by the business owner → status APPROVED in 13.
