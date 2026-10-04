# System audit — 4 October 2026

## Repair update — 4 October 2026

**The reproducible application defects A1–A4, F1–F5, O1–O2, and L1–L3 have been repaired and regression-tested.** The original audit below is retained as the before-repair record; its failing test counts and source line numbers describe that baseline.

| Current verification | Result |
|---|---|
| Full backend PHPUnit | **304 passed, 4,544 assertions; no failures/errors/skips** |
| Registered API actions | **296 routes; 0 missing controller actions** |
| Chromium Playwright | **10 passed / 10**, including session reload, menu sweep, billing, Port DA and laytime |
| Frontend Vitest | **34 passed / 34**, Vitest 4.1.11 |
| PHPStan/Larastan, Pint | Passed |
| ESLint, TypeScript/Vite production build | Passed |
| Composer validation, lockfile install dry run, audit | Passed; no advisories |
| Clean `npm ci` and full npm audit | Passed; **0 vulnerabilities**, including development dependencies |
| Backup-script regressions and shell syntax | **3 passed / 3**; archive contents, private permissions, failed-dump handling and missing documents checked |

Implemented repairs:

- Reconciled the API with actual controller actions, parameters, frontend clients and workflow tests. Restored session lookup, static lookups, parent documents, reports, workflow routes and dedicated throttling. A new route-contract regression checks public action existence and required bindings.
- Restored built-in role grants and permission-cache invalidation while preserving restricted-role and self-approval checks. Revenue/expense register reports use their respective view permissions; aggregate profitability remains separately gated.
- Payment allocations enforce exactly one target, direction, counterparty, and the **converted** target-currency balance. Same-foreign-currency settlement now records snapshot FX differences. Batch rejection rolls back earlier allocations in the same request.
- Cancel/delete/credit releases active revenue billing links while retaining historical invoice-line provenance. A generated unique active-link column prevents concurrent double billing. Credit notes release receipts for reallocation/reversal with audit entries and reverse the original tax/FX snapshots.
- Active invoices block voyage cancellation. Revenue/expense models guard all manual and automated writes/deletes against finalized/cancelled voyages under the voyage row lock. Reopening restores correction access without altering the previous snapshot payload.
- Laytime calculator version `1.0.2` fixes exact-expiry demurrage and independently tracks overlapping exceptions. Input/SOF/exception edits clear derived results, submission/agreement require a current calculation, and submitted/agreed calculations cannot be recalculated. Corrected local-time route binding and enforced a separate agreeing user through the existing approval guard. Browser coverage now verifies that restriction, including for Super Admin.
- Added explicit `INVOICES_REQUIRE_APPROVAL` configuration, dependency lockfiles, a patched Vitest version, `type-check`, and `.github/workflows/checks.yml`. CI is configured; the workflow has not yet run on GitHub.
- Backups now produce a verified database/document pair with configurable paths and credentials. Replaced the incorrect restore/deployment examples with isolated restore instructions and the public `/up` health endpoint.

### Local application rollout

Applied the new billing-link migration and reseeded built-in roles on the audited **local `offshore` database**, after successfully backing up the database and configured private document root. All 25 migrations are now applied. The backup recovery set is:

`/var/folders/36/rd2sl4td7t99rb0y28smx_rm0000gn/T/omnirush/offshore-pre-repair/offshore_20261004_222559_3447/`

It contains `database.sql.gz` and `documents.tar.gz`; archive checks passed. No production restore rehearsal or production rollout is claimed. The updated deployment runbook includes the migration and role-seeding commands for other environments.

### Remaining product scope

The feature gaps in section 4 remain a backlog: CII, live AIS provider integration, actual financial snapshots/TCE, contractual laytime/calendar aggregation, detailed approval thresholds, additional alerts/email, request-key idempotency, imports and remaining outputs/dashboard enhancements. The invoice-approval configuration key and broken routes mentioned there are now fixed. Business-rule confirmations and UAT are still required. Passing the implemented suites does not establish delivery of those missing capabilities or production readiness.

Latest backend result artifact: `/var/folders/36/rd2sl4td7t99rb0y28smx_rm0000gn/T/omnirush/offshore-repair-phpunit.xml`.

