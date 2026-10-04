# 05 — Database Dictionary

Status: DRAFT v0.1. Column-level design for review **before** any migration is written.

## Implemented so far (as built)

Phase 2: `users`, Spatie tables, `personal_access_tokens`, `activity_log`, `settings`, `user_notifications`, `document_types`, `documents`, `document_sequences`.
Phase 3 (migrations `2026_10_01_1500xx`): `currencies`, `exchange_rates`, `vessel_types` (with `attribute_schema` JSON), `fuel_types`, `cargo_types`, `offshore_activity_types`, `milestone_types`, `expense_categories`, `revenue_categories`, `da_cost_categories`, `companies` (+`normalized_name`, `lock_version`), `company_roles`, `company_aliases`, `contacts`, `company_bank_accounts`, `ports` (+`normalized_name`), `port_agents`, `offshore_locations`, `port_distances` (points typed `port|location`, `route_key`), `vessels` (+`code`, `custom_attributes`, denormalised `commercial_status`/`operational_status`, `lock_version`), `vessel_name_histories`, `vessel_consumption_profiles`, `vessel_consumption_rates` (speed 0 for non-sea modes), `vessel_status_history` (`track`).
Differences from the design below: no `vessel_type_attributes`/`vessel_attribute_values` tables (replaced by JSON schema + values, D-030); `exchange_rates` has no `entered_by` (uses `created_by`/`updated_by`); `port_aliases` deferred.

Phase 4 (migration `2026_10_01_160000_create_chartering_tables`): `revenue_categories.is_commissionable`; `enquiries`, `enquiry_ports`, `enquiry_vessels`, `estimations`, `estimation_scenarios` (`inputs` JSON snapshot, `inputs_hash`, `calc_status`, `calc_issues`, `calculation_version`, generated `selected_key` unique), `scenario_results` (all result figures + `breakdown`/`trace` JSON), `offers`, `offer_revisions` (generated `accepted_key` unique), `fixtures` (`recap_snapshot` JSON), `voyages` (`conversion_type`, `direct_reason`, generated `direct_key` unique), `voyage_snapshots` (generated `single_key`). Differences from the design: scenario legs/calls/bunkers/items are JSON inside `inputs` instead of child tables (D-034).

Phase 5 (migration `2026_10_02_100000_create_contract_tables`): fixtures + `submitted_by/at`, `decided_by/at`, `decision_comment`, `remarks`, `lock_version`, `updated_by`; `contracts` (unique nullable `fixture_id`, `current_version`, lifecycle stamps, `lock_version`), `contract_versions` (immutable header snapshot per version), `contract_rates` / `contract_clauses` (with `version_no`), `contract_amendments` (`proposal`, `changes`, `resulting_version`); `voyages.contract_id`. Difference from design: no `contract_parties` table (customer FK on contract).

Phase 6 (migration `2026_10_02_120000_create_operations_tables`): voyages + `commenced_at`, `completed_at`, `finalized_at/by`, `cancelled_at`, `status_reason`, `reopened_count`, `status_changed_at`; `port_calls` (port **or** offshore location, purpose, ETA/ETB/ETD/ATA/ATB/ATD in UTC, status, `lock_version`); `voyage_milestones` (+`captain_report_id`); `off_hire_events` (`hours` DECIMAL(10,4) calculated, `fuel_consumed` JSON, `decided_by/at`, `lock_version`); `captain_reports` (+`submitted_by/at`, `decision_comment`, `lock_version`, soft deletes) and `captain_report_fuel_lines`. Phase 7 (migration `2026_10_02_140000_create_offshore_activity_tables`): `offshore_projects` (code unique, client, optional contract and location, field, dates, status `planned|active|completed|cancelled`, `lock_version`, soft deletes); `offshore_activities` (`activity_number` OA-YYYY-NNNNN, vessel, optional voyage, contract, project and location, client, type, `start_at`/`end_at` UTC, hours split DECIMAL(10,4), `currency`, `contract_version_no`, `rate_snapshot` JSON lines, `revenue_amount` DECIMAL(18,2), `calculation_basis`, `warnings`, `fuel_used` JSON, status, submit/verify stamps, `lock_version`, soft deletes). Differences from design: no `fx_rate` or `expense_amount` (these arrive with phases 9–10); rate details live in `rate_snapshot` rather than in single `rate_type`/`rate` columns.

