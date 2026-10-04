# 06 — API Specification

Status: DRAFT v0.1. REST/JSON, Sanctum Bearer auth, prefix `/api/v1` (CM parity).

## 1. Conventions

| Item | Rule |
|---|---|
| Base | `/api/v1` |
| Auth | `Authorization: Bearer <token>` on all routes except login/forgot/reset |
| Resource routes | `Route::apiResource()` — `GET /x`, `POST /x`, `GET /x/{id}`, `PUT /x/{id}`, `DELETE /x/{id}` |
| State transitions | `POST /x/{id}/{action}` (submit, approve, reject, cancel, activate, complete, finalize, reopen, issue, convert-to-…) |
| Nested | `/parents/{id}/children` for owned children |
| Documents | `/{morph}/{id}/documents` list/upload; `GET /documents/{id}/download` |
| Exports | `GET /reports/{slug}?format=csv|xlsx|pdf` |
| PDF | `GET /x/{id}/pdf` |
| Lists | `page`, `per_page` (default 15, max 100), `search`, `sort` (`field` / `-field`, allow-listed), `filter[field]=value`, date ranges `filter[from]`, `filter[to]`; `all=1` only on small masters (≤ 1000 rows) |
| Concurrency | Editable aggregates return `lock_version`; `PUT` must send it, else `409 stale_record` |
| Idempotency | `Idempotency-Key` header honoured on conversion, issue, payment create, finalize |
| Decimals | Sent/returned as **strings** (`"12345.67"`) |
| Dates | ISO 8601 UTC (`2026-10-01T12:00:00Z`); date-only `YYYY-MM-DD` |
| Throttle | `login` 5/min (email+IP); `api` 120/min per user; `exports` 10/min |

### Envelope (always — fixing CM inconsistency)

Success
```json
{ "success": true, "message": "Voyage created.", "data": { }, "meta": { "current_page": 1, "per_page": 15, "total": 120, "last_page": 8 }, "links": { } }
```
Error
```json
{ "success": false, "message": "Voyage is already finalized.", "error_code": "voyage_already_finalized", "errors": { }, "request_id": "01J..." }
```
HTTP codes: 200, 201, 401, 403, 404, 409 (business rule / stale / duplicate), 422 (validation), 429, 502 (integration), 500.

## 1a. Implemented (phase 2)

| Method | Path | Permission |
|---|---|---|
| POST | /auth/login | public, `throttle:login` (5/min per email+IP, 20/min per IP) |
| POST | /auth/forgot-password, /auth/reset-password | public, `throttle:password` |
| GET | /auth/me | authenticated — returns `roles`, `permissions`, `is_super_admin` |
| POST | /auth/logout, /auth/change-password | authenticated |
| PUT | /profile | authenticated (own record) |
| GET/POST/GET/PUT/DELETE | /users[/{id}] | users.view/create/update/delete |
| PATCH | /users/{id}/status | users.update |
| GET | /roles, /roles/{id}, /roles/permissions | roles.view |
| POST/PUT/DELETE | /roles[/{id}] | roles.create/update/delete (system roles locked) |
| GET/PUT | /settings | settings.view / settings.update — body `{settings: {key: value}}` |
| GET | /audit-logs, /audit-logs/filters | audit-logs.view |
| GET/POST | /notifications, /notifications/unread-count, /notifications/{id}/read, /notifications/read-all | own records only (404 for others) |
| GET | /document-types | authenticated |
| GET/POST | /{parentType}/{parentId}/documents | documents.view/upload + parent permission |
| GET/DELETE | /documents/{id}, /documents/{id}/download | documents.view/delete + parent permission |

### Phase 3 (implemented)