## Original audit verdict (before repairs)

**The audited baseline was not ready for production.** Substantial implementation existed, but the API wiring and seeded permissions broke core workflows. Additional financial-integrity and laytime defects were reproduced independently of those failures. Several documented capabilities still require implementation.

This assessment is based on the working tree at commit `5973c8a`, the installed dependencies, fresh automated runs, code inspection, and isolated business-logic probes. Historical claims of “292/292 backend tests passing” and “10/10 browser tests passing” do not describe this checkout.

## Scope and method

- Reviewed the project Markdown documentation, including requirements, architecture, schema, API, rules, calculations, permissions, deployment, pending items, phase summaries, and onboarding guides.
- Compared routes, controllers, services, migrations, seeders, frontend API clients/navigation, and test cases against those requirements.
- Ran the complete backend and frontend suites, Chromium E2E tests, lint/build/static checks, and dependency audits.
- Inspected the local migration and scheduler status. Local runtime: PHP 8.2.30, Node 22.19.0, MySQL 5.7.44; the normal database has 84 tables and all 24 listed migrations applied.
- Backend tests used `offshore_test`. Playwright rebuilt its designated `offshore_e2e` database. Additional probes used only `offshore_e2e`, with each probe's database changes rolled back.
- Production infrastructure, production email delivery, external AIS services, and business acceptance were not validated. Historical load-test and restore-drill claims were reviewed, not rerun.

## 1. Fresh verification results

| Check | Result |
|---|---|
| Backend PHPUnit | **121 passed; 145 failures; 26 errors; 292 total; 0 skipped** |
| Backend unit suite | 68 passed, 26 errors / 94 |
| Backend feature suite | 53 passed, 145 failures / 198 |
| Larastan/PHPStan | Passed, no errors |
| Laravel Pint | Passed |
| Frontend ESLint | Passed |
| TypeScript + Vite production build | Passed |
| Frontend Vitest | **34 passed / 34**, across 13 test files |
| Chromium Playwright | **3 passed; 7 failed / 10** |
| Composer dependency audit | No advisories reported |
| npm production dependency audit | No vulnerabilities reported |
| npm audit including development dependencies | 2 moderate affected packages: `vitest` and `@vitest/mocker`, advisory `GHSA-82fw-gwwq-j7x9` |
| Route/controller inspection | **53 of 254 API route definitions reference nonexistent methods**; additional routes have parameter/signature problems |

The first backend invocation exceeded the tool's four-minute timeout. The subsequent full PHPUnit run completed in approximately 4 minutes 40 seconds and is the source of the totals above. Browser testing completed in approximately seven minutes.

Frontend tests emit MUI out-of-range select warnings in Port DA and Laytime tests. The production build emits dependency annotation warnings, but succeeds.

### Backend feature results by area

| Area | Passed | Failed |
|---|---:|---:|
| Administration | 18 | 4 |
| Authentication | 9 | 3 |
| AIS | 0 | 6 |
| Chartering | 3 | 19 |
| Contracts/fixtures | 0 | 11 |
| Core documents/errors/notifications | 8 | 6 |
| Document register/parents | 2 | 3 |
| Finance/reports/dashboard | 2 | 21 |
| Masters | 3 | 23 |
| Operations | 0 | 36 |
| Performance/query-count checks | 3 | 11 |
| Security | 5 | 2 |

These counts are **test outcomes, not numbers of independent defects**. Many downstream tests fail during their shared chartering setup, before exercising their named feature. All 26 unit errors are authorization errors in finance service tests.

The three passing browser cases cover initial sign-in/sign-out, rejecting a wrong password, and redirecting signed-out visitors. Seven other cases encounter the broken session-reload endpoint, so the current run does not verify their full workflows or the menu sweep.

## 2. Immediate application blockers

### A1 — Session restoration fails with HTTP 500

**Priority: P0. Reproduced in backend and real-browser tests.**

`backend/routes/api.php:235` maps `GET /api/v1/auth/me` to `ProfileController::show()`, which does not exist. The implemented current-user action is `AuthController::me()` at `backend/app/Http/Controllers/Api/V1/Auth/AuthController.php:35`.

