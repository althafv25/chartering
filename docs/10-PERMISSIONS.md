# 10 — Permissions

Status: DRAFT v0.1. Reuses the Crew Management model: Spatie Permission, a `App\Enums\Permission` string enum in `module.action` format, `RolesAndPermissionsSeeder`, a policy per model, `$this->authorize()` in every controller action, and the frontend `RoleGuard` / `hasPermission`. Improvement over CM: `Gate::before` grants everything to `super-admin`, and every route has a feature test that asserts the 403.

## 1. Actions

`view`, `create`, `update`, `delete`, `approve`, `finalize`, `export`, `print` (PDF), plus specific actions (`submit`, `issue`, `cancel`, `reopen`, `override`, `verify`, `allocate`).

## 2. Permission catalogue

| Module | Permissions |
|---|---|
| dashboard | dashboard.view, commercial.financials.view (financial KPIs) |
| users / roles | users.view/create/update/delete, roles.view/create/update/delete/assign |
| settings | settings.view/update, approval-settings.update, integrations.update |
| audit | audit-logs.view |
| masters | vessels.*, vessel-status.view/update, companies.*, ports.*, distances.view/override, masters.view/update (fuel/cargo/activity/milestone types, categories, tax codes), currencies.*, exchange-rates.view/create/update |
| chartering | enquiries.view/create/update/delete/export, estimations.view/create/update/delete/submit/approve/export/print, offers.view/create/update/send/accept |
| fixtures | fixtures.view/create/update/submit/approve/cancel/print |
| contracts | contracts.view/create/update/delete/submit/approve/activate/cancel/print/export, contracts.rates.view (commercial rates may be hidden) |
| operations | voyages.view/create/update/delete/complete/finalize/reopen/export, port-calls.*, milestones.*, captain-reports.view/create/update/verify, offshore-activities.view/create/update/verify |
| bunkers / DA / laytime | bunkers.view/manage, port-da.view/create/update/approve, laytime.view/create/update/agree |
| finance | revenues.view/create/update, expenses.view/create/update/approve, invoices.view/create/update/submit/approve/issue/cancel/print/export, payments.view/create/allocate/reverse, payables.view/create/approve, balancing.view |
| reports | reports.view, reports.export, statistics.view |
| documents | documents.view/upload/delete (the parent entity's view permission is also required) |
| ais / cii | ais.view, ais.manual-position, cii.view/calculate, cii.formulas.manage |

## 2a. Seeded in phase 3

`vessels.view/create/update/delete`, `vessel-status.view/update`, `companies.view/create/update/delete/bank-view`, `ports.view/create/update/delete` (also offshore locations), `distances.view/override`, `masters.view/update`, `currencies.view/update`, `exchange-rates.view/manage`.
All roles get read access to masters. Writes: Chartering/Commercial/Operations/Finance/Accounts — address book; Operations — vessel particulars update, status, ports, distances, reference data; Marine Operations — full vessel CRUD, status, ports, distances, reference data; Finance — currencies + FX; Accounts — FX; bank details visible to Management/Finance/Accounts. Source: `RolesAndPermissionsSeeder`.

## 2b. Seeded in phase 4

`chartering.enquiries.view/create/update/delete`, `chartering.estimations.view/create/update/submit/approve/reject/clone`, `chartering.offers.view/create/update/send/accept/reject`, `chartering.fixtures.view/create`, `operations.voyages.view/create`.
Chartering & Commercial: full chartering work (no approve/reject). Management: view + estimation approve/reject. Operations: view + `operations.voyages.create` (direct operation). Finance, Marine Ops, Read Only: view. Accounts: fixtures/voyages view. Super Admin: all via `Gate::before`, but self-approval is still blocked by the service rule (APR-02).

## 2e. Seeded in phase 7

`operations.offshore-projects.view/manage`, `operations.offshore-activities.view/create/update/verify`. Operations and Marine Operations have all of these; every other role with chartering read access, plus Accounts, has view. Revenue and rates also need `contracts.rates.view` (Marine Ops and Read Only don't have it).

## 2d. Seeded in phase 6

`operations.voyages.update/complete/finalize/reopen/cancel`, `operations.port-calls.manage`, `operations.milestones.manage`, `operations.off-hire.manage/agree`, `operations.captain-reports.view/create/update/verify`.
- Operations: all of the above.
- Marine Operations: all of the above except finalize/reopen.
- Every role with chartering read access, plus Accounts: captain-reports.view.
- Management, Chartering, Commercial, Finance, Read Only: view only.

## 2c. Seeded in phase 5

`chartering.fixtures.update/submit/approve/cancel`, `contracts.view/create/update/submit/approve/activate/cancel/amend/rates.view`.
Chartering: fixtures (no approve) + contracts create/update/submit/activate/cancel/amend. Commercial: same + `contracts.approve`. Management: fixture approve + contract view/approve. Operations, Finance, Accounts: contract view incl. rates. Marine Ops, Read Only: contract view without rates.

## 2f. Seeded in phase 8

`operations.bunkers.view/manage`, `operations.port-da.view/create/update/approve`, `operations.laytime.view/create/update/agree`.
- Operations: full CRUD for bunkers, DA and laytime
- Commercial: view + approve (DA) + agree (laytime)
- All chartering read roles (Management, Chartering, Commercial, Finance, Accounts, Marine Ops, Read Only): view
- Bunker stems: ordered → delivered (FX snapshot at delivery) → cancelled. ROB ledger derived from verified captain reports (BR-BK-01/02/03).
- Port DA: proforma/final with items, estimated vs actual variance, submit/approve/reject workflow (APR-02 segregation)
- Laytime: calculation with SOF events and exceptions, once-on-demurrage rule, submit/agree/dispute lifecycle

## 3. Role matrix (proposed — BR-ROLE-01 **[CONFIRM]**)

V = view, C = create/update, D = delete, A = approve, F = finalize/reopen, X = export/print, — = none

| Area | Super Admin | Management | Chartering | Operations | Commercial | Finance | Accounts | Marine Ops | Read Only |
|---|---|---|---|---|---|---|---|---|---|
| Dashboard (financial) | V | V | V | V (ops only) | V | V | V | V (ops only) | V (ops only) |
| Users/Roles/Settings | all | V | — | — | — | — | — | — | — |
| Audit logs | V | V | — | — | — | V | — | — | — |
| Vessels / status | all | V | V | VC | V | V | V | VCD | V |
| Address book | all | V | VC | VC | VC | VC | VC | V | V |
| Ports / masters | all | V | VC | VC | V | V | V | VC | V |
| Currencies / FX | all | V | V | V | V | VC | VC | V | V |
| Enquiries / offers | all | VX | VCDX | V | VCX | V | — | V | V |
| Estimations | all | VAX | VCDX | V | VCX | V | — | V | V |
| Fixtures | all | VAX | VCX | V | VCX | V | — | V | V |
| Contracts | all | VAX | VCX | V | VCAX | V | V | V | V |
| Voyages / port calls / milestones / off-hire | all | VX | V | VCFX | V | V | V | VCX | V |
| Captain reports / AIS | all | V | V | VC (verify) | V | V | V | VC (verify) | V |
| Offshore activities | all | V | V | VC (verify) | V | V | V | VC | V |
| Bunkers / Port DA | all | V | V | VC | V | VA (DA) | V | VC | V |
| Laytime | all | V | VC | VC | VCA (agree) | V | V | V | V |
| Revenue / expenses | all | V | V | VC (ops costs) | VC | VCA | VC | V | V |
| Invoices | all | VAX | V | V | VCX | VCAX (issue) | VCX | — | V |
| Payments / payables | all | V | — | — | V | VCA | VC (allocate) | — | V |
| Finalization | all | VF | V | C (complete) | V | F | V | V | V |
| Reports / statistics | all | VX | VX | VX | VX | VX | VX | VX | V |
| CII formulas | all | V | — | V | — | — | — | VC (verify) | V |

Notes:
- Read Only does not see commercial rates or financial KPIs unless granted `commercial.financials.view` **[CONFIRM]**.
- Segregation of duties: the same user cannot create and approve the same invoice or expense (APR-02).
- Netpas documents folder-level permissions. We use role permissions instead (single company). **BR-ROLE-02 [CONFIRM]** whether per-vessel or per-desk data scoping is needed. If it is, add a `user_vessel_scopes` table and enforce it in repositories.

## 4. Enforcement checklist per endpoint

1. Route is inside `auth:sanctum` and `throttle:api`.
2. FormRequest `authorize()` or controller `$this->authorize()`.
3. Policy checks both the permission and the record state where relevant (e.g. update denied when the record is finalized, which also returns a 409 business error).
4. Repository applies data scoping, if BR-ROLE-02 adopts it.
5. Feature test: an allowed role gets 2xx, a denied role gets 403.
