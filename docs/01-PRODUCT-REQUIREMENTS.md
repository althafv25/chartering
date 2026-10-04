# 01 — Product Requirements

Status: DRAFT v0.1 (Phase 1 — discovery). Awaiting approval.
Owner: Lead architect. Last updated: 2026-10-01.

## 1. Product statement

An internal, single-company **Offshore Chartering & Vessel Operations Management System** covering the commercial and operational lifecycle of owned/managed/chartered vessels:

```
Enquiry → Estimation (scenarios) → Offer/Negotiation → Fixture → Contract
       → Voyage / Offshore Operation → Port calls, activities, reports, bunkers, DA, laytime
       → Revenue / Expenses → Invoicing → Payments → Finalization → Balancing → P&L / Statistics
```

Data entered once flows forward; each stage keeps a snapshot of the commercial conditions valid at that stage (see 07-BUSINESS-RULES §Snapshot).

The product is functionally comparable to Netpas Tramper Business (used as a **workflow reference only** — no code, branding, UI assets or layouts are copied) and extended for **offshore** work (day-rate charters, mobilization/demobilization, offshore activities, billable/standby hours).

It belongs to the same software family as the existing **Crew Management** system (`../crew-management`) and follows its stack, architecture and design language (see 02-SYSTEM-ARCHITECTURE).

## 2. Constraints

| # | Constraint |
|---|-----------|
| C1 | Single company. No `tenant_id`, `organization_id`, tenant middleware/databases/packages. |
| C2 | Same stack as Crew Management: Laravel 12 / PHP ^8.2 / Sanctum / Spatie Permission + Activitylog / React 19 + TypeScript + Vite + Tailwind CSS v4 + TanStack Query. **Note:** Crew Management does *not* use MUI (see 14-DECISIONS D-002). |
| C3 | All financial/operational calculations run in Laravel domain services. React only displays results. |
| C4 | Backend authorization is mandatory for every endpoint. |
| C5 | AIS and external distance providers are optional; system is fully usable on manual data. |
| C6 | No direct database coupling with Crew Management. |
| C7 | Decimal arithmetic for money, rates, fuel, distance, FX — never PHP float for persisted financial values. |
| C8 | Incremental delivery (phases in 13-DEVELOPMENT-PROGRESS). |

## 3. Users and roles

Super Admin, Management, Chartering, Operations, Commercial, Finance, Accounts, Marine Operations, Read Only. Detailed matrix: 10-PERMISSIONS.

## 4. Netpas Tramper Business — functional analysis

### 4.1 Sources consulted (2026-10-01)

| Source | Result |
|-------|--------|
| https://www.netpas.net/products/detail/tb (product page) | Read. |
| https://www.netpas.net/products/detail/nt (Tramper Single) | Read — operation, milestones, comparison, off-hire, laytime. |
| https://www.netpas.net/products/detail/ne (Estimator) | Read — estimation types and tools. |
| https://www.netpas.net/products/detail/nvm (Vessel Monitor AIS) | Read. |
| https://www.netpas.net/support/quickHelpView/tb/1..20 | Read — text portions only (many details are image-only). |
| https://www.youtube.com/watch?v=NvHWqBfgGV0 | **Not accessible with available tooling** (page rendered without content; no metadata obtained). Nothing in this document is derived from that video. **Action: a stakeholder should watch it and record any additional workflow observations in 14-DECISIONS.** |

Anything not stated in those sources is **not assumed**. Where our product needs a rule that Netpas does not document, it is marked **BUSINESS RULE REQUIRES CONFIRMATION** (consolidated list in 07-BUSINESS-RULES §BR-Confirm).

### 4.2 Documented Netpas capabilities