| Method | Path | Permission |
|---|---|---|
| GET | /reference | masters.view (catalogue of lists + field definitions) |
| GET | /reference/{type} | authenticated (active only unless masters.view; `?active=1`) |
| POST/PUT/DELETE | /reference/{type}[/{id}] | masters.update; delete → 409 `reference_in_use` when referenced |
| PUT | /reference/vessel-types/{id}/attributes | masters.update — `{attribute_schema:[{key,label,data_type,unit,options}]}` |
| GET/POST/PUT | /currencies[/{id}] | read: authenticated (active) / currencies.view (all); write: currencies.update; base currency cannot be deactivated (409) |
| GET/POST/PUT/DELETE | /exchange-rates[/{id}] | exchange-rates.view / exchange-rates.manage |
| GET | /exchange-rates/convert?from&to&date&amount | exchange-rates.view → `{rate, method, rate_date, source, converted_amount}`; 422 `exchange_rate_missing` |
| CRUD | /companies (+ /lookup?search&role, /duplicates) | companies.*; create/rename → 409 `possible_duplicate` (`errors.duplicates[]`), resend with `confirm_duplicate:true`; update requires `lock_version` |
| POST/PUT/DELETE | /companies/{id}/contacts[/{cid}], /bank-accounts[/{bid}], /aliases[/{aid}] | companies.update (+ companies.bank-view for bank) |
| CRUD | /ports (+ /lookup), /offshore-locations | ports.* |
| POST/DELETE | /ports/{id}/agents[/{companyId}] | ports.update; company must have role `agent` (422 `company_not_agent`) |
| GET | /route-points?search | distances.view → ports + offshore locations |
| POST | /distances/calculate | distances.view → `{distance_nm, eca_distance_nm, provider, is_estimate, reversed, notes, from, to}`; 409 `distance_unavailable` |
| GET/POST/DELETE | /distances[/{id}] | view / distances.override (manual entry, upsert) |
| CRUD | /vessels (+ /lookup) | vessels.*; update requires `lock_version`; IMO check-digit validated |
| GET/POST/PUT/DELETE | /vessels/{id}/consumption-profiles[/{pid}] | vessels.view / vessels.update; 409 `profile_period_overlap` |
| GET | /vessel-status/catalogue, /vessel-status/board, /vessels/{id}/status-history?track | vessel-status.view |
| POST | /vessels/{id}/status, /vessels/{id}/status/undo | vessel-status.update; 409 `status_backdated` / `status_unchanged`, 422 `status_in_future` |
| — | documents: parents `vessels`, `companies`, `ports`, `offshore-locations` | parent view/update permission |

### Phase 4 (implemented)

| Method | Path | Permission / notes |
|---|---|---|
| CRUD | /enquiries | chartering.enquiries.*; update needs `lock_version`; `ports[]` replaces itinerary |
| POST | /enquiries/{id}/status `{status, reason}` | update; 409 `invalid_status_transition`, 422 `reason_required` |
| POST/DELETE | /enquiries/{id}/vessels[/{vesselId}] | update; shortlist |
| GET | /enquiries/{id}/activity, /estimations/{id}/activity, /offers/{id}/activity | record view permission |
| GET/POST/GET/PUT | /estimations[/{id}] | estimations.*; POST creates scenario A from defaults |
| POST | /estimations/{id}/submit · approve `{comment}` · reject `{reason}` · reopen · clone | submit/approve/reject/update/clone; 403 `self_approval_not_allowed` |
| GET | /estimations/{id}/compare | key figures of all scenarios |
| POST | /estimations/{id}/scenarios `{name, clone_from_id?}` | update; 409 `estimation_read_only` |
| GET/PUT | /estimations/{id}/scenarios/{sid} `{lock_version, name, notes, inputs}` | PUT saves + calculates; 409 `stale_record` |
| POST | …/scenarios/{sid}/calculate · select · refresh-defaults `{vessel, consumption}` | 409 `scenario_not_calculated`, 422 `nothing_to_refresh` |
| POST | /estimations/{id}/convert-to-voyage `{reason}` | operations.voyages.create; 201 created / 200 existing; 409 `estimation_not_approved`, `fixture_exists` |
| GET/POST | /offers (POST creates offer + draft revision 1 from scenario/enquiry) | offers.* |
| POST/PUT | /offers/{id}/revisions[/{rid}] | update; 409 `draft_exists`, `revision_immutable`, `offer_closed` |
| POST | …/revisions/{rid}/send · receive · accept `{note}` · reject `{reason}` | send/update/accept/reject; 409 `invalid_direction`, `already_accepted` |
| GET | …/revisions/{rid}/diff/{other} | commercial field differences |
| POST | /offers/{id}/withdraw `{reason}` | update |
| POST | …/revisions/{rid}/convert-to-fixture | fixtures.create; 201 / 200 existing; 409 `revision_not_accepted`, `scenario_not_approved` |
| GET | /fixtures[/{id}], /voyages[/{id}] | fixtures.view / operations.voyages.view |