Differences from design: no `deducted_hire` column (BR-OP-02 open), no `timezone_offset` on captain reports (time arrives as ISO with offset and is stored in UTC).

Phase 8 (migration `2026_10_02_160000_create_bunker_tables`, `_170000_create_port_da_tables`, `_180000_create_laytime_tables`): `bunker_stems` (stem_number BS-2026-NNNNN, vessel, voyage, port_call, fuel_type, ordered_on/mt, delivered_at/mt, price/currency, fx_rate/method, total_amount/base_amount, bdn_number, status ordered|delivered|invoiced|cancelled, lock_version, soft deletes); `port_das` (da_number DA-2026-NNNNN, port_call, voyage, port, agent, da_type proforma|final, proforma_da_id, currency/fx_rate/method, total_amount/base_amount, status draft|submitted|approved|settled, submitted/approved stamps, lock_version, soft deletes) and `port_da_items` (port_da_id, da_cost_category_id, description, estimated_amount/actual_amount/variance_amount DECIMAL(18,2), sequence); `laytime_calculations` (port_call, voyage, contract, calculation_type load|discharge|reversible, fixed_hours or cargo_quantity/rate_per_day/rate_unit, terms_code/terms_definition JSON, nor_tendered/accepted/notice_time/commenced/completed timestamps UTC, demurrage/despatch rates, currency, once_on_demurrage_rule always_on_demurrage|exceptions_apply, allowed/used/difference hours DECIMAL(12,4), amounts DECIMAL(18,2), calculation_version/trace JSON, calculated_at, status draft|submitted|agreed|disputed, agreed stamps, lock_version, soft deletes), `laytime_sof_events` (event_at, event_code, description, source manual|port_call|captain_report), `laytime_exceptions` (from_at/to_at, exception_type weather|holiday|weekend|shifting|breakdown_owner|…, pct_counted DECIMAL(7,4) default 0). ROB ledger has no table — computed on demand from verified captain report fuel lines (REP-01).

## 0. Conventions

| Item | Rule |
|---|---|
| PK | `id` BIGINT UNSIGNED auto-increment (CM parity) |
| FK | `<entity>_id`, `foreignId()->constrained()`; `restrictOnDelete` for business links, `cascadeOnDelete` for owned children, `nullOnDelete` for audit user FKs |
| Audit columns | `created_by`, `updated_by` (FK users, nullable), `created_at`, `updated_at` |
| Soft delete | `deleted_at` on masters and business headers (SD). Financial/posted records are **never** deleted — cancelled instead |
| Status | `VARCHAR(30)` + backing PHP enum + index (CM uses strings; we add enums in code) |
| Optimistic lock | `lock_version` INT UNSIGNED default 0 on editable aggregates (LV) |
| Business number | `VARCHAR(30)` unique, generated by `document_sequences` |
| Money | `DECIMAL(18,2)` amounts; `DECIMAL(18,4)` unit prices/rates |
| FX rate | `DECIMAL(18,8)` |
| Fuel quantity (MT) | `DECIMAL(14,3)` |
| Consumption (MT/day) | `DECIMAL(10,3)` |
| Distance (NM) | `DECIMAL(10,2)` |
| Speed (kn) | `DECIMAL(5,2)` |
| Days | `DECIMAL(12,6)` (stored), displayed 2–4 dp |
| Hours | `DECIMAL(10,4)` |
| Percentages | `DECIMAL(7,4)` (e.g. 2.5000 = 2.5 %) |
| Lat/Long | `DECIMAL(10,7)` |
| Datetimes | `TIMESTAMP`/`DATETIME` in UTC; port-local display via `ports.timezone` |
| Currency | `CHAR(3)` ISO 4217 + FK to `currencies.code` |
| JSON | only for non-queried payloads (raw AIS payload, calculation trace) |

Money rows always carry the triple: `currency`, `fx_rate` (to base), `base_amount`.

> CM uses `decimal(15,2)` for money. Offshore widens to `(18,2)` to hold large offshore contract values in low-value currencies; formatting conventions otherwise identical (D-005).

---

## 1. Core

### users (CM parity)
id, name, first_name, last_name, username (unique, nullable), email (unique), password, phone, avatar_path, status (`active|inactive`), timezone, locale, last_login_at, last_login_ip, password_changed_at, remember_token, timestamps, deleted_at.