`frontend/src/auth/AuthContext.tsx:13–18` calls this endpoint on application startup and clears the saved token on failure. Initial form login can work, but refreshing, reopening, or directly loading an authenticated page sends the user back through authentication.

**Required repair:** correct the endpoint mapping and verify login, reload, deep links, logout, expiry, and restricted-role navigation.

### A2 — Route definitions diverge extensively from implemented controllers and frontend calls

**Priority: P0. Reproduced by the suite and runtime route matching.**

Examples, all under `/api/v1`:

| Request | Current outcome / defect |
|---|---|
| `PUT /estimations/1/scenarios/1` | 405; scenario update route missing |
| `POST /offers/1/revisions/1/send` | 404; revision-level workflow route missing |
| `POST /offers/1/revisions/1/convert-to-fixture` | Route missing |
| Fixture submit/approve/reject/cancel/fail | Maps to nonexistent individual methods; controller implements `transition()` with an action parameter |
| Fixture conversions | Maps to `convertToContract/convertToVoyage`; controller implements `toContract/toVoyage` |
| `POST /voyages/{id}/transition` | Route missing |
| Voyage comparison, snapshots, finance gates, ROB ledger | Required routes missing |
| Off-hire workflow | Frontend calls `/off-hire`; registered resource uses `/off-hires` |
| `POST /port-das/1/items` | 404; items editor cannot save |
| Port DA submit/reject | Routes missing |
| `POST /laytime-calculations/1/calculate` | 404; calculation and SOF/exception workflows incompletely routed |
| `GET /voyage-revenues`, `/voyage-expenses` | 404; frontend expects flat resources, backend registers different nested paths |
| `GET /receivables/aging`, `/balancing/accounts`, `/balancing/cash-flow` | 404 at frontend's paths |
| `GET /reports`, `/reports/{slug}`, `/statistics[/{metric}]` | Catalogue/statistics/generic report routes missing |
| `GET /voyages/{id}/financials` | Frontend expects plural; registered route is singular `/financial` |
| `POST /invoices/{id}/pdf` | Route missing; registered `/print` points to nonexistent `print()` |
| `GET /documents` | Incorrectly calls parent-scoped `index()` without required parent arguments; central action is `register()` |
| Parent document lists/uploads, document types | Required routes missing |
| AIS manual position/history | Mapped action names do not match implemented controller methods |

There are also **route-order collisions**. Runtime matching sends `/companies/lookup`, `/companies/duplicates`, `/ports/lookup`, `/exchange-rates/convert`, and `/distances/calculate` to their earlier `/{id}` resource routes. This breaks selection dropdowns and lookups.

Evidence: `backend/routes/api.php`; `backend/app/Http/Controllers/Api/V1/Chartering/FixtureController.php:54–96`; `backend/app/Http/Controllers/Api/V1/DocumentController.php:25–54`; `frontend/src/api/{chartering,operations,finance}.ts`.

**Required repair:** reconcile HTTP methods, paths, action names, required arguments, route parameter names, ordering, constraints, and frontend contracts across the entire route file. Add a route-target/signature contract check. Merely increasing the number of routes will not resolve this.

### A3 — Seeded roles conflict with documented and tested responsibilities

**Priority: P1. Reproduced by permission tests and finance service errors.**

`backend/database/seeders/RolesAndPermissionsSeeder.php:24–83` contains both missing and excessive grants:

- Finance lacks `invoices.issue`, `invoices.submit`, `revenues.update`, `expenses.update`, `dashboard.view`, and `commercial.financials.view`, among other expected permissions.
- Management lacks expected estimation/fixture approval capabilities, while Chartering is granted estimation approval.
- Management is granted `settings.update`, despite the existing test expecting it to be denied.
- Operations and Marine Operations lack various master/workflow grants required by their tested duties.

These are reproducible with freshly seeded roles. Existing users' database grants can differ from the seeder until it is rerun.

**Required repair:** agree and restore the intended role matrix, preserve segregation of duties, and exercise workflows as the operational roles rather than relying on Super Admin.

### A4 — Dedicated authentication throttles are defined but not attached

