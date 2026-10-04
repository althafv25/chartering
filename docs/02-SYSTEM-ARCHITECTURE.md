# 02 — System Architecture

Status: DRAFT v0.1. Contains (A) the Crew Management Architecture Report and (B) the proposed Offshore architecture.

---

# Part A — Crew Management Architecture Report

Source inspected: `/Applications/ServBay/www/crew-management` on 2026-10-01 (read-only). Source code, migrations and manifests were treated as authoritative over its README/docs, which contain stale claims.

## A1. Stack (verified from manifests)

| Layer | Actual |
|---|---|
| Backend | Laravel `^12.0`, PHP `^8.2`, Sanctum `^4.0`, Spatie Permission `^6.25`, Spatie Activitylog `^4.12`, barryvdh/laravel-dompdf `^3.1`, Intervention Image `^3.11`, Maatwebsite Excel `^3.1` (installed, **not used** in app code) |
| Dev/QA | PHPUnit `^11.5`, Larastan `^3.10`, Pint `^1.29`, Mockery, Faker |
| Web | React `^19.2`, TypeScript `~6.0`, Vite `^8.1`, React Router `^7.18`, TanStack Query `^5.101`, Axios `^1.18`, **Tailwind CSS `^4.3` (no MUI)**, lucide-react icons, sonner toasts, react-select, recharts, clsx, react-helmet-async, react-hook-form + zod (lightly used), date-fns **and** dayjs |
| Mobile | Expo/React Native (minimal) |
| DB | `.env.example` SQLite; production doc MySQL |
| Queue/cache/session | `database` drivers |
| Mail | SMTP in production, `log` locally |

## A2. Repository layout

```
crew-management/
  backend/      Laravel API (routes/api.php, app/*, database/*, tests/*)
  frontend/     React SPA
  mobile/       Expo client
  uploads/      public disk root (outside backend)
  docs/         architecture/API/schema/deployment
  index.php, .htaccess   dev/shared-host router: /api,/storage → backend, else → frontend/dist
```

## A3. Backend architecture

Flow: `route → controller → FormRequest → Policy → Service → Repository → Model → API Resource`.

| Concern | Implementation |
|---|---|
| Controllers | `app/Http/Controllers/Api/*` (older ones under `Api/V1/*`). Constructor-inject `XxxServiceInterface`. Base `Controller` uses `AuthorizesRequests`. |
| Validation | `app/Http/Requests/<Module>/{Store,Update}XxxRequest.php`; `authorize()` calls `$this->user()->can(...)`. Some controllers validate inline. |
| DTOs | `app/DTO/<Module>/{Create,Update}XxxDTO.php`, readonly promoted constructor props, `fromArray()`. |
| Services | `app/Services/XxxService.php` implements `App\Contracts\Services\XxxServiceInterface`. Business rules, status transitions (`assertStatus`), `DB::transaction` + `lockForUpdate()` on approvals, `activity()->log()`. |
| Repositories | `app/Repositories/XxxRepository.php` implements `Contracts\Repositories\XxxRepositoryInterface`; filtering with `->when()`, `paginate($perPage)`, code generation (`SUP-00001`). |
| Bindings | `AppServiceProvider` (services, storage) and `RepositoryServiceProvider` (repos). |
| Resources | `app/Http/Resources/XxxResource.php` — explicit field maps, ISO-8601 dates, `whenLoaded` for relations, decimals cast to float. |
| Enums | `App\Enums\Permission` (string-backed, `module.action`), `UserRole`, `UserStatus`. |
| Traits | `ApiResponse` (envelope helpers), `HasAuditLog` (Spatie `LogsActivity`: fillable, dirty only, log name = table). |
| Jobs | `CheckExpiryAlertsJob` (thresholds 90/60/30/7), `SendContractNotificationJob`, `SendTravelAgentNotificationJob`. |
| Scheduler | `bootstrap/app.php → withSchedule`: `expiry:check` daily 06:00, `withoutOverlapping`. |
| Mail | `app/Mail/*Mail.php` + `resources/views/emails/*.blade.php`. |
| PDF | DomPDF `Pdf::loadView('pdf.procurement-document', [...])->download()`; generic `ExportsReports` concern (`respondWithReport`, `streamCsv`, `streamPdf`, `toRows`). |
| Middleware | None custom; `withMiddleware` empty. No rate limiting configured. |
| Exceptions | `withExceptions` empty → Laravel default JSON for API (422 validation `{message, errors}`, 403, 404, 500 hidden when `APP_DEBUG=false`). |
| Logging | Default `stack/single`; ad-hoc `\Log::info/error` in controllers. |