### roles, permissions, model_has_roles, model_has_permissions, role_has_permissions — Spatie standard.
### personal_access_tokens — Sanctum standard (+ `expires_at`).
### activity_log — Spatie standard; `properties` JSON holds `old`, `attributes`, `ip`, `user_agent`, `request_id`.

### notifications (CM parity + extensions)
user_id FK, type (`info|success|warning|error`), category (e.g. `invoice_overdue`), title, message, action_url, action_text, entity_type, entity_id, dedupe_key (unique with user_id — avoids duplicate daily alerts), is_read, read_at, timestamps.

### settings
key (unique), value (TEXT), type (`string|int|decimal|bool|json`), group, updated_by. Examples: `base_currency`, `notification.contract_expiry_days`, `bunker.discrepancy_threshold_pct`, `ais.enabled`.

### document_types
code (unique), name, applicable_to (JSON list of morph aliases), requires_expiry (bool), status.

### documents (SD)
documentable_type, documentable_id (index), document_type_id FK, title, document_number, issue_date, expiry_date (index), disk (`private|s3`), path (unique), original_filename, mime_type, size_bytes, sha256, version_no, supersedes_document_id (FK self), remarks, uploaded_by, timestamps, deleted_at.

### approval_settings
area (`estimation|fixture|contract|invoice|expense|finalization`, unique), enabled (bool), approver_permission, threshold_amount (DECIMAL 18,2, base ccy, nullable), self_approval_allowed (bool, default false).

### approval_requests
approvable_type, approvable_id, area, status (`pending|approved|rejected|withdrawn`), submitted_by, submitted_at, decided_by, decided_at, decision_comment, snapshot_hash (hash of the approved payload), timestamps. Index (approvable_type, approvable_id, status).

### document_sequences
sequence_key (unique, e.g. `invoice:2026`), prefix, next_number, padding. Used under `lockForUpdate`.

### idempotency_keys
key, user_id, route, request_hash, response_status, response_body (JSON), expires_at. Unique (user_id, key).

---

## 2. Masters

### currencies (CM parity)
code CHAR(3) unique, name, symbol, decimals TINYINT (default 2), status.

### exchange_rates
rate_date DATE, base_currency CHAR(3), quote_currency CHAR(3), rate DECIMAL(18,8) (1 base = rate quote), source (`manual|provider:<name>`), entered_by. Unique (rate_date, base_currency, quote_currency, source).

### vessel_types
code (unique), name, category (`offshore|tanker|bulk|general|other`), parent_id (subtype tree), status.

### vessel_type_attributes
vessel_type_id FK, key, label, data_type (`decimal|integer|string|bool|enum`), unit, options JSON, sort_order. Unique (vessel_type_id, key).

### vessels (SD, LV)
| Column | Type | Notes |
|---|---|---|
| name | VARCHAR(150) | index |
| former_names | — | via `vessel_name_history` (name, from, to) |
| imo_number | CHAR(7) | unique nullable, check-digit validated |
| mmsi | CHAR(9) | unique nullable |
| call_sign, official_number | VARCHAR(20) | |
| vessel_type_id | FK | |
| flag_country | CHAR(2) | ISO 3166 |
| port_of_registry | VARCHAR(100) | |
| year_built | SMALLINT | |
| builder | VARCHAR(150) | nullable |
| class_society | VARCHAR(100) | |
| owner_company_id, manager_company_id, commercial_manager_company_id, technical_manager_company_id | FK companies nullable | |
| ownership_type | VARCHAR(20) | `owned|managed|chartered_in|third_party` (BR-VSL-02) |
| loa_m, lbp_m, beam_m, depth_m, summer_draft_m, air_draft_m | DECIMAL(8,3) | |
| dwt_mt | DECIMAL(12,3) | |
| gt, nt | DECIMAL(12,2) | |
| main_engine_desc, aux_engine_desc | VARCHAR(255) | |
| main_engine_power_kw, aux_engine_power_kw | DECIMAL(10,2) | |
| propulsion_type | VARCHAR(50) | |
| service_speed_kn, max_speed_kn, eco_speed_kn | DECIMAL(5,2) | |
| deck_area_m2 | DECIMAL(10,2) | offshore |
| bollard_pull_t | DECIMAL(8,2) | offshore |
| dp_class | VARCHAR(10) | `DP0..DP3` |
| crane_swl_t | DECIMAL(8,2) | |
| accommodation_pob | SMALLINT | |
| cargo_capacity_notes | TEXT | liquid mud, brine, fuel, water, dry bulk m³ via attributes |
| low_rob_threshold_mt | — | per fuel in `vessel_fuel_thresholds` |
| crew_management_vessel_id | BIGINT nullable | integration mapping |
| status | VARCHAR(20) | `active|inactive|sold|scrapped` (record status, not operational) |
| remarks | TEXT | |