### Phase 7 (implemented)

| Method | Path | Permission / notes |
|---|---|---|
| GET/POST/PUT | /offshore-projects[/{id}] `?search&status&client_company_id&contract_id` | operations.offshore-projects.view/manage. With a contract, the client is the contract customer. 409 `project_in_use` (contract change after activities). Detail returns `summary` (hours; revenue only with contracts.rates.view) |
| GET | /offshore-activities `?vessel_id&voyage_id&contract_id&offshore_project_id&offshore_activity_type_id&status&from&to&search` | operations.offshore-activities.view; the envelope adds `summary {count, billable/non_billable/standby_hours, revenue[] (verified/invoiced, by currency) or null}` |
| POST/PUT/DELETE | /offshore-activities[/{id}] `{vessel_id, voyage_id?, contract_id?, offshore_project_id?, offshore_location_id?, offshore_activity_type_id, start_at, end_at, billable/non_billable/standby_hours?, description, remarks, fuel_used[]}` | create/update. Local times use the location timezone, otherwise the user's. Split defaults from `is_billable_default`. 422 `hours_exceed_duration`, `activity_overlap`; 409 `activity_read_only`, `voyage_read_only` |
| POST | /offshore-activities/{id}/submit · verify `{comment?}` · reject `{reason}` | update / verify. 403 `self_approval_not_allowed`; 409 `activity_not_priced` |
| — | Settings `offshore.day_rate_proration` (hourly/half_day/full_day), `offshore.standby_basis` (standby_rate/full_rate), `offshore.mob_demob_auto` | GET /settings now returns `options` (from `in:` rules) and `help` per setting |

The resource hides `currency`, `revenue_amount` and `rate_snapshot` (returned as null) without contracts.rates.view.

### Phase 6 (implemented)

Times: port-call fields accept port-local `YYYY-MM-DDTHH:mm` (converted with the port/location timezone) and return UTC ISO plus `*_local` and `timezone`; milestones use the port-call timezone or the user's; off-hire and voyage `at` use the user's timezone; an explicit offset/Z is always honoured. Captain report `reported_at` is ISO 8601 with offset.

| Method | Path | Permission / notes |
|---|---|---|
| GET | /voyages `?search&status&operation_type&vessel_id&conversion_type&open` · /voyages/{id} | operations.voyages.view; detail includes `port_calls`, `milestones`, `off_hires`, `snapshots`, `allowed_transitions`, `can_complete`, `is_open` |
| PUT | /voyages/{id} `{lock_version, remarks}` | operations.voyages.update |
| POST | /voyages/{id}/transition `{status, at?, note?}` | operations.voyages.update; 409 `invalid_status_transition`; 422 `invalid_datetime` (future / before last change) |
| POST | /voyages/{id}/complete `{at?}` · finalize · reopen `{reason}` · cancel `{reason}` | complete/finalize/reopen/cancel; 409 `voyage_not_completable` (errors.port_calls / errors.captain_reports), `off_hire_open` |
| POST | /voyages/{id}/snapshots `{name}` · GET /voyages/{id}/comparison · GET /voyages/{id}/activity | update / view; comparison = `{currency, columns[initial, milestone:N…, current, final], rows[{metric, values, variance}], financials_source}` |
| POST/PUT/DELETE | /voyages/{id}/port-calls[/{cid}] · POST …/{cid}/cancel `{reason}` | operations.port-calls.manage; 409 `voyage_read_only`, `port_call_in_progress`, `port_call_in_use`, `stale_record` |
| POST/PUT/DELETE | /voyages/{id}/milestones[/{mid}] · POST …/{mid}/verify | operations.milestones.manage; 422 type not applicable |
| POST/PUT/DELETE | /voyages/{id}/off-hire[/{eid}] · POST …/{eid}/agree `{comment?}` · dispute `{comment}` | manage / operations.off-hire.agree; 422 `off_hire_overlap`; 409 `off_hire_read_only`, `off_hire_open_ended` |
| GET/POST/PUT/DELETE | /captain-reports[/{id}] `?vessel_id&voyage_id&status&report_type&from&to` | operations.captain-reports.view/create/update; 422 `report_out_of_order`; 409 `report_read_only` |
| POST | /captain-reports/{id}/submit · verify `{comment?, apply_to_port_call?}` · reject `{reason}` | update / verify |