| Netpas capability (documented) | What the source says | Our equivalent module |
|---|---|---|
| Home | Summary of recent estimations, ongoing operations, vessel monitor, statistics | Dashboard |
| Estimation | Voyage, Cargo Relet and Time Charter estimation "workbooks"; folder permissions; a workbook in use by another user is locked (user can message the holder) | Estimation (+ scenarios, optimistic locking) |
| Estimator tools | Loadable quantity (draft-based), freight simulator, estimation analyzer (optimal freight/hire, break-even), bunker simulator (ECA/LSDO/LSFO/IFO/MDO, full vs eco speed), (S)ECA distance & bypass options, port charge & canal toll calculator, individual speed/consumption, laytime estimator, CEV calculator, LT/BT charge | Estimation engine + scenarios; some tools deferred (see §6) |
| Operation | Estimation transferred to operation; "To be updated" list; milestones (save snapshots at any stage); comparison report between Initial / Milestone / Active sheets (print, Excel/PDF, email); off-hire records; complete operation | Voyage/Operation, Milestones, Snapshots, Off-hire, Comparison |
| Laytime calculator | Demurrage/despatch estimation and calculation; SHEX terms mentioned | Laytime |
| Contract | Company contracts; Voyage Charter and Time Charter contracts; can be created from estimation/operation | Contracts |
| Balancing | Invoice list (issued & received), account view (receivable/payable per account), calendar of receivable/payable and cash-flow chart | Balancing / Receivables / Payables |
| Invoice | Hire invoice (simple / detailed), freight invoice (simple / detailed), other invoices issued/received during operation | Invoicing |
| Statistics | Revenue and profit tables/charts with view options; permission-gated | Statistics |
| Vessel manager | Shared vessel list, import, vessel particulars, performance record, voyage summary, annual CII per vessel | Vessel Master + Performance |
| Address book | Company-based (not person-based), multiple PICs, alias for former names, Outlook/Google import | Address Book / CRM |
| Port DA | DA and SOF records auto-saved from operations or entered manually; shared for future reference | Port DA + historical port costs |
| Cargo | Cargo list | Cargo types master |
| Bunker | Bunker list, single and blended bunkers; only listed bunkers usable in estimation | Fuel types master (+ blends) |
| Vessel monitor | Satellite/terrestrial AIS + captain reports; position reports auto-saved; voyage report and fleet status report; ROB, ETA, remaining distance; speed/consumption comparison vs initial | AIS module + Captain Reports |
| Admin | Activate/deactivate members, admin rights, permission per area/folder/member (read/edit/delete) | Users/Roles/Permissions (Spatie, reused) |
| CII | Simulate CII in estimation and operation; annual rating A–E per vessel; year comparison; manual edit | CII module |
| EU ETS & FuelEU | Emission and allowance cost calculation | **Deferred** — architecture hook only (see §6) |
| Multi currency | Main + sub-currency with exchange rate per estimation/operation; operations whose main currency ≠ default are excluded from statistics | Multi-currency with per-transaction FX snapshot; statistics converted to base currency (improvement — see BR-FX-04) |
| Cloud co-work | Shared data, encrypted storage, per-user permissions | Central web app; RBAC; private document storage |

### 4.3 Gaps — capabilities we need that Netpas does not document

| Area | Gap |
|---|---|
| Offshore day-rate charters | Day/hour rates, standby rates, mobilization/demobilization fees, billable vs non-billable hours. |
| Offshore activities | Activity log per vessel/project/location with billable hours and fuel. |
| Enquiry & offer history | Netpas documents estimation → operation; it does not document an enquiry/offer/counter-offer register. We require one with immutable offer revisions. |
| Fixture as an entity | Netpas moves estimation → operation/contract; we add a distinct Fixture record (recap snapshot). |
| Approval workflow | Not documented; we add configurable approvals. |
| Payments & partial payments | Balancing shows received/paid; detailed payment allocation is not documented. |
| Estimated vs actual bunker reconciliation with discrepancy flags | Comparison exists in NVM; we formalise it. |
| Document management | Not documented as a module. |
| Notifications | Not documented. |
| Crew integration | Not applicable to Netpas. |

## 5. Capability specifications (summary)

For each capability: purpose, inputs, outputs, workflow, entities, calculations, permissions, reports, integrations. Entities refer to 04/05; calculations to 08; permissions to 10.

### 5.1 Dashboard
- Purpose: at-a-glance commercial/operational state.
- Inputs: none (read model). Filters: period, vessel.
- Output: KPI cards — Active Vessels, Available Vessels, On-Hire Vessels, Active Voyages, Upcoming Port Calls (7 days), Current Offshore Operations, Revenue (period), Expenses (period), Voyage Profit (period), Outstanding Invoices, Vessel Utilization %, Contracts expiring (60 days), Pending Approvals (mine), Operational Alerts. Charts (max 4 by default): Monthly Revenue vs Expenses, Monthly Profit, Utilization by Vessel, Revenue by Vessel.
- Entities: read-only aggregations (`DashboardService`), cached 5 min.
- Permissions: `dashboard.view`; financial widgets additionally require `commercial.financials.view`.

### 5.2 Vessel Master
- Purpose: single source of commercial and technical particulars used by estimation/operations.
- Inputs: identity (name, IMO, MMSI, call sign, official number, type, subtype, flag, port of registry, year built, class society), parties (owner, manager, commercial/technical manager → address-book companies), dimensions (LOA, LBP, beam, depth, summer draft, air draft), tonnage (DWT, GT, NT), machinery (main/aux engines, power kW, propulsion), speeds (service, max, eco), consumption profiles per mode × fuel, offshore capabilities (deck area, cargo capacities, bollard pull, DP class, crane SWL, POB/accommodation), remarks, custom attributes per vessel type.
- Output: vessel record, vessel card, performance history.
- Workflow: create → active → (sold/scrapped → inactive). Consumption profile changes are versioned (effective_from).
- Entities: `vessels`, `vessel_types`, `vessel_consumption_profiles`, `vessel_consumption_rates`, `vessel_type_attributes`, `vessel_attribute_values`.
- Permissions: `vessels.*`.
- Integration: Crew Management vessels linked by IMO (see 02 §9).