### vessel_attribute_values
vessel_id FK, vessel_type_attribute_id FK, value_decimal, value_string, value_bool. Unique (vessel_id, attribute_id).

### vessel_consumption_profiles
vessel_id FK, name (e.g. "Laden 12 kn"), effective_from DATE, effective_to DATE nullable, source (`design|charter_party|observed`), remarks. Non-overlap per vessel+name enforced in service.

### vessel_consumption_rates
profile_id FK cascade, mode (`sea_laden|sea_ballast|port_working|port_idle|standby|dp_operation|manoeuvring`), speed_kn (nullable for port modes), fuel_type_id FK, consumption_mt_per_day DECIMAL(10,3). Unique (profile_id, mode, speed_kn, fuel_type_id).

### vessel_status_history
vessel_id FK, track (`commercial|operational`), status (enum list in 07), effective_from DATETIME, effective_to DATETIME nullable, location_text, port_id nullable, latitude, longitude, voyage_id nullable, reason, remarks, changed_by, timestamps. Index (vessel_id, track, effective_from).

### companies (SD)
code (unique, `CMP-00001`), legal_name, trading_name, country CHAR(2), city, address_line1/2, postal_code, email, phone, website, tax_number, vat_registered (bool), default_currency, payment_terms_days SMALLINT, credit_limit DECIMAL(18,2), status (`active|inactive|blocked`), remarks. Index (legal_name).

### company_roles
company_id FK cascade, role (`owner|charterer|broker|customer|agent|supplier|shipyard|surveyor|port_authority|insurer|other`). Unique (company_id, role).

### company_aliases
company_id FK, alias, valid_from, valid_to, reason (`former_name|abbreviation|other`).

### contacts (SD)
company_id FK, first_name, last_name, job_title, department, email, phone, mobile, is_primary, remarks.

### company_bank_accounts
company_id FK, bank_name, account_name, account_number, iban, swift_bic, currency, is_primary.

### ports (SD)
name, unlocode CHAR(5) unique nullable, country CHAR(2), region_id FK nullable, latitude, longitude, timezone VARCHAR(64), max_draft_m DECIMAL(6,2), max_loa_m, restrictions TEXT, notes TEXT, is_offshore_location (bool), status.

### port_aliases — port_id, alias.
### port_agents — port_id FK, company_id FK (role agent), is_default, remarks. Unique (port_id, company_id).

### port_distances
from_port_id FK, to_port_id FK, route_hash CHAR(64) (hash of waypoints/options, '' for direct), waypoints JSON, distance_nm, eca_distance_nm, canal_codes JSON, provider VARCHAR(50), provider_reference, calculated_at, is_manual_override. Unique (from_port_id, to_port_id, route_hash, provider).

### offshore_locations
name, field_name, block, latitude, longitude, nearest_port_id, water_depth_m, remarks.

### fuel_types
code (unique), name, category (`HFO|VLSFO|ULSFO|LSMGO|MGO|MDO|LNG|METHANOL|OTHER`), is_eca_compliant (bool), status. Emission factors live in `cii_formula_sets` (versioned), not here.

### cargo_types — code, name, stowage_factor nullable, remarks.
### offshore_activity_types — code, name, default_rate_type, is_billable_default, sort_order.
### milestone_types — code, name, applies_to (`voyage|offshore|both`), sequence, is_laytime_relevant, is_system (bool).
### revenue_categories / expense_categories — code, name, group (`freight|hire|offshore_service|demurrage|other` / `bunker|port|agency|canal|brokerage|commission|operational|mobilization|demobilization|supplier|other`).
### da_cost_categories — code, name (pilotage, towage, berth, agency, customs, immigration, launch, garbage, fresh_water, security, other), expense_category_id FK.
### tax_codes — code, name, rate_pct DECIMAL(7,4), country, effective_from, effective_to.

---

## 3. Chartering

