# 14 — Decisions (ADR log)

Format: ID · date · decision · reason · status (Proposed / Accepted / Superseded).

| ID | Date | Decision | Reason | Status |
|---|---|---|---|---|
| D-001 | 2026-10-01 | Follow the Crew Management architecture: Laravel 12 API (Controller → FormRequest → Policy → Service → Repository → Model → Resource, interface bindings, DTOs) + React 19/TS/Vite SPA | Same software family; proven internally | Proposed |
| D-002 | 2026-10-01 | **Superseded 2026-10-01:** UI is built with **MUI v7** (Material UI) + Emotion, own maritime theme (navy/teal). The user instructed "implement the best way, no need to copy Crew Management"; the original brief specified MUI. Folder structure (backend/frontend/uploads/docs) stays CM-like | User instruction; mature component set (Drawer, Breadcrumbs, Autocomplete, DataGrid-ready tables), accessibility built in | Accepted |
| D-003 | 2026-10-01 | Single company; no tenancy constructs | Requirement §3 | Accepted |
| D-004 | 2026-10-01 | Separate Offshore codebase and database (`offshore`). Integrate with CM through its REST API, never a shared database | Avoid coupling; independent deploys | Proposed |
| D-005 | 2026-10-01 | Decimal strategy: `bcmath` `Decimal` value object; DB DECIMAL with scales per 05 §0; API returns decimals as strings; money widened to DECIMAL(18,2) | Avoid float errors; large offshore amounts | Proposed |
| D-006 | 2026-10-01 | Keep Sanctum Bearer tokens (CM parity), add token expiry and login throttling. Revisit httpOnly-cookie SPA mode later | Consistency vs XSS token-theft risk; documented trade-off | Proposed |
| D-007 | 2026-10-01 | Single consistent API envelope via `ApiResponse` plus a central exception handler with `error_code` and `request_id` | CM envelope is inconsistent | Proposed |
| D-008 | 2026-10-01 | Offshore is the system of record for commercial/technical vessel data; CM for crew. Link by IMO, plus an explicit mapping id | Clear ownership | Proposed (BR-INT-03) |
| D-009 | 2026-10-01 | Add Vitest for frontend utilities/components | CM has no frontend tests | Proposed |
| D-010 | 2026-10-01 | Leaflet + OpenStreetMap for the fleet map (AIS phase only, lazy-loaded) | No map library exists in CM; open-source | Proposed |
| D-011 | 2026-10-01 | Private document storage with policy-checked download / S3 pre-signed URLs; no public file route | CM `/files/{path}` is unauthenticated | Proposed |
| D-012 | 2026-10-01 | Optimistic locking (`lock_version`) instead of Netpas-style "workbook in use" locks | Web multi-user editing; simpler | Proposed |
| D-013 | 2026-10-01 | Reuse CM in-app notifications design, extended with category/entity/dedupe_key | Reuse; avoid duplicate alerts | Proposed |
| D-014 | 2026-10-01 | Use a **MySQL** test database (not SQLite) | Decimal/locking fidelity | Proposed |
| D-015 | 2026-10-01 | date-fns only; react-hook-form + zod for all forms | Consistency | Accepted |
| D-016 | 2026-10-01 | EU ETS/FuelEU, weather routing, loadable quantity, canal tolls and Outlook import are out of v1 | Scope control; regulatory verification | Proposed |
| D-017 | 2026-10-01 | **No mobile app work in this project for now** | User instruction | Accepted |
| D-018 | 2026-10-01 | Local dev DB: MySQL `offshore`, root/root (local only; production uses a dedicated least-privilege user). Tests use `offshore_test` | User created the DB | Accepted |
| D-020 | 2026-10-01 | Local server is **MySQL 5.7.44**, not 8. Schema stays 5.7-compatible (JSON columns OK; no CHECK constraints, no functional indexes; "one selected row" uniqueness via generated column). Production target remains MySQL 8 / RDS; CI should run on both | Verified with `SELECT VERSION()` | Accepted |
| D-021 | 2026-10-01 | Spatie Role/Permission subclassed as `App\Models\Role|Permission` pinned to the `web` guard (API auth uses `sanctum` guard) | Spatie otherwise resolves the wrong guard on API requests | Accepted |
| D-022 | 2026-10-01 | In-app notifications table named `user_notifications` (not `notifications`) | Avoid clash with Laravel `Notifiable::notifications()` | Accepted |
| D-023 | 2026-10-01 | Private documents stored on the `documents` disk rooted at project-level `uploads/` (outside web root; same folder layout as CM) and streamed only through the policy-checked download endpoint | User asked for the CM-like folder structure; keeps files private | Accepted |
| D-025 | 2026-10-01 | Vessel status uses **two parallel tracks** (commercial / operational) with non-overlapping history, denormalised current status on `vessels`, undo-latest for corrections; status changes cannot be future-dated (planned movements belong to voyages) | Proposal for BR-VS-01 | Proposed — confirm |
| D-026 | 2026-10-01 | Distances: stored table first (manual wins, symmetric fallback), then external providers (none yet), then a **great-circle estimate** flagged `is_estimate` and never cached | Usable without a commercial provider; estimates cannot silently feed calculations | Accepted |
| D-027 | 2026-10-01 | FX resolution: identity → direct → inverse → cross via base currency, latest rate on/before the date; rates stored 8 dp; transactions must snapshot the rate | BR-FX-02 still open for the rate source | Accepted |
| D-028 | 2026-10-01 | Company duplicate detection by normalised name (legal suffixes stripped) + aliases, returned as 409 `possible_duplicate`; user can confirm. Legal-name change auto-creates a former-name alias | Netpas-style aliasing; avoid duplicate counterparties | Accepted |
| D-029 | 2026-10-01 | Optimistic locking (`lock_version`) on vessels and companies; status changes do not bump the vessel version | Concurrent editing safety without blocking the status board | Accepted |
| D-030 | 2026-10-01 | Vessel-type specific fields via `vessel_types.attribute_schema` (decimal/integer/string/bool/select) validated server-side into `vessels.custom_attributes` | Requirement §7 "configurable fields where vessel types differ" | Accepted |
| D-031 | 2026-10-01 | Bank account numbers/IBANs masked unless `companies.bank-view` | Least privilege for payment data | Accepted |
| D-032 | 2026-10-01 | Phase 4 business decisions BR-EST-01, -03, -04, -05, -07 and BR-OP-01 confirmed by the business (see 07) | User confirmation | Accepted |
| D-033 | 2026-10-01 | Estimation engine = pure `VoyageEngine` (time/fuel/revenue/cost components) + one `EstimationStrategy` per type for P&L; Cargo Relet is its own strategy | No type-specific conditionals in the shared calculator | Accepted |
| D-034 | 2026-10-01 | Scenario inputs stored as one versioned JSON snapshot (`estimation_scenarios.inputs`) + `inputs_hash`; results in `scenario_results` with full trace | Snapshot principle; reproducibility; no partial master-data reads | Accepted |
| D-035 | 2026-10-01 | Break-even solved by two engine evaluations (rate 0 and 1) because profit is linear in the rate | Exact for all commission/cost bases; no duplicated formula | Accepted |
| D-036 | 2026-10-01 | Minimal `voyages` + `voyage_snapshots` tables introduced in phase 4 (only for direct conversion); lifecycle remains phase 6 | BR-OP-01 needed a target | Accepted |
| D-037 | 2026-10-01 | MySQL 5.7: generated-column unique keys used for "one selected scenario", "one accepted revision", "one direct voyage per scenario", "one initial/final snapshot"; their parent FKs use RESTRICT (5.7 forbids CASCADE on generated-column bases) | DB-level integrity on 5.7 | Accepted |
| D-038 | 2026-10-01 | Fixtures created in phase 4 have status `draft`; fixture approval and contract creation are phase 5 | Scope | Accepted |
| D-039 | 2026-10-01 | Self-approval guard applies to everyone incl. Super Admin (setting `approvals.self_approval_allowed`, default false) | Segregation of duties | Accepted |
| D-040 | 2026-10-01 | JSON numbers in chartering requests are normalised to decimal strings before validation (`NormalizesDecimals`) | Floats never reach money fields | Accepted |
| D-041 | 2026-10-01 | Fix (phase 2 defect found in phase 4 E2E): unauthenticated API requests without `Accept: application/json` returned 500 (missing `login` route); guests on `api/*` are no longer redirected → JSON 401 | Regression test added | Accepted |
| D-042 | 2026-10-01 | Vitest + Testing Library added (D-009 accepted) | Frontend tests requested | Accepted |
| D-043 | 2026-10-02 | Contract terms versioned in-place: `contract_rates`/`contract_clauses` carry `version_no`; `contract_versions` holds immutable header snapshots; amendments propose full replacement sets | Simple effective-date lookup; history never overwritten | Accepted |
| D-044 | 2026-10-02 | BR-CT-01/02/03 implemented as the documented proposals (one vessel; extensions as end-date amendments; automatic expiry, switchable) | Avoid blocking; reversible via setting | Proposed — confirm |
| D-045 | 2026-10-02 | Failed/cancelled fixture returns the enquiry to evaluating (BR-FX-02 proposal) | Allows renegotiation | Proposed — confirm |
| D-047 | 2026-10-02 | Operational times: API accepts port-local naive times and converts with the port/location timezone (user timezone otherwise); captain reports use ISO with offset. The browser never converts zones | One source of truth for G-06 | Accepted |
| D-048 | 2026-10-02 | Comparison actuals limited to operational metrics; financial lines carried from the estimate and labelled (A-OP-2) | Actual revenue/expenses arrive in phases 9–10 | Accepted |
| D-049 | 2026-10-02 | Completed voyages remain correctable until finalized; reopen reclassifies the old final snapshot as a milestone (payload unchanged) | OP-05 history without mutation | Accepted |
| D-050 | 2026-10-02 | BR-OP-03 implemented as proposal: commencement = first operational status, with back-dating allowed | Unblocks phase 6; confirm | Proposed — confirm |
| D-051 | 2026-10-02 | Off-hire hours recorded without a hire deduction (A-OH-1) until BR-OP-02 is confirmed | Avoid an unconfirmed financial rule | Proposed — confirm |
| D-052 | 2026-10-02 | BR-OA-01/02/03 delivered as settings (`offshore.day_rate_proration`, `offshore.standby_basis`, `offshore.mob_demob_auto`) with the documented proposals as defaults. The basis used is stored on each activity | 07 policy for unconfirmed rules: configurable, not hard-coded | Proposed — confirm |
| D-053 | 2026-10-02 | Activity revenue is a pure domain calculator (`App\Domain\Offshore`). The contract rate snapshot is refreshed on draft save and submit, then frozen at verification | Testable; contract amendments never change verified figures | Accepted |
| D-054 | 2026-10-02 | Activity verification follows the APR-02 segregation rule (no self-verification unless the setting allows it) | Revenue-affecting record | Accepted |
| D-055 | 2026-10-02 | Activities of one vessel may not overlap | One vessel cannot do two activities at once; prevents double billing | Accepted |
| D-056 | 2026-10-02 | The settings API exposes `options`/`help` so enum settings render as dropdowns | Avoid free-text values for rule switches | Accepted |
| D-046 | 2026-10-02 | Contract rates hidden from roles without `contracts.rates.view` (Marine Ops, Read Only) | Commercial confidentiality | Accepted |
| D-024 | 2026-10-01 | Frontend stack: Vite 6, React 19, TypeScript 5.8, MUI 7, React Router 7 (data router, lazy routes), TanStack Query 5, axios, react-hook-form + zod, notistack, date-fns | Best-practice choice per user instruction | Accepted |
| D-019 | 2026-10-01 | Netpas reference video NvHWqBfgGV0 could not be accessed with the available tooling. The analysis relies on netpas.net product and Quick Help pages only | Transparency | Open — stakeholder to review the video |