**Priority: P1. Reproduced by both login-rate-limit tests.**

`AppServiceProvider.php:159–174` defines `login`, `password`, and `uploads` limiters, but `backend/routes/api.php:228–234` omits `throttle:login` and `throttle:password`. The general API limiter remains configured; the documented stricter login/password limits are ineffective.

**Required repair:** restore route-specific middleware and verify account/IP throttling. The permissionless-route sweep also currently reports numerous HTTP 500s from invalid actions/signatures.

## 3. Independently reproduced financial and operational defects

Ten focused probes were run through the existing services or pure calculator. Service probes used a seeded Super Admin to exercise business logic independently of the broken normal-role grants. Each database probe ran inside a rolled-back transaction.

### F1 — Cross-currency allocation can overpay an invoice

**Priority: P1.** In `PaymentService.php:207–217` and `243–253`, the balance check compares the amount in payment currency with the balance in invoice/payable currency **before conversion**.

Reproduction: USD 100 invoice; EUR 90 payment; EUR→USD rate 1.20. The service accepts the allocation, credits USD 108, and leaves balance **USD -8.00** with status `partially_paid`.

**Fix:** compare the converted target-currency amount with the locked target balance, for invoices and payables. Test both FX directions, rounding boundaries, and repeat allocations.

### F2 — Wrong-company/outgoing payments can settle customer invoices

**Priority: P1.** `PaymentService.php:101–138,207–275` and `PaymentController.php:58–66` do not enforce payment direction or counterparty consistency with the allocation target.

Reproduction: create an invoice for Company A; create a USD 100 payment with direction `paid` to Company B; allocate it to A's invoice. The invoice becomes **Paid**.

**Fix:** enforce received→customer invoice, paid→supplier payable, matching counterparties, and exactly one target per allocation. Any intentional cross-company settlement needs an explicit supported workflow.

### F3 — Realised FX differences disappear for same-foreign-currency settlements

**Priority: P1.** `PaymentService.php:285–287` returns zero FX difference whenever payment and invoice currency match.

Reproduction: EUR 100 invoice at EUR→USD 1.10; EUR 100 payment at 1.20. Expected realised gain in the USD base currency: **10.00**. Stored `fx_difference_base`: **0.00**.

**Fix:** account for differing transaction FX snapshots even when no payment-to-invoice currency conversion is necessary.

### F4 — Cancelling a draft invoice does not release its revenue for reinvoicing

**Priority: P1.** `InvoiceService.php:255–258` resets the revenue status to `confirmed`, but retains its invoice-line link. `attachRevenueLines()` at lines 168–172 then rejects the revenue as already invoiced; the unique link also needs appropriate handling.

Reproduction: attach confirmed revenue to a draft invoice, cancel it, and attach that revenue to a new draft. The second attachment fails with **“Revenue line … is already invoiced.”**

**Fix:** release the active billing relationship while preserving cancellation history. Cover cancel, delete, credit, and reinvoice flows.

### F5 — Crediting a paid invoice strands its payment allocation

**Priority: P1.** `InvoiceService.php:265–312` cancels paid/partially paid originals without unwinding or transferring their allocations. `PaymentService.php:148–153` then refuses removal because the invoice is cancelled.

Reproduction: issue USD 100, allocate USD 100, credit the invoice, and reverse the payment. Reversal fails with **“Invoice cancelled cannot have its allocation removed.”**

**Fix:** implement a coherent credit/refund/reallocation lifecycle, including partially paid documents and realised FX treatment.

### O1 — A voyage with an issued invoice can still be cancelled

**Priority: P1.** `VoyageLifecycleService.php:251–260` checks voyage status but not linked issued invoices.

Reproduction: issue an invoice against a sailing voyage, then cancel the voyage. Both operations succeed; the voyage is `cancelled` and the invoice remains `issued`. This violates the documented “cancel only if nothing invoiced” rule.

### O2 — Finalization does not lock the financial ledger

**Priority: P1.** Manual revenue/expense services check line status and permission, but do not guard the parent voyage's finalized/cancelled state. See `VoyageRevenueService.php:59–105` and `VoyageExpenseService.php:64–124`.