### enquiries (SD, LV)
enquiry_number (unique `ENQ-2026-0001`), received_at, source (`direct|broker|tender`), business_type (`voyage_charter|time_charter|offshore_charter|cargo_relet|service`), charterer_company_id, broker_company_id nullable, cargo_type_id nullable, cargo_description, quantity DECIMAL(14,3), quantity_unit, quantity_tolerance_pct, offshore_location_id nullable, laycan_from DATE, laycan_to DATE, period_days DECIMAL(10,2) nullable, rate_idea DECIMAL(18,4) nullable, rate_basis, currency, commission_terms TEXT, terms TEXT, remarks, status (`open|evaluating|offered|fixed|lost|cancelled`), lost_reason, assigned_to (user), timestamps.

### enquiry_ports — enquiry_id FK cascade, sequence, port_id, purpose (`load|discharge|bunker|supply_base|other`), notes.
### enquiry_vessels — enquiry_id, vessel_id, shortlist_status (`candidate|rejected|selected`), notes.

### estimations (SD, LV)
estimation_number (unique), enquiry_id nullable, estimation_type (`voyage_charter|time_charter|offshore_day_rate|cargo_relet`), title, vessel_id, currency (main), status (`draft|submitted|approved|rejected|archived`), selected_scenario_id nullable, approved_by, approved_at, remarks.

### estimation_scenarios (LV)
estimation_id FK cascade, code (`A`,`B`…), name, is_selected, selected_key (generated, unique), status (`draft|final`), vessel_snapshot JSON (name, IMO, DWT, speeds at creation), speed_laden_kn, speed_ballast_kn, consumption_profile_id (trace), sea_margin_pct, weather_factor_pct (BR-EST-02), commencement_at DATETIME, base_currency, fx_rate_main_to_base, notes, calculated_at, calculation_version, cloned_from_id.

### scenario_legs
scenario_id FK cascade, sequence, from_port_id / from_location_id, to_port_id / to_location_id, condition (`laden|ballast`), distance_nm, eca_distance_nm, distance_provider, distance_calculated_at, speed_kn (override), sea_margin_pct (override), canal_code nullable.

### scenario_port_calls
scenario_id, sequence, port_id or offshore_location_id, purpose, working_days DECIMAL(12,6), idle_days, waiting_days, port_cost_amount, port_cost_currency, port_cost_fx_rate, agency_cost_amount, agency_currency, agency_fx_rate, cost_source (`manual|historical_da|proforma`).

### scenario_bunkers
scenario_id, fuel_type_id, price_per_mt DECIMAL(18,4), currency, fx_rate, price_source, price_date, sea_consumption_overrides JSON (per mode), opening_rob_mt nullable, closing_rob_mt nullable.

### scenario_cost_items
scenario_id, expense_category_id, description, basis (`lump_sum|per_day|per_mt|pct_of_revenue`), quantity, rate, currency, fx_rate, amount_main (stored result).

### scenario_revenue_items
scenario_id, revenue_category_id, description, basis (`lump_sum|per_mt|per_day|per_hour`), quantity, rate DECIMAL(18,4), currency, fx_rate, address_commission_pct, brokerage_pct, other_commission_pct.

### scenario_results (1:1)
scenario_id unique, calculation_version, inputs_hash, sea_distance_nm, eca_distance_nm, sea_days, port_days, total_days, fuel_sea_mt JSON per fuel, fuel_port_mt JSON per fuel, fuel_total_mt, fuel_cost, port_costs, agency_costs, canal_costs, other_costs, gross_revenue, total_commission, net_revenue, total_voyage_cost, voyage_result (profit), profit_margin_pct, profit_per_day, tce_per_day, breakeven_rate, breakeven_basis, trace JSON (formula trace for audit), calculated_at.

### offers (SD)
offer_number (unique), enquiry_id FK, vessel_id, estimation_scenario_id nullable, status (`open|accepted|declined|withdrawn|expired`).

### offer_revisions (immutable after `sent/received`)
offer_id FK, revision_no, direction (`outbound|inbound`), status (`draft|sent|received|superseded|accepted|rejected`), sent_at/received_at, valid_until, rate DECIMAL(18,4), rate_basis, currency, laycan_from, laycan_to, quantity, ports JSON, commissions JSON (address/brokerage/other, payee company ids), terms TEXT, estimation_scenario_id (pricing), remarks, created_by. Unique (offer_id, revision_no).

---

## 4. Fixtures & Contracts