### 5.3 Vessel Status & Availability
- Statuses: Available, Open, On Hire, Off Hire, Under Charter, Mobilizing, Demobilizing, At Sea, At Port, Offshore Operation, Standby, Maintenance, Dry Dock, Laid Up.
- Two dimensions are mixed in that list (commercial vs operational/position). **BUSINESS RULE REQUIRES CONFIRMATION (BR-VS-01):** whether to track one status or two parallel statuses (commercial + operational). Proposed: two parallel tracks.
- History: `vessel_status_history` (status, effective_from, effective_to, location, reason, remarks, changed_by). Current status = open-ended row. Non-overlap enforced in service with row lock.
- Permissions: `vessel-status.view`, `vessel-status.update`.

### 5.4 Address Book / CRM
- Organizations (`companies`) separate from people (`contacts`); company may have many roles (owner, charterer, broker, customer, agent, supplier, shipyard, surveyor, port authority, insurer, other) via `company_roles`.
- Fields: legal name, trading name, aliases/former names (Netpas documents aliasing), department, email, phone, mobile, address, country, website, VAT/tax number, payment terms, default currency, remarks, bank details.
- Permissions: `contacts.*`.
- Import from CSV (phase 3); Outlook/Google import **deferred**.

### 5.5 Port Master
- Fields: name, UN/LOCODE (unique when present), country, region, lat/long, time zone (IANA), max draft, restrictions, notes, alternative names.
- Related: agents (`port_agents`), historical DA, voyage calls, cached distances.
- Permissions: `ports.*`.
- **BR-PORT-01:** source of the initial port list (UN/LOCODE public dataset vs commercial) requires confirmation.

### 5.6 Chartering / Enquiry
- Workflow: Enquiry (RFQ) → Requirement → Vessel selection → Estimation(s) → Offer → Counter offer(s) → Fixture → Contract.
- Enquiry fields: source (direct/broker), charterer, broker, business type (voyage charter / time charter / offshore charter / cargo relet), cargo/service, quantity & tolerance, load/discharge ports or offshore location, laycan / period, duration, rate idea, currency, commission terms, terms, remarks, status (open, quoting, offered, fixed, lost, cancelled), lost reason.
- Offers: each offer/counter-offer is an immutable `offer_revisions` row (never updated after send). Direction: outbound/inbound. Linked to an estimation scenario.
- Permissions: `enquiries.*`, `offers.*`.

### 5.7 Voyage Estimation (critical)
See 08-VOYAGE-CALCULATIONS. Estimation types: Voyage Charter, Time Charter (hire-based), Offshore Day-Rate, Cargo Relet (**BR-EST-05:** cargo relet in scope? confirm). Each estimation has ≥1 scenario; one scenario may be Selected/Approved. All inputs are stored on the scenario (snapshot); results are recomputed server-side and stored with `calculation_version`.

### 5.8 Distance
`DistanceProviderInterface` with `ManualDistanceProvider` (internal table) first; external providers later. Cache in `port_distances` (from, to, via/waypoints hash, distance_nm, eca_distance_nm, provider, calculated_at).

### 5.9 Fixture
Created from an approved scenario/offer. Copies (snapshots) parties, vessel, cargo/service, ports, laycan, rate, currency, commissions, terms. Fixture number unique. One active fixture per accepted offer (unique constraint).

### 5.10 Contract
Types: Voyage Charter, Time Charter, Bareboat, Offshore Charter, Service Contract, Other. Statuses: Draft, Under Review, Approved, Active, Completed, Expired, Cancelled. Clauses, rate schedule (multiple rate types — day rate, standby rate, mob/demob lump sum, hourly), amendments (versioned, never overwritten), documents.

### 5.11 Operation / Voyage
Created by "Convert fixture to operation" (or estimation → operation where no fixture is required — BR-OP-01). Stores estimate snapshot (`voyage_snapshots` type `initial`), actuals, milestones, port calls, reports, documents. Statuses in 07 §Voyage lifecycle. Netpas-equivalent features: user-created milestone snapshots and comparison Initial vs Milestone vs Current vs Final.

### 5.12 Milestones
Configurable `milestone_types` (voyage and offshore sets seeded from the requirement list). Each event: type, port call (optional), planned/actual datetime with timezone, remarks, source (manual/captain report/AIS-suggested).