Reproduction: complete and finalize a voyage through `VoyageLifecycleService`, verify its final snapshot exists, then create and confirm a new USD 100 voyage revenue. The service accepts it without reopening the voyage.

**Fix for O1/O2:** enforce the parent lifecycle consistently inside financial transactions, including appropriate locking, and verify the published P&L cannot change after finalization without the audited reopen process.

### L1 — Once-on-demurrage misses the exact-expiry boundary

**Priority: P1.** `LaytimeCalculator.php:65` only invokes the once-on-demurrage recount when the initially counted time is strictly greater than allowed time.

Reproduction: 10-hour window, 8 allowed hours, final 2 hours excluded for weather, `always_on_demurrage`, USD 2,400/day. Expected: 10 used hours and USD 200 demurrage. Actual: **8 used hours and no demurrage**.

### L2 — Overlapping identical exceptions are removed too early

**Priority: P1.** `LaytimeCalculator.php:215` removes all active exceptions with the same type and percentage when one ends.

Reproduction: 10-hour window, zero-count weather exceptions at hours 0–4 and 2–6. Their union excludes six hours, so expected used time is 4 hours. Actual: **6 hours**.

**Fix for L1/L2:** calculate demurrage onset directly from the timeline and identify each exception independently. Add boundary, overlap, nesting, and adjacent-period fixtures.

### L3 — Changed laytime inputs retain a stale billable calculation

**Priority: P1.** `LaytimeService.php:30–56,101–111,189–236` does not invalidate previous results on input/exception changes; submission only checks that result fields are non-null.

Reproduction: calculate 10 used hours against 8 allowed hours at USD 2,400/day; change allowed input to 20 hours; submit without recalculating. It becomes **submitted** with `fixed_hours=20`, `allowed_hours=8`, and stale demurrage **USD 200**.

**Fix:** hash/version calculation inputs or clear derived results on every relevant change; require a current calculation at submit/agree. Recalculation also needs lifecycle guards so agreed results remain frozen.

## 4. Capabilities still pending implementation

| Capability | Current evidence and remaining work |
|---|---|
| **CII** | Only CII permission entries were found. No CII models, migrations, calculator/service, endpoints, frontend pages, or tests exist. Requires implementation **and** verified applicability/formula parameters. `14-FINAL-STATUS.md:33–45` and `15-PENDING-ITEMS.md:20–31` overstate completion. |
| **Configurable approvals** | Fixed module transitions and a global self-approval setting exist. The documented per-area enablement, approval thresholds, settings/request register, and separate finalization approval are absent. Invoice issue reads `offshore.approvals.invoices_require_approval` with fallback `false`, but that key is not defined in shipped configuration or the settings registry. |
| **Actual financial snapshots / actual TCE** | The live P&L service exists. `VoyageMetricsService.php:40–52,107–109` still copies estimated money figures into milestone/final snapshots and marks `financials_source=estimate`. `VoyageFinancialService.php:26` explicitly leaves actual TCE unimplemented. Integrate actual ledgers into reproducible financial snapshots. |
| **Laytime contractual rules** | Manual commencement/completion and explicit exceptions exist. `terms_code`, `terms_definition`, NOR, and notice fields are stored but not passed into the calculator (`LaytimeService.php:72–83`). Automatic contractual commencement, SHEX/holiday calendars, reversible multi-port aggregation, and a terms builder remain open. |
| **Offshore financial enhancements** | Fuel recharge/pricing, activity-level profit and availability-based utilization, standby caps, and automatic off-hire hire deductions remain open in the rules/progress documents. Billable/standby-hour statistics and general vessel utilization are separate existing capabilities. |
| **Operational notifications** | Actual schedule contains contract expiry, AIS ingest, and AIS staleness only. Document/certificate expiry, invoice due/overdue transitions, port-call reminders, low ROB, approval-submission alerts, milestone alerts, and laytime-threshold alerts are not implemented as documented. `notifications.email_enabled` is exposed but `NotificationService` only writes in-app records. |
| **Live AIS integration** | Provider abstraction, manual positions, tracks, and staleness code exist. Only `NullAisProvider` and `ManualAisProvider` are bound; the manual provider returns no polled positions. A commercial adapter, retry/circuit-breaker client, retention/pruning, remaining distance, and AIS-vs-plan reporting remain open. Current manual/history routes also need repair. |
| **Request-key idempotency** | No general `Idempotency-Key` handler or `idempotency_keys` storage was found. Payment creation and invoice issue/finalize do not implement the promised replay contract. Conversion uniqueness and AIS deduplication provide narrower safeguards. |
| **Master imports / document outputs** | Company/port CSV imports remain absent. Dedicated estimation PDF, fixture recap PDF, contract PDF, and laytime PDF outputs remain outstanding; invoice/report PDF code exists but routing is broken. |
| **Expanded dashboard** | Implemented dashboard returns active-vessel/voyage counts, pending approvals, and gated open financial balances. The wider requirements list—availability/on-hire cards, upcoming port calls, expiring contracts, period revenue/expenses/profit, utilization, and charts—is only partially delivered. |
| **Port DA / bunker extensions** | Agent advance settlement, blended fuel support, and time-charter delivery/redelivery bunker settlement remain business-rule decisions with follow-on implementation. |