## A4. Frontend architecture

| Concern | Implementation |
|---|---|
| Entry | `main.tsx → App.tsx`: HelmetProvider → QueryClientProvider (retry 1, staleTime 60 s, no refetchOnWindowFocus) → ThemeProvider → AuthProvider → BrowserRouter → `AppRoutes` + sonner `Toaster`. |
| Routing | `routes/index.tsx`, flat `<Route>` list, `RoleGuard permissions=... redirectTo="/unauthorized"` per route. |
| Layouts | `AppLayout` (Sidebar + Header + `<Outlet/>`, wrapped in `ProtectedRoute`), `SelfServiceLayout`, `VesselPortalLayout`. |
| Navigation | `components/layout/Sidebar.tsx` — `NavItem{label,href,icon,permission}` + collapsible `NavGroup`; items filtered by `hasPermission`. |
| HTTP | `api/axios.ts` (base `VITE_API_URL || /api/v1`, Bearer token from `localStorage.cms_token`, 401 → clear token + redirect `/login`). Per-module `api/<module>.ts`. |
| Server state | `hooks/use<Module>.ts`: `useQuery(['module', filters])`, `useMutation` → `invalidateQueries` + `toast.success/error(err.response.data.message)`. |
| Types | `types/<domain>.ts`, `types/api.ts` (`ApiResponse<T>`, `PaginatedResponse<T>`, `PaginationMeta`). |
| Auth state | `AuthContext` (`user`, `login`, `logout`, `refreshUser`, `hasRole`, `hasPermission`). |
| Permissions | `constants/permissions.ts` enum (partial), most checks inline strings. |
| UI kit | `components/ui/`: Button, Input, Select, SearchableSelect, Table, Pagination, Modal, ConfirmDialog, Card, Badge, Avatar, Spinner/FullPageSpinner, EmptyState, Toggle. `components/common/`: FileUpload, CurrencySelect. Dashboard: KPICard, ActivityFeed, QuickActions. Notifications: NotificationBell, NotificationItem. |
| Missing | No Drawer, no Breadcrumb, no DatePicker (native inputs), no frontend tests. |
| Styling | Tailwind v4 `@theme` tokens: `primary-*` (ocean blue → navy), `surface-*` (slate); fonts Inter / Outfit. Dark mode disabled (`@variant dark (never)`). |
| Page pattern | `<Helmet>` title; header row (h1 + subtitle + primary action gated by permission); KPI `Card` row; filter `Card` (search + selects, reset page to 1); `Card` containing `Table` + `Pagination`; `ConfirmDialog` for destructive actions. |

## A5. Database conventions

- `$table->id()` bigint PK; `foreignId()->constrained()`; audit FKs `created_by` / `approved_by` `nullOnDelete`.
- Business code column unique (`supplier_code`, `po_number`).
- `status` as `string` with default and comment listing values (no DB enum; validation by FormRequest `in:` + service `assertStatus`).
- Money `decimal(15,2)`; `currency` `string(3)`; coordinates `decimal(10,7)`.
- `timestamps()`; `softDeletes()` on master/business records.
- Indexes on `status` and name columns.
- Migrations named `YYYY_MM_DD_HHMMSS_create_xxx_table.php`; ~81 migrations; additive changes.
- **No exchange-rate storage anywhere.** Currencies master: `code, name, symbol, status`.

## A6. Authentication

- Sanctum personal access tokens (Bearer), stateless; `POST /api/v1/auth/login` → `{user, token}`; token abilities = permission names; previous token for the same `device_name` revoked; inactive users rejected; login logged to activity log.
- Password reset via Laravel Password broker; password change revokes other tokens.
- Token in `localStorage` (XSS exposure risk — accepted by CM; noted for Offshore in 14-DECISIONS D-006).

## A7. Authorization

