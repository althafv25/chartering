# 11 — Testing

Status: DRAFT v0.1.

## 1. Tooling (CM parity + frontend)

| Layer | Tool |
|---|---|
| Backend unit/feature | PHPUnit 11 (`php artisan test`), RefreshDatabase on **MySQL database `offshore`** |
| Static analysis | Larastan (level 6 to start), Pint |
| Frontend | ESLint + `tsc -b` (CM parity). **Proposed new:** Vitest + Testing Library for formatting helpers and critical components (D-009) |

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