### Business decisions still needed

CII is not the only open decision. `07-BUSINESS-RULES.md` still contains outstanding choices for tax treatment/rounding, FX policy/base currency, overhead allocation, credit/write-off approval, approval thresholds, off-hire deduction, offshore charging, charter-party laytime rules, vessel status/ownership, and data/provider selection. Module demos and business sign-offs are also outstanding.

The accepted scope explicitly excludes the mobile app and Crew Management integration. EU ETS/FuelEU, weather routing, draft/loadable-quantity calculation, canal-toll calculation, Outlook/Google import, report emailing, and accounting integration are deferred in the requirements. They should not be silently counted as delivered features.

## 5. Deployment and documentation gaps

1. **Dependency locks are ignored and untracked.** `.gitignore:45,104` excludes `backend/composer.lock` and `package-lock.json`. A fresh checkout cannot reproduce the audited versions; the runbook's `npm ci` requires a lockfile. Track the application lockfiles and validate a clean install. `GITIGNORE-GUIDE.md:149–169` currently says the opposite of the actual ignore rules.
2. **Backups are incomplete as a system recovery solution.** `scripts/backup-database.sh` only dumps MySQL; it does not back up private uploaded documents. It hardcodes local paths/connection details. Cron installation, monitored off-site storage, and a current DB-plus-documents restore verification remain deployment work. The script's existence does not establish that production backups run.
3. **Restore documentation names the wrong target.** `BACKUP-SETUP.md:89–91` says the command creates `offshore_restored`, but it actually imports into `offshore`. Correct and verify the runbook before operational use.
4. **Health/deployment instructions need validation.** The runbook checks `/api/v1/up` without authentication even though it is inside the authenticated group; Laravel's actual public health route is `/up`. Production paths still use the local ServBay tree/dev ports. The prescribed `npm run type-check` script does not exist; type checking currently runs through `npm run build`.
5. **Automated release enforcement is absent.** No repository CI workflow was found. Add checks for backend/frontend tests, route contracts, seeded roles, and Chromium workflows, using committed dependency versions.
6. **Documentation status is unreliable.** README/final-status/pending pages claim all-green suites; phase-14 session documents acknowledge 121/292 passes; older phase-8 guides call existing frontend screens unimplemented. Some documents label 34 passing frontend tests “100% tested.” Passing tests are not coverage or readiness percentages.
7. **Several onboarding references/examples are incorrect.** References to `03-ARCHITECTURE.md`, `04-DATABASE-SCHEMA.md`, and `06-API.md` point to nonexistent files. Some API examples use outdated fields/routes and omit the response envelope when extracting tokens. Replace examples with verified workflows.
8. **CII documentation requires reconciliation.** `CII-REQUIREMENTS.md` presents example constants/rating boundaries as regulatory facts and has a formula inconsistent with `08-VOYAGE-CALCULATIONS.md` (including the CO2 mass-unit conversion). Treat it as an unverified draft and obtain official, versioned inputs before implementing compliance calculations.

## 6. Test coverage implications