- Spatie roles/permissions; `Permission` enum → `RolesAndPermissionsSeeder` (`firstOrCreate`, `syncPermissions`). `company-admin` receives all permissions.
- Policies per model calling `hasPermissionTo('x.y')`; controllers call `$this->authorize()`; FormRequests call `can()`.
- No route-level permission middleware; some endpoints miss checks (e.g. nested supplier contacts).
- Record-scoping pattern (`/me/*`) resolves the owned record from the authenticated user, never from input.

## A8. API conventions

- Prefix `/api/v1`. `apiResource` + action routes `POST /{resource}/{id}/{action}` (submit/approve/reject/cancel/close), `GET /{resource}/{id}/pdf`, nested `/{parent}/{id}/{child}`.
- Query: `search`, `status`, domain filters, `page`, `per_page` (default 15), `all=1` for unpaginated lists.
- Envelope (intended): `{success, message, data, meta?, links?}`; errors `{success:false, message, errors?}`. **Inconsistent in practice**: newer controllers return `{message, data}` or raw resource collections (`{data, links, meta}`).

## A9. File storage

- `FileStorageInterface` → `LocalFileStorage` on the `public` disk whose root is `../uploads`. UUID filenames.
- Generic `/upload` endpoint (authenticated) accepts arbitrary `category`/`custom_path` (path not constrained).
- `GET /api/v1/files/{path}` **public, unauthenticated**, serves any file on the public disk.
- Limits in `config/uploads.php` (100 MB, extension allow-list).

## A10. Audit

- `HasAuditLog` on models; `activity()` for explicit domain events (approve/reject/login); `AuditLogController` + `AuditLogsPage`.
- IP recorded only for login.

## A11. Notifications

- Custom `notifications` table (`user_id, type(info|success|warning|error), title, message, action_url, action_text, is_read, read_at`), `NotificationService::send/sendToMany/info/warning…` with optional email (`NotificationMail`).
- Frontend `NotificationBell` + notifications page; polling via TanStack Query.
- Scheduled checker job pattern with day thresholds.

## A12. Deployment (from `docs/PRODUCTION_DEPLOYMENT.md`)

- Ubuntu 22.04, Nginx, PHP-FPM 8.2+, MySQL, Node 20 for builds, Let's Encrypt.
- `/var/www/<domain>/{backend,frontend,uploads}`; frontend `dist/` served statically, `/api` → Laravel `public/index.php`; `/uploads/` alias.
- Frontend env `VITE_API_URL`, `VITE_BASE_URL` baked at build.
- Supervisor `queue:work --queue=notifications,default --tries=3`; cron `schedule:run` every minute.
- No AWS-specific configuration in code; S3 disk present but unused.

## A13. Reusable assets for Offshore

| Reuse as-is (copy pattern / code into new repo) | Reuse with fixes |
|---|---|
| Controller/Service/Repository/DTO/Resource/Policy layering and interface bindings | `ApiResponse` — make it the *only* response path |
| `HasAuditLog` trait | Add IP/user-agent tap for every activity |
| `NotificationService` + notifications table + bell | Add `category` and `entity_type/entity_id` columns |
| `ExportsReports` concern (CSV/PDF) | Add Excel via installed Maatwebsite |
| DomPDF blade document pattern | — |
| Permission enum + seeder + policies + `RoleGuard` + permission-filtered Sidebar | Enforce enum use in frontend |
| UI kit (`components/ui/*`), design tokens, page layout pattern | Add Drawer, Breadcrumbs, DateTimeInput, MoneyInput, StatusBadge, Tabs, FilterBar |
| Axios instance, `types/api.ts`, hooks pattern | Error normaliser for the new error envelope |
| Auth flow (`AuthService`, login page, `AuthContext`, `ProtectedRoute`) | Add login throttling |
| `FileStorageInterface` | Implement private disk + signed URLs; no public file route |

## A14. Do not copy

Public `/files/{path}` route; unconstrained `custom_path` uploads; mixed response envelopes; missing login rate limit; dead `SettingsRepository` binding; tenancy remnants (`SubscriptionPlan`, `company.*` permissions, nwidart stubs, `modules_statuses.json`); `index.css` `.bg-blue-500` override and broken gradient classes; unused `dark:` classes; both date-fns and dayjs; inline permission strings; very large page files (e.g. 60 KB `PayrollPage.tsx`); `.claude/worktrees`, `.DS_Store`, `.phpunit.result.cache` in repo; "Added" commit messages; payroll-specific logic.

---

# Part B — Proposed Offshore System Architecture