### Phase 5 (implemented)

| Method | Path | Permission / notes |
|---|---|---|
| PUT | /fixtures/{id} `{lock_version, cargo_description, terms, remarks, *_company_id}` | chartering.fixtures.update; draft only (409 `fixture_read_only`) |
| POST | /fixtures/{id}/submit · approve `{comment}` · reject `{reason}` · fail `{reason}` · cancel `{reason}` | submit / approve / cancel; 403 `self_approval_not_allowed`, 409 `fixture_in_use` |
| POST | /fixtures/{id}/convert-to-contract · convert-to-voyage | contracts.create / operations.voyages.create; 201 new / 200 existing; 409 `fixture_not_approved` |
| GET/POST/PUT | /contracts[/{id}] (`?status&contract_type&customer_company_id&vessel_id&expiring_within_days`) | contracts.view/create/update; 422 `vessel_required`; 409 `contract_read_only`, `fixture_terms_locked` |
| PUT | /contracts/{id}/rates · clauses `{lock_version, rates[] / clauses[]}` | contracts.update (+ rates.view); draft only |
| POST | /contracts/{id}/submit · approve · reject · activate · complete · cancel | contracts.submit/approve/activate/cancel; 409 `contract_incomplete`, `contract_ended`, `contract_in_use` |
| GET | /contracts/{id}/effective-rates?date= | contracts.rates.view → `{version_no, rates[]}` |
| POST/PUT | /contracts/{id}/amendments[/{aid}] | contracts.amend; 409 `contract_not_amendable`, `amendment_open`, `amendment_read_only`; 422 `empty_amendment`, `amendment_backdated` |
| POST | …/amendments/{aid}/submit · approve · reject · withdraw | amend / approve |
| — | `php artisan contracts:check-expiry` (scheduled daily 06:00) | expiry + notifications |

The pagination `meta` returned is `{current_page, per_page, total, last_page, from, to}`; `links` is not returned.

## 2. Endpoints (target)

### Auth & profile (CM parity)
```
POST /auth/login            POST /auth/logout          GET /auth/me
POST /auth/forgot-password  POST /auth/reset-password  POST /auth/change-password
GET|PUT /profile
```

### Administration
```
apiResource users            PATCH /users/{id}/status
apiResource roles            GET /roles/permissions
GET|PUT /settings
GET|PUT /approval-settings
GET /approval-requests?filter[status]=pending&mine=1
GET /audit-logs?filter[subject_type]=&filter[subject_id]=&filter[causer_id]=&filter[from]=&filter[to]=
GET /notifications  POST /notifications/{id}/read  POST /notifications/read-all  GET /notifications/unread-count
```