### fixtures (SD)
fixture_number unique, offer_revision_id unique, estimation_scenario_id, enquiry_id, vessel_id, charterer_company_id, owner_company_id, broker_company_id, fixture_date, business_type, cargo/service description, quantity, laycan_from/to, rate, rate_basis, currency, terms TEXT, recap_snapshot JSON (full copy incl. vessel particulars), status (`draft|submitted|approved|fixed|failed|cancelled`), approved_by/at.

### fixture_ports — fixture_id, sequence, port_id/location_id, purpose.
### fixture_commissions — fixture_id, type (`address|brokerage|other`), payee_company_id, pct.

### contracts (SD, LV)
contract_number unique, contract_type (`voyage_charter|time_charter|bareboat|offshore_charter|service|other`), fixture_id nullable unique, customer_company_id, vessel_id nullable (service contracts), start_date, end_date, extension_options TEXT, currency, payment_terms_days, payment_terms_text, commissions JSON snapshot, terms TEXT, status (`draft|under_review|approved|active|completed|expired|cancelled`), current_version SMALLINT, approved_by/at, activated_at.

### contract_rates
contract_id FK, version_no, rate_type (`day_rate|hire_per_day|freight_per_mt|lump_sum|standby_day_rate|hourly|mobilization_fee|demobilization_fee|other`), activity_type_id nullable, amount DECIMAL(18,4), currency, unit, effective_from, effective_to, notes.

### contract_clauses — contract_id, version_no, clause_ref, title, body TEXT, sequence.

### contract_amendments
contract_id, amendment_no, effective_date, summary, changes JSON (before/after of headers, rates, clauses), status (`draft|approved`), approved_by/at. Unique (contract_id, amendment_no).

---

## 5. Operations

### voyages (SD, LV)
voyage_number unique (`<VESSELCODE>-<YY>-<NNN>`), vessel_id, contract_id nullable, fixture_id unique nullable, estimation_scenario_id nullable, operation_type (`voyage|time_charter|offshore`), charterer_company_id, currency, base_currency, commenced_at, completed_at, status (see 07), finalized_at, finalized_by, reopened_count, remarks.

### voyage_snapshots
voyage_id FK, type (`initial|milestone|final`), name, single_key (generated unique for initial/final), payload JSON (inputs + results: days, distance, fuel per type, costs by category, revenue by category, profit, TCE), calculation_version, created_by, created_at. Immutable.

### port_calls (SD, LV)
voyage_id, sequence, port_id or offshore_location_id, agent_company_id, purpose (`load|discharge|bunker|supply|crew_change|repair|offshore_ops|other`), berth, eta, etb, etd, ata, atb, atd (UTC), status (`planned|nominated|arrived|berthed|sailed|cancelled`), remarks.

### voyage_milestones
voyage_id, port_call_id nullable, milestone_type_id, planned_at, actual_at, source (`manual|captain_report|ais_suggested`), verified_by, verified_at, remarks.

### off_hire_events
voyage_id, from_at, to_at, reason_code, description, hours DECIMAL(10,4) (calculated), deducted_hire (calc), fuel_consumed JSON, status (`draft|agreed|disputed`).

### captain_reports (SD)
voyage_id nullable, vessel_id, report_type (`noon|arrival|departure|daily|bunker|offshore_activity`), reported_at (UTC), timezone_offset, latitude, longitude, port_call_id nullable, speed_kn, course_deg, distance_since_last_nm, distance_to_go_nm, wind_force_bft, wind_direction, sea_state, weather_text, main_engine_hours, aux_engine_hours, activity_text, delay_hours, delay_reason, remarks, source (`manual|import|email`), status (`draft|submitted|verified|rejected`), verified_by/at.

### captain_report_fuel_lines
captain_report_id FK cascade, fuel_type_id, rob_mt, consumed_mt, received_mt. Unique (report, fuel).

---

## 6. Offshore

### offshore_projects (SD) — code unique, name, client_company_id, contract_id nullable, field_name, start_date, end_date, status.

### offshore_activities (SD, LV)
voyage_id nullable, vessel_id, contract_id, offshore_project_id nullable, client_company_id, offshore_location_id nullable, activity_type_id, start_at, end_at, description, billable_hours, non_billable_hours, standby_hours (all DECIMAL 10,4), rate_type, rate DECIMAL(18,4) (snapshot from contract_rates), contract_rate_id (trace), currency, fx_rate, revenue_amount (calc), fuel_used_mt JSON per fuel, expense_amount (calc from linked expenses), status (`draft|submitted|verified|invoiced`), remarks.