Useful tests exist: estimation golden/edge cases, decimal/FX helpers, offshore revenue proration, laytime examples, role controls, snapshot workflows, document access, finance services, report exports, N+1 guards, and browser flows.

Important gaps exposed by this audit:

- Many operations/report tests depend on successfully completing the entire chartering setup. A missing scenario update route prevents those tests from reaching their actual subject. Keep full-chain integration coverage and also create valid independent fixtures for focused downstream tests.
- The generic authorization sweep permits a 404 from model binding; it cannot prove correct authorization against an existing record or detect every missing route. Supplement it with route-target checks and record-backed permission cases.
- Frontend component tests mock API calls and authentication. They cannot detect the current real route/session failures. Finance pages and many other screens lack focused frontend coverage.
- Payment tests miss cross-currency balance bounds, same-foreign-currency FX gains/losses, mismatched parties/directions, and credit-after-payment reversal.
- Voyage finalization tests check operational edit guards but miss financial writes after finalization and cancellation after invoicing.
- Laytime tests miss the exact demurrage boundary, overlapping same-type exceptions, and stale-result submission.
- Sequence tests verify sequential numbering and rollback, but do not simulate simultaneous independent writers. Real concurrent approvals/conversions/allocations still need targeted validation.
- The existing browser suite has no complete chartering→contract→voyage→financial close journey. It seeds a voyage directly for operations cases.
- Browser coverage is Chromium/desktop only. No current accessibility, multi-browser, or responsive-layout verification was established.
- No code-coverage percentage was measured. No current production load, restore, or penetration-test result is asserted by this audit.

## 7. Recommended repair order and acceptance criteria

1. **Restore usable application access:** fix `/auth/me`, reconcile route contracts/order/parameters, repair document/report endpoints, restore dedicated throttles, and correct seeded roles. Acceptance: the current 292 backend tests and 10 browser tests all pass with the intended non-admin roles and original business assertions preserved.
2. **Repair financial and calculation integrity:** address F1–F5, O1–O2, L1–L3; add focused regression cases with independently calculated expected outcomes. Validate complete cancel/credit/refund/reinvoice and finalize/reopen lifecycles.
3. **Complete required v1 gaps:** agree the capability/approval matrix, integrate actual financial snapshots, implement mandatory alerts/idempotency, and decide CII/live AIS/contractual laytime scope with business owners.
4. **Establish a reproducible release:** commit lockfiles, add CI checks, verify a clean deployment, correct the runbook, and verify monitored DB-plus-documents recovery and actual email delivery.
5. **Perform business UAT:** run complete chartering, offshore, operations, billing, settlement, and reporting journeys with representative vessel data and signed-off calculation examples.

A target of “90% tests passing,” used in some older documents, is insufficient when the remaining failures include authentication, approvals, or financial correctness.

## 8. Reproduction commands and local evidence

From `backend/`:

```bash
APP_ENV=testing DB_DATABASE=offshore_test DB_URL='' ./vendor/bin/phpunit
./vendor/bin/phpstan analyse --memory-limit=1024M --no-progress
./vendor/bin/pint --test
composer audit --format=plain
php artisan migrate:status
php artisan schedule:list
```

From `frontend/`:

```bash
npm run lint
npm run build
npm test
npm run e2e
npm audit --omit=dev
npm audit --json
```

Local audit artifacts:

- PHPUnit JUnit XML: `/var/folders/36/rd2sl4td7t99rb0y28smx_rm0000gn/T/omnirush/offshore-audit-phpunit.xml`.
- Isolated probe script: `/var/folders/36/rd2sl4td7t99rb0y28smx_rm0000gn/T/omnirush/offshore-audit-probes.php`. It refuses any database except `offshore_e2e`; run with `APP_ENV=testing DB_DATABASE=offshore_e2e DB_URL='' CACHE_STORE=array php <script>`. An optional probe-name argument runs one case. It expects Playwright's seeded E2E admin and voyage.
- Playwright screenshots, error contexts, and traces: `frontend/test-results/` (regenerated by the next E2E run).

The original audit produced this report; subsequent repairs and permanent regression coverage are recorded in the repair update at the top.