### Masters
```
apiResource currencies
apiResource exchange-rates          GET /exchange-rates/lookup?from=AED&to=USD&date=2026-10-01
apiResource vessel-types            (+ /vessel-types/{id}/attributes)
apiResource vessels
  GET|POST  /vessels/{id}/consumption-profiles
  PUT|DELETE /vessels/{id}/consumption-profiles/{pid}
  GET  /vessels/{id}/status-history
  POST /vessels/{id}/status            {track, status, effective_from, location, reason, remarks}
  GET  /vessels/{id}/performance?from=&to=
  GET  /vessels/{id}/crew              (proxy to Crew Management, cached)
apiResource companies               ?filter[role]=charterer
  apiResource companies.contacts
  apiResource companies.bank-accounts
  POST /companies/{id}/aliases
  POST /companies/import            (CSV)
apiResource ports                   (+ ports.agents)
apiResource offshore-locations
apiResource fuel-types | cargo-types | offshore-activity-types | milestone-types
apiResource revenue-categories | expense-categories | da-cost-categories | tax-codes | document-types
POST /distances/calculate           {from_port_id, to_port_id, waypoints[], options} → {distance_nm, eca_distance_nm, provider, calculated_at}
GET  /distances?filter[from]=&filter[to]=
POST /distances/override            (manual value, permission distances.override)
```

### Chartering
```
apiResource enquiries
  POST /enquiries/{id}/vessels            shortlist
  POST /enquiries/{id}/mark-lost          {reason}
  POST /enquiries/{id}/cancel
apiResource estimations
  POST /estimations/{id}/submit | approve | reject | archive
  apiResource estimations.scenarios
  POST /estimations/{id}/scenarios/{sid}/clone
  POST /estimations/{id}/scenarios/{sid}/calculate     inputs → results (persists; idempotent by inputs_hash)
  POST /estimations/{id}/scenarios/preview              stateless calculation, no persist
  POST /estimations/{id}/scenarios/{sid}/select
  GET  /estimations/{id}/compare?scenarios=1,2,3
  GET  /estimations/{id}/scenarios/{sid}/breakeven
  GET  /estimations/{id}/pdf
apiResource offers
  POST /offers/{id}/revisions             new revision (draft)
  POST /offers/{id}/revisions/{rid}/send | record-received | accept | reject
  GET  /offers/{id}/revisions/{a}/diff/{b}
  POST /offers/{id}/withdraw
```

### Fixtures & contracts
```
POST /offers/{id}/revisions/{rid}/convert-to-fixture     (Idempotency-Key)
apiResource fixtures  (no store — created by conversion; update only in draft)
  POST /fixtures/{id}/submit | approve | reject | cancel | mark-failed
  POST /fixtures/{id}/convert-to-contract
  POST /fixtures/{id}/convert-to-voyage                   (Idempotency-Key)
  GET  /fixtures/{id}/recap.pdf
apiResource contracts
  POST /contracts/{id}/submit-review | approve | reject | activate | complete | cancel
  apiResource contracts.rates | contracts.clauses
  apiResource contracts.amendments      POST /contracts/{id}/amendments/{aid}/approve
  GET  /contracts/{id}/pdf
```

### Operations
Design sketch. Voyage endpoints as built are listed under "Phase 6 (implemented)": `/transition` replaces `/status`, snapshots come with the voyage detail, and the comparison always returns all columns. Not built yet: flat `GET /port-calls`, `/financials`, `/balancing`, offshore endpoints.
```
POST /estimations/{id}/scenarios/{sid}/convert-to-voyage   (when BR-OP-01 allows)
apiResource voyages
  POST /voyages/{id}/transition        {status, at, note}  (transition validated)
  POST /voyages/{id}/snapshots {name}  (milestone snapshot)
  GET  /voyages/{id}/comparison
  POST /voyages/{id}/complete | finalize | reopen {reason}
  GET  /voyages/{id}/financials          (P&L estimated/actual/variance)
  GET  /voyages/{id}/balancing
apiResource voyages.port-calls        (also flat GET /port-calls with filters)
apiResource voyages.milestones
apiResource voyages.off-hire
apiResource captain-reports           POST /captain-reports/{id}/submit | verify | reject
apiResource offshore-projects
apiResource offshore-activities       POST /offshore-activities/{id}/submit | verify
```