## B1. Topology

```
Browser (React SPA, same design system as CM)
   │ HTTPS, Bearer token
Nginx ── /            → frontend/dist (static)
      └─ /api/v1/*    → PHP-FPM → Laravel 12 API
                              │
              ┌───────────────┼────────────────────────┐
           MySQL 8*        Queue worker            Scheduler (cron)
          (InnoDB)       (database driver,        schedule:run
                          Redis optional)
              │
       Private document disk (local `private` → S3 private bucket on AWS)
              │
   Optional outbound adapters: DistanceProvider, AisProvider, FxRateProvider,
                               CrewManagementClient (HTTP, service token)
```

Separate repository/directory `offshore/` with the CM-like layout:

```
offshore/
  backend/    Laravel 12 API
  frontend/   React 19 + Vite + MUI SPA
  uploads/    private documents (Laravel `documents` disk; never web-served)
  docs/       these documents
```

\* Local dev runs MySQL 5.7.44 (D-020).

### B1.1 Implemented in phase 2 (as built)

| Area | Location |
|---|---|
| Response envelope | `app/Http/Concerns/ApiResponse.php` (used by base `Controller`) |
| Error rendering | `app/Exceptions/ApiExceptionRenderer.php`, `BusinessRuleException` (409), `IntegrationException` (502) |
| Request id | `app/Http/Middleware/AssignRequestId.php` (header `X-Request-Id`, log context, audit properties) |
| Rate limits | `AppServiceProvider::configureRateLimiting` — `login`, `password`, `api`, `uploads` |
| RBAC | `App\Enums\Permission`, `App\Enums\UserRole`, `RolesAndPermissionsSeeder`, policies in `app/Policies`, `Gate::before` for super-admin |
| Audit | `App\Models\Concerns\HasAuditLog` + global `Activity::saving` enrichment (ip, user agent, request id) |
| Settings | `config/offshore.php` registry + `SettingsService` (typed, allow-listed keys) |
| Notifications | `user_notifications` table, `NotificationService` (dedupe keys) |
| Documents | `DocumentService`, `DocumentPolicy` (document permission AND parent permission), registry `config('offshore.documents.parents')` |
| Numbering | `SequenceService` (row-locked, gap-free, transaction-aware) |
| Frontend | `frontend/src`: `api/` (axios client + typed endpoints), `auth/` (context, guards), `components/` (DataTable, PageHeader, FilterBar, StatusChip, ConfirmDialog, LoadingButton, StatCard, Feedback), `layouts/` (AppLayout with responsive Drawer sidebar, AuthLayout), `config/navigation.ts`, `pages/` |

## B2. Backend layering (unchanged from CM, with additions)

```
app/
  Contracts/{Repositories,Services,Integrations}/
  DTO/<Module>/
  Domain/                    # NEW: pure calculation code, no Eloquent/HTTP
    Money/                   #   Money, Decimal helpers (bcmath), Currency, FxRate
    Estimation/              #   VoyageEstimator, inputs/results value objects
    Laytime/                 #   LaytimeCalculator, TermSet, Period
    Bunker/                  #   RobReconciler
    Cii/                     #   CiiCalculator + versioned FormulaSet
  Enums/                     # Permission, UserRole, statuses per module (string-backed)
  Exceptions/                # NEW: BusinessRuleException, IntegrationException, ConcurrencyException
  Http/Controllers/Api/<Module>/
  Http/Requests/<Module>/
  Http/Resources/<Module>/
  Integrations/              # NEW: Distance/, Ais/, Fx/, CrewManagement/ adapters
  Jobs/  Mail/  Policies/  Providers/  Repositories/  Services/  Traits/
```

Rules:
1. Controllers thin; only `ApiResponse` helpers for output.
2. Services own transactions, status transitions, snapshot creation, audit events.
3. `Domain/*` calculators are deterministic pure PHP, unit-tested with fixtures, versioned (`calculation_version` persisted with results).
4. Repositories own queries; no query building in controllers.
5. All decimals handled with `bcmath` via a `Decimal` value object; Eloquent casts `decimal:N` are strings — never cast to float before computation. Resources serialize decimals as **strings** (D-005).

## B3. Error handling

Registered in `bootstrap/app.php → withExceptions` (CM leaves this empty):