### 5.13 Offshore Activities
Activity types configurable (Supply Run, Crew Transfer, Anchor Handling, Towing, Standby, ROV Support, Diving Support, Survey, Construction Support, Platform Support, Field Support, Mobilization, Demobilization, Other). Fields per requirement §19. Hours split billable / non-billable / standby; rate looked up from contract rate schedule and snapshotted.

### 5.14 Captain / Vessel Reports
Noon, Arrival, Departure, Daily, Bunker, Offshore Activity. Manual entry first; optional import later. Per-fuel ROB and consumption lines. Reports can be "verified" by Operations; only verified reports feed actuals (BR-REP-01).

### 5.15 AIS (optional)
See 09-AIS-INTEGRATION.

### 5.16 Bunkers
Per voyage, per fuel type: opening ROB + received − consumed = closing ROB. Bunker purchases (stems) with supplier, port, price, currency, FX, BDN document. Estimated vs actual comparison with threshold flag.

### 5.17 Port Calls
Voyage/vessel/port/agent, ETA/ETB/ETD and ATA/ATB/ATD (stored UTC + port timezone), purpose, berth, status. Links to DA, documents, milestones, reports, expenses.

### 5.18 Port DA
Proforma (estimated) and Final (actual) DA with line items by category; variance; historical port costs query for estimation defaults.

### 5.19 Laytime / Demurrage / Despatch
Laytime calculation per port call (or reversible across ports — BR-LT-03), SOF events, exception periods, terms set; outputs allowed/used/difference/demurrage/despatch. All charter-party interpretation items flagged in 07.

### 5.20 Financials
Revenue and expense lines per voyage/operation with category, estimated/actual flags, currency and FX snapshot; P&L estimated vs actual vs variance.

### 5.21 Invoicing, Payments, Receivables
Invoices from voyage/contract/activity/revenue lines; numbering; tax; PDF via DomPDF (existing pattern). Payments with partial allocation, FX snapshot, bank reference. Balance = total − allocated payments.

### 5.22 Multi-currency
Currencies master (reuse CM pattern) + `exchange_rates` table (date, from, to, rate, source). Every monetary transaction stores `currency`, `fx_rate_to_base`, `base_amount`. Base currency configurable once (BR-FX-01).

### 5.23 Finalization, Balancing
Final stage compares Estimate vs Operation vs Final; finalized voyage is locked; reopen requires `voyages.reopen` and a reason, audited. Balancing reconciles estimated vs actual revenue/expenses, receivable invoiced/received, payable/paid.

### 5.24 CII / Environmental
Architecture with versioned formula configuration; scope applicability flagged (BR-CII-01). EU ETS/FuelEU deferred.

### 5.25 Documents
Polymorphic `documents` attached to any listed entity; private storage; signed, short-lived download URLs; type, number, issue/expiry dates.

### 5.26 Reports & Statistics
List in 03-MODULES §Reports. Filters: date range, vessel, customer, voyage, contract, status. Export CSV / Excel / PDF via the existing `ExportsReports` pattern.

### 5.27 Notifications
Reuse CM in-app `notifications` table + `NotificationService` + mail. Scheduled checks listed in 03 §Notifications; thresholds configurable.

### 5.28 Approvals
Configurable per area (Estimation, Fixture, Contract, Invoice, Expense, Finalization): enabled flag, approver permission, optional amount threshold. Draft → Submitted → Approved/Rejected.

### 5.29 Audit
Spatie Activitylog via `HasAuditLog` (reused), extended to record IP and user agent; explicit domain events for status transitions and financial edits.

## 6. Out of scope for v1 (explicit)

| Item | Reason |
|---|---|
| EU ETS & FuelEU calculator | Regulatory; offshore applicability to be confirmed; hook reserved. |
| Weather routing / forecasts | Requires provider. |
| Loadable quantity / draft calculator | Dry-bulk centric; confirm need (BR-EST-06). |
| Canal toll calculator | Manual canal cost entry in v1. |
| Outlook/Google contact import | Low priority. |
| Email sending of reports | Download only in v1. |
| Mobile app | Not requested. |
| Accounting/GL export | Confirm target system (BR-FIN-05). |

## 7. Non-functional requirements

- Availability: business hours critical; daily backups (DB + documents).
- Performance: list endpoints < 500 ms p95 at 100k rows; estimation recalculation < 300 ms.
- Security: see 02 §Security and 10.
- Auditability: every financial and status change attributable to a user with old/new values.
- Accessibility: keyboard navigation, labelled form controls, colour not sole status indicator (badges have text).
- Time: store UTC; display in user timezone, port-local times shown with port timezone.