---

## 7. Bunkers, DA, Laytime (Phase 8 — as built)

### bunker_stems (SD, LV)
stem_number (BS-2026-NNNNN) unique, vessel_id, voyage_id nullable, port_call_id nullable, port_id nullable, supplier_company_id nullable, fuel_type_id, ordered_on DATE, ordered_mt DECIMAL(12,3), delivered_at TIMESTAMP nullable, delivered_mt DECIMAL(12,3) nullable, price_per_mt DECIMAL(18,4), currency, fx_rate/fx_method (snapshotted at delivery), total_amount/base_amount DECIMAL(18,2) nullable, bdn_number VARCHAR(60), invoice_reference VARCHAR(60), status VARCHAR(20) default `ordered` (`ordered|delivered|invoiced|cancelled`), remarks, lock_version, audit columns, soft deletes. Index (vessel_id, delivered_at), (voyage_id, fuel_type_id).

**ROB Ledger** — no table, computed on-demand by `RobLedgerService::forVoyage()` from verified `captain_report_fuel_lines` (REP-01). Returns per fuel: rows with period_start/end, opening/closing/received/consumed/stems_delivered MT, ROB discontinuity flag (tolerance 0.001 MT, BK-02), estimated consumption from initial snapshot fuel per day, variance MT/%, flagged when |variance%| > `bunker.discrepancy_threshold_pct` setting (default 5%, BK-03), received mismatch flag when stems ≠ received. Closing = opening + received − consumed (BK-01).

### port_das (SD, LV)
da_number (DA-2026-NNNNN) unique, port_call_id, voyage_id, port_id, agent_company_id nullable, da_type ENUM(`proforma`,`final`) default `proforma`, proforma_da_id nullable (FK self, links final → proforma), currency, fx_rate/fx_method DECIMAL(18,8)/VARCHAR(20), total_amount/base_amount DECIMAL(18,2) default 0 (server-calculated sum of items), status VARCHAR(20) default `draft` (`draft|submitted|approved|settled`), submitted_at/submitted_by, approved_at/approved_by, remarks, lock_version, audit columns, soft deletes. Index (voyage_id, da_type).

### port_da_items
port_da_id FK cascade, da_cost_category_id, description VARCHAR(255), estimated_amount/actual_amount/variance_amount DECIMAL(18,2) nullable (variance = actual − estimated, server-calculated), remarks, sequence SMALLINT default 0. Index (port_da_id, sequence).

### laytime_calculations (SD, LV)
port_call_id, voyage_id, contract_id nullable, calculation_type ENUM(`load`,`discharge`,`reversible`), fixed_hours DECIMAL(12,4) nullable (L1 option 1), cargo_quantity DECIMAL(14,3) / rate_per_day DECIMAL(12,4) / rate_unit VARCHAR(20) nullable (L1 option 2: allowed = qty/rate×24), terms_code VARCHAR(30) (`SHINC|SHEX|SSHEX|FHEX|custom`), terms_definition JSON nullable (BR-LT-* rules, [CONFIRM]), nor_tendered_at/nor_accepted_at TIMESTAMP nullable, notice_time_hours DECIMAL(10,4), laytime_commenced_at/laytime_completed_at TIMESTAMP nullable (L2), demurrage_rate_per_day/despatch_rate_per_day DECIMAL(18,4) nullable (L5), currency CHAR(3), once_on_demurrage_rule VARCHAR(30) default `always_on_demurrage` (`always_on_demurrage|exceptions_apply` — L4), allowed_hours/used_hours/difference_hours DECIMAL(12,4) nullable (server-calculated, L3/L5), demurrage_amount/despatch_amount DECIMAL(18,2) nullable (L6), calculation_version VARCHAR(20), trace JSON nullable (full audit trail), calculated_at TIMESTAMP, status VARCHAR(20) default `draft` (`draft|submitted|agreed|disputed`), submitted_at/submitted_by, agreed_at/agreed_by, remarks, lock_version, audit columns, soft deletes. Index (voyage_id, calculation_type).

### laytime_sof_events
laytime_calculation_id FK cascade, event_at TIMESTAMP (UTC), event_code VARCHAR(50) (NOR_TENDERED|NOR_ACCEPTED|COMMENCED|HOSES_CONNECTED|OPERATIONS_COMMENCED|OPERATIONS_COMPLETED|HOSES_DISCONNECTED|ALL_FAST|COMPLETED), description VARCHAR(255), source VARCHAR(30) default `manual` (`manual|port_call|captain_report`). Index (laytime_calculation_id, event_at).

