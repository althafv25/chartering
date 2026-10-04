# 11 — Testing

Status: DRAFT v0.1.

## 1. Tooling (CM parity + frontend)

| Layer | Tool |
|---|---|
| Backend unit/feature | PHPUnit 11 (`php artisan test`), RefreshDatabase on **MySQL test DB `offshore_test`** (SQLite differs in decimal/JSON/locking behaviour) |
| Static analysis | Larastan (level 6 to start), Pint |
| Frontend | ESLint + `tsc -b` (CM parity). **Proposed new:** Vitest + Testing Library for formatting helpers and critical components (D-009) |
| E2E | Deferred (Playwright candidate) |

## 2. Test categories

| Category | Scope |
|---|---|
| Calculator unit tests | `Domain/*`: E1–E14, O1–O4, B1–B3, L1–L6, F1–F7 with JSON fixtures and hand-verified expected values (08) |
| Service tests | transitions, snapshots, idempotency, locking (two concurrent approvals → one succeeds) |
| Feature/API tests | per endpoint: validation 422, permission 403 per role, business rule 409, happy path envelope |
| Permission matrix test | data-driven over 10 §3 |
| Integration contracts | providers tested with fake HTTP responses (`Http::fake`) |
| Regression | every bug fix adds a test |

## 3. Mandatory calculation tests

Voyage days; fuel consumption (sea/port, ECA split); fuel cost; revenue per basis; commission; voyage profit; TCE; break-even rate (round trip gives P≈0); bunker reconciliation & discontinuity; Port DA variance; laytime used/allowed incl. exceptions and once-on-demurrage; demurrage; despatch; currency conversion & FX difference; invoice totals & tax rounding; partial payments & status; finalization guards; permissions.

## 4. Golden data

Golden fixtures (e.g. EST-TC-01, LT-TC-01) are approved by the Chartering/Operations leads and stored with their approver name and date. A golden test may change only when `calculation_version` is bumped and the change is recorded in 14-DECISIONS.

## 5. Definition of Done per module

Migrations reviewed; policies and permissions seeded; unit tests for calculations; feature tests for all endpoints (including 403); Larastan clean; frontend builds and lints; docs 05/06/13 updated; demo approved by the business owner → status APPROVED in 13.


## Browser end-to-end tests (phase 14)

`cd frontend && npm run e2e` (first time only: `npx playwright install chromium`). Playwright starts its own stack: database `offshore_e2e` (dropped and rebuilt on every run by `backend/tools/e2e/prepare.php`, which refuses any other database name), the API on port 8011 and the Vite dev server on 5174. Nothing touches the normal `offshore` database or the usual dev ports. `API_RATE_LIMIT` is raised for this stack only; never set it in production. Login throttling (5 per minute per account) still applies, so specs sign in through the form once and otherwise reuse an API token.

| Spec | What a user does |
|---|---|
| `auth` | sign in and out, wrong password, signed-out redirect, a read-only user has no admin menu and gets the 403 page |
| `menu` | with seeded invoices, payables, payments and vessels, opens **every** sidebar page and fails on any 5xx response, uncaught error, console error or "Could not load data" banner |
| `finance` | creates an invoice, adds a line, issues it (number assigned), finds it in aging and balancing; records a payment, allocates it, invoice becomes Paid |
| `operations` | builds a Port DA, cannot approve own submission (403), a second user approves and the expense is booked; enters a laytime in port time, sees the once-on-demurrage trace, submits and agrees it |
| `ais` | fleet map hidden while AIS is off; after enabling, a recorded position shows in the table and as a Leaflet marker, with a track distance |

Known gaps: only Chromium; the map tiles come from the internet and are not asserted; mobile layouts are not exercised; no visual-regression or accessibility scan yet.