| Exception | HTTP | `error_code` |
|---|---|---|
| `ValidationException` | 422 | `validation_failed` (+ `errors`) |
| `AuthenticationException` | 401 | `unauthenticated` |
| `AuthorizationException` | 403 | `forbidden` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | `not_found` |
| `BusinessRuleException` | 409 | domain code e.g. `voyage_already_finalized` |
| `ConcurrencyException` (stale `lock_version`) | 409 | `stale_record` |
| `IntegrationException` | 502 | `integration_failed` (+ provider) |
| `ThrottleRequestsException` | 429 | `too_many_requests` |
| anything else | 500 | `server_error` — generic message, logged with request id |

Envelope: `{ success:false, message, error_code, errors?, request_id }`. `X-Request-Id` header added by middleware and included in logs.

## B4. Module architecture

| Module | Key services | Depends on |
|---|---|---|
| Core/Admin | AuthService, UserService, RoleService, SettingsService, AuditLogService, NotificationService, DocumentService, ApprovalService | — |
| Masters | VesselService, VesselStatusService, CompanyService, ContactService, PortService, FuelTypeService, CurrencyService, ExchangeRateService, CargoTypeService, ActivityTypeService, MilestoneTypeService | Core |
| Distance | DistanceService (+ providers) | Ports |
| Chartering | EnquiryService, EstimationService, ScenarioService, OfferService | Masters, Distance, Domain/Estimation |
| Fixtures & Contracts | FixtureService, ContractService, ContractAmendmentService | Chartering |
| Operations | VoyageConversionService / FixtureConversionService (conversion), VoyageLifecycleService (lifecycle, snapshots), VoyageMetricsService (actuals, comparison), PortCallService, MilestoneService, OffHireService, CaptainReportService | Fixtures/Contracts |
| Offshore | OffshoreActivityService (validation, rate snapshot, lifecycle), `Domain\Offshore\ActivityRevenueCalculator` (pure); projects handled in OffshoreProjectController | Operations, Contracts |
| Bunkers | BunkerService (stems, ROB ledger, reconciliation) | Operations |
| Port DA | PortDaService | Operations |
| Laytime | LaytimeService (+ Domain/Laytime) | Operations, Contracts |
| Finance | VoyageFinancialService (revenue/expense lines, P&L), InvoiceService, PaymentService, BalancingService | Operations, Offshore, Bunkers, DA, Laytime |
| Finalization | FinalizationService | Finance |
| Reports/Statistics | ReportService, StatisticsService (read models) | all |
| AIS | AisService, AisIngestJob, providers | Masters |
| CII | CiiService (+ Domain/Cii) | Operations, Bunkers, Reports |
| Integration | CrewManagementClient | Masters |

## B5. Navigation (frontend Sidebar config)

```
Dashboard
Chartering ▸ Enquiries · Estimations · Offers · Fixtures
Contracts
Operations ▸ Voyages (port calls inside the voyage) · Offshore Activities · Captain Reports · Bunkers · Port DA · Laytime
Fleet ▸ Vessels · Vessel Status · Vessel Performance · Fleet Map* · AIS Tracking*
Commercial ▸ Revenue & Expenses · Invoices · Payments · Voyage P&L · Balancing
Masters ▸ Ports · Address Book (filter by role: Customers/Charterers/Brokers/Agents/Suppliers/Owners) · Fuel Types · Cargo Types · Activity Types · Milestone Types · Currencies & FX
Reports ▸ Reports · Statistics
Documents
Administration ▸ Users · Roles & Permissions · Approval Settings · Audit Logs · Settings · Integrations
```
`*` shown only when AIS integration is enabled. Customers/Charterers/Brokers/Agents/Suppliers are filtered views of the single Address Book (no duplicate tables).

## B6. Frontend architecture