### laytime_exceptions
laytime_calculation_id FK cascade, from_at/to_at TIMESTAMP (UTC), exception_type VARCHAR(50) (`weather|holiday|weekend|shifting|breakdown_owner|breakdown_charterer|strike|waiting_berth|other`), pct_counted DECIMAL(7,4) default 0 (0=excluded, 50=half time, 100=full — overlapping exceptions use lowest pct), remarks. Index (laytime_calculation_id, from_at).

---

## 8. Finance

### voyage_revenues (SD)
voyage_id nullable, contract_id nullable, offshore_activity_id nullable, laytime_calculation_id nullable, revenue_category_id, description, is_estimate (bool), quantity, rate, currency, fx_rate, amount, base_amount, commission_pct_total, commission_amount, status (`draft|confirmed|invoiced|cancelled`), service_period_from/to.

### voyage_expenses (SD)
voyage_id nullable, contract_id nullable, expense_category_id, source_type/source_id (port_da, bunker_stem, payable, offshore_activity, manual), description, is_estimate, quantity, rate, currency, fx_rate, amount, base_amount, supplier_company_id, status (`draft|confirmed|approved|paid|cancelled`), incurred_at.

### invoices (SD, LV)
invoice_number unique (assigned at **issue**, drafts use `DRAFT-<id>`), invoice_type (`freight|hire|offshore_service|demurrage|other|credit_note`), customer_company_id, billing_snapshot JSON (name, address, tax no.), contract_id, voyage_id, issue_date, due_date, currency, fx_rate, subtotal, tax_amount, total, base_total, amount_paid (denormalized, recalculated in service), balance, status (`draft|submitted|approved|issued|partially_paid|paid|overdue|cancelled`), cancelled_reason, credit_note_for_id nullable, pdf_document_id, approved_by/at, issued_by/at.

### invoice_lines — invoice_id FK cascade, sequence, voyage_revenue_id unique nullable, description, quantity, unit, rate DECIMAL(18,4), amount, tax_code_id, tax_rate_pct, tax_amount, line_total.

### payments (SD)
payment_number unique, direction (`received|paid`), company_id, payment_date, amount, currency, fx_rate, base_amount, bank_account_ref, bank_reference, method, remarks, status (`recorded|reversed`), unallocated_amount (calc).

### payment_allocations — payment_id, invoice_id or payable_id, allocated_amount (payment ccy), invoice_ccy_amount, fx_difference_base, created_by. Unique (payment_id, invoice_id), unique (payment_id, payable_id).

### payables (SD) — supplier invoices received: payable_number, supplier_company_id, supplier_invoice_ref, voyage_id nullable, issue_date, due_date, currency, fx_rate, subtotal, tax, total, amount_paid, balance, status (`draft|approved|partially_paid|paid|cancelled`). Unique (supplier_company_id, supplier_invoice_ref).

---

## 9. AIS & CII

### ais_providers_config — provider (unique), enabled, polling_minutes, last_success_at, last_error, (credentials in `.env` only).
### ais_positions
vessel_id, imo, mmsi, latitude, longitude, sog_kn DECIMAL(5,2), cog_deg DECIMAL(5,2), heading_deg SMALLINT, nav_status VARCHAR(40), destination VARCHAR(100), eta_reported DATETIME, draught_m DECIMAL(5,2), observed_at (AIS timestamp), received_at, provider, raw JSON nullable. Index (vessel_id, observed_at); unique (vessel_id, observed_at, provider).
### vessel_latest_positions — vessel_id unique, ais_position_id, observed_at, is_stale (bool). Cheap fleet-map query.

### cii_formula_sets — code, name, regulation_reference, valid_from_year, valid_to_year, parameters JSON (capacity definition, reference line a/c per ship type, reduction factors per year, rating boundary vectors d1..d4, emission factors Cf per fuel), verified_by, verified_at, status (`draft|verified|retired`).
### cii_vessel_years — vessel_id, reporting_year, formula_set_id, fuel_consumption JSON per fuel, co2_t, distance_nm, capacity, transport_work, attained_cii, required_cii, rating (`A..E`), source (`calculated|manual`), remarks. Unique (vessel_id, reporting_year).