### Bunkers, DA, Laytime
```
apiResource bunker-stems
GET  /voyages/{id}/bunkers/ledger      POST /voyages/{id}/bunkers/ledger/rebuild
GET  /voyages/{id}/bunkers/reconciliation
apiResource port-das                   POST /port-das/{id}/submit | approve | settle
POST /port-das/{id}/create-final       (from proforma)
GET  /ports/{id}/da-history?category=&from=&to=
apiResource laytime-calculations
  apiResource laytime-calculations.sof-events | laytime-calculations.exceptions
  POST /laytime-calculations/{id}/calculate
  POST /laytime-calculations/{id}/submit | agree | dispute
  GET  /laytime-calculations/{id}/pdf
```

### Finance
```
apiResource voyage-revenues | voyage-expenses
apiResource invoices
  POST /invoices/from-sources          {voyage_revenue_ids[] | offshore_activity_ids[] | contract_id + period}
  POST /invoices/{id}/submit | approve | issue (Idempotency-Key) | cancel {reason}
  POST /invoices/{id}/credit-note
  GET  /invoices/{id}/pdf
apiResource payments                   POST /payments/{id}/allocate {allocations[]}  POST /payments/{id}/reverse
apiResource payables                   POST /payables/{id}/approve
GET /receivables/aging?as_of=
GET /balancing?filter[voyage_id]=&filter[company_id]=&from=&to=
```

### Reports, statistics, dashboard
```
GET /dashboard?from=&to=
GET /reports                           catalogue (slug, title, filters, formats, permission)
GET /reports/{slug}?filters…&format=json|csv|xlsx|pdf
GET /statistics/{metric}?group_by=vessel|customer|month|category&from=&to=&currency=base
```

### AIS & CII
```
GET  /ais/fleet                        latest positions (clustered client-side)
GET  /ais/vessels/{id}/track?from=&to=&simplify=0.001   (max range 31 days per call)
GET  /ais/vessels/{id}/positions?from=&to=&page=
POST /ais/positions/manual             (manual/verified position)
GET  /ais/status                       provider health
apiResource cii-formula-sets           POST /cii-formula-sets/{id}/verify
GET  /vessels/{id}/cii?year=           POST /vessels/{id}/cii/{year}/calculate
POST /estimations/{id}/scenarios/{sid}/cii-simulate
```

### Documents
```
GET  /{morph}/{id}/documents           morph ∈ vessels, enquiries, fixtures, contracts, voyages, port-calls, port-das, offshore-activities, invoices, companies
POST /{morph}/{id}/documents           multipart: file, document_type_id, title, number, issue_date, expiry_date
GET  /documents/{id}                   metadata
GET  /documents/{id}/download          policy-checked stream / 5-min S3 signed URL
DELETE /documents/{id}                 soft delete
GET  /documents?filter[expiring_within_days]=30
```

## 3. Example — scenario calculate

`POST /api/v1/estimations/12/scenarios/31/calculate`
```json
{
  "lock_version": 4,
  "speed_laden_kn": "11.50",
  "speed_ballast_kn": "12.00",
  "sea_margin_pct": "5.0000",
  "legs": [ { "sequence": 1, "from_port_id": 101, "to_port_id": 220, "condition": "ballast", "distance_nm": "1240.00" } ],
  "port_calls": [ { "sequence": 1, "port_id": 220, "working_days": "2.500000", "idle_days": "0.500000", "port_cost_amount": "18500.00", "port_cost_currency": "USD" } ],
  "bunkers": [ { "fuel_type_id": 3, "price_per_mt": "640.0000", "currency": "USD" } ],
  "revenue_items": [ { "revenue_category_id": 1, "basis": "per_mt", "quantity": "45000.000", "rate": "18.2500", "currency": "USD", "address_commission_pct": "3.7500", "brokerage_pct": "1.2500" } ],
  "cost_items": []
}
```
Response `data`: scenario with `results` (all fields of `scenario_results`, strings) and `trace` (formula steps) — see 08.