**Updated (D-002 superseded):** MUI v7 with a custom theme replaces the CM Tailwind kit. Patterns kept from CM: api → hooks/query → pages split, permission-filtered sidebar, route guards, toast feedback. Planned additions:
- `components/ui/` additions: `Drawer`, `Breadcrumbs`, `Tabs`, `StatusBadge` (maps enum → colour + label), `MoneyDisplay`/`MoneyInput` (string decimals, currency), `DateTimeInput` (with timezone), `FilterBar`, `KeyValueGrid`, `ComparisonTable` (Estimate/Operation/Final/Variance).
- Forms: standardise on **react-hook-form + zod** (already in CM deps) for complex forms (estimation, invoice).
- Dates: **date-fns** only.
- Permissions: single `constants/permissions.ts` generated/maintained from backend enum; no string literals.
- Calculation UX: forms post inputs to `POST /estimations/{id}/scenarios/{sid}/calculate` (debounced) and render returned results; React never computes money.
- Map (AIS phase): Leaflet + OpenStreetMap tiles (new dependency, justified in D-010), loaded lazily.
- File size discipline: page < ~400 lines; split into `components/<module>/`.

## B7. Security

| Area | Measure |
|---|---|
| Auth | Sanctum Bearer tokens (CM parity); token expiry configured (`sanctum.expiration`, e.g. 12 h); `throttle:login` (5/min per email+IP) on login & password routes; `throttle:api` (120/min/user). |
| Authorization | Policy per model; every controller action `authorize()`; feature tests assert 403 per role. `Gate::before` for `super-admin`. |
| Documents | `private` disk (local) / S3 private bucket; download only via authenticated `GET /documents/{id}/download` which checks policy on the parent entity, then streams (local) or returns 5-minute S3 pre-signed URL. No public file route. Upload: MIME sniffing + extension allow-list + size limit; server-generated paths only. |
| Input | FormRequests; Eloquent bindings; no raw SQL interpolation. |
| Output | React escapes; PDF blades escape `{{ }}`. |
| Secrets | `.env` only; provider credentials never sent to frontend; settings UI shows "configured/not configured". |
| CSRF | Not applicable for Bearer-token API (no cookies). If SPA cookie mode adopted later, enable Sanctum stateful CSRF. |
| Headers | Nginx HSTS, nosniff, frame-options, CSP for SPA. |
| Audit | Activity log for all mutations; IP + user agent. |

## B8. Concurrency & integrity

- Approval/conversion/finalization/payment allocation: `DB::transaction` + `lockForUpdate()` + status assertion (CM pattern).
- Editable aggregates (estimation scenario, invoice draft, voyage) have `lock_version` integer; updates require matching version → `409 stale_record` (replaces Netpas "workbook in use" lock).
- Idempotency: `Idempotency-Key` header accepted on conversion, invoice issue and payment create; stored in `idempotency_keys` (key, user, route, response hash, 24 h TTL).
- Unique constraints: `fixtures.offer_revision_id`, `voyages.fixture_id`, `invoices.invoice_number`, `payments(bank_reference, company_id)` (BR-FIN-03), number sequences via `document_sequences` row locks.

## B9. Integration with Crew Management

Findings: CM `vessels` table holds `name, imo_number, flag, vessel_type, build_year, gross_tonnage, status, position fields, fleet_id`; crew contracts link seafarer↔vessel↔rank; CM exposes Sanctum-protected `/api/v1/vessels`, crew contracts, planning endpoints.

Recommendation (D-008): **API integration, no shared database.**

1. Offshore is the system of record for commercial/technical vessel particulars. CM remains the system of record for crew, certificates of seafarers, rotations.
2. Link key: **IMO number** (fallback: explicit `crew_management_vessel_id` mapping column on Offshore `vessels`).
3. Offshore backend calls CM API through `CrewManagementClient` (service account user in CM with a read-only role, token stored in Offshore `.env`). Read use cases: crew on board for a voyage period, POB counts, crew change events relevant to port calls.
4. Optional later: CM consumes an Offshore read endpoint for vessel schedule (voyages/port calls) using its own service token.
5. Responses cached (15 min) and shown as "from Crew Management" with timestamp; failures degrade gracefully (502 integration error contained in widget).
6. SSO is out of scope for v1; separate user bases. **BR-INT-01:** confirm whether shared login is required.

## B10. Performance

Indexes on every FK + status + date columns used in filters; eager loading in repositories; pagination everywhere (max `per_page` 100); dashboard/statistics cached 5 min with tag invalidation on financial writes; AIS positions in a dedicated narrow table with `(vessel_id, observed_at)` index, monthly partitioning considered at > 50 M rows; track simplification server-side (Douglas-Peucker) before sending; queues for ingest, notifications, PDF batch exports. Redis optional (`CACHE_STORE`/`QUEUE_CONNECTION` env switch, CM uses database drivers).
