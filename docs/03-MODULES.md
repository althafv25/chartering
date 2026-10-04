# 03 — Modules

Status: DRAFT v0.1. One section per module, following the coding-discipline checklist (purpose, CM reuse, tables, rules, API, permissions, screens, calculations, tests, integration). Table details: 05; API: 06; permissions: 10.

---

## M00 Core / Common infrastructure (Phase 2)
- Purpose: auth, users, roles, settings, audit, notifications, documents, approvals, numbering, error handling.
- CM reuse: AuthService, UserService, RoleController, Settings, `HasAuditLog`, `ApiResponse`, NotificationService, FileStorageInterface, ExportsReports, Sidebar/Header/Login/UI kit.
- Tables: users, roles/permissions (Spatie), personal_access_tokens, activity_log, notifications, settings, documents, document_types, approval_settings, approval_requests, document_sequences, idempotency_keys.
- Screens: Login, Forgot/Reset password, Profile, Users, Roles & Permissions, Settings, Approval Settings, Audit Logs, Notifications.
- Tests: login throttle; inactive user rejected; permission 403 per role; private document download denied without parent permission; sequence generation under concurrency.

## M01 Vessels & Vessel Status (Phase 3)
- Purpose: vessel particulars, consumption profiles, offshore capabilities, status history.
- CM reuse: Vessel CRUD pattern (`VesselsPage`, `VesselDetailPage` tab layout).
- Tables: vessel_types, vessels, vessel_type_attributes, vessel_attribute_values, vessel_consumption_profiles, vessel_consumption_rates, vessel_status_history.
- Rules: IMO unique (7 digits + check digit validation); MMSI 9 digits; consumption profile versions don't overlap; status history non-overlapping per track.
- Screens: Vessel list (filters type/status/owner), Vessel detail tabs (Particulars, Dimensions & Tonnage, Machinery & Speeds, Consumption, Offshore Capabilities, Status History, Voyages, Documents, Performance, CII).
- Calculations: none (inputs to estimation). IMO check digit.
- Tests: IMO validation; status change closes previous period; overlap rejection.
- Integration: CM vessel link by IMO.

## M02 Address Book (Phase 3)
- Tables: companies, company_roles, company_aliases, contacts, company_bank_accounts.
- Rules: company has ≥1 role; duplicate detection on normalized name + country (warning, not block); aliases preserved when renamed (auto alias on legal name change).
- Screens: Address Book list with role filter tabs (All/Customers/Charterers/Brokers/Agents/Suppliers/Owners/Other), Company detail (Contacts, Roles, Bank, Documents, Activity: enquiries, contracts, invoices).

## M03 Ports & Distances (Phase 3)
- Tables: ports, port_aliases, port_agents, port_distances, canals (optional), regions.
- Rules: UN/LOCODE unique when present; distance cache key = (from, to, route_hash, provider).
- Screens: Port list/detail (Agents, DA history, Calls, Distances).
- Services: DistanceService → provider chain (Manual → External when configured).

## M04 Reference masters (Phase 3)
- fuel_types (code, name, category HFO/VLSFO/LSMGO/MGO/LNG/Methanol…, CO2 factor version link), fuel_blends (BR-FUEL-01), cargo_types, offshore_activity_types, milestone_types, expense_categories, revenue_categories, da_cost_categories, currencies, exchange_rates, tax_codes.

## M05 Chartering: Enquiries & Offers (Phase 4)
- Tables: enquiries, enquiry_ports, offers, offer_revisions.
- Rules: offer revisions immutable once `sent`/`received`; new revision number = max+1 within offer; an enquiry may have multiple offers (different vessels).
- Screens: Enquiry list (pipeline status), Enquiry detail (Requirement, Candidate vessels, Estimations, Offers timeline, Documents), Offer revision drawer (diff vs previous).

## M06 Estimation & Scenarios (Phase 4) — critical
- Tables: estimations, estimation_scenarios, scenario_legs, scenario_port_calls, scenario_bunkers, scenario_cost_items, scenario_revenue_items, scenario_results.
- Rules: see 07 §Estimation; inputs snapshot copies of master defaults; recalculation is server-side; one Selected scenario per estimation; approved scenario is read-only (clone to edit).
- Screens: Estimation list; Estimation workspace (left: inputs sections — Vessel & Speed/Consumption, Itinerary & Distances, Port Days, Bunkers & Prices, Costs, Revenue & Commissions; right: sticky Results panel); Scenario comparison table; Break-even/TCE panel.
- Calculations: 08 §E1–E14.
- Tests: golden fixtures per estimation type (voyage, TC, offshore day-rate).

## M07 Fixtures (Phase 5)
- Tables: fixtures, fixture_parties, fixture_ports, fixture_commissions, fixture_terms.
- Rules: created from an accepted offer revision + approved scenario; unique per offer revision; snapshot; recap PDF.
- Screens: Fixture list, detail, "Create Contract" and "Create Voyage" actions.

## M08 Contracts (Phase 5)
- Tables: contracts, contract_rates, contract_clauses, contract_amendments, contract_parties.
- Rules: amendments create new version, original preserved; Active requires Approved; expiry job → Expired.
- Screens: list, detail (Terms, Rates, Clauses, Amendments, Voyages/Activities, Invoices, Documents).

## M09 Voyages / Operations (Phase 6)
- Tables: voyages, voyage_snapshots, voyage_milestones, off_hire_events, port_calls.
- Rules: conversion idempotent (unique `fixture_id`); `initial` snapshot written at conversion; user can create named milestone snapshots; status transitions per 07.
- Screens: Voyage list, Voyage workspace tabs (Overview, Itinerary/Port Calls, Milestones, Reports, Bunkers, DA, Laytime, Activities, Financials, Invoices, Documents, Comparison).
- Built in phase 6: Overview, Itinerary, Milestones, Captain reports, Off-hire, Estimate vs actual, Documents, Activity. Bunkers, DA, Laytime, Activities, Financials and Invoices tabs are added in later phases. Rules: 07 OP-10…17.

## M10 Captain Reports (Phase 6)
- Tables: captain_reports, captain_report_fuel_lines.
- Rules: report time monotonic per voyage; verified flag; ROB lines per fuel.
- Built in phase 6: Captain Reports page plus a voyage tab; draft → submitted → verified/rejected; optional copy of arrival/departure time to the port call ATA/ATD.

## M11 Offshore Activities (Phase 7)
- Tables: offshore_projects, offshore_locations, offshore_activities.
- Calculations: hours split, revenue = Σ(hours × rate by rate type), activity profit.
- Built in phase 7: Offshore Projects page, Offshore Activities page (filters, totals, calculation breakdown), and an activities tab on offshore voyages. Rules: 07 OA-10…14. Activity profit and utilization are not built yet.

## M12 Bunkers (Phase 8)
- Tables: bunker_stems (purchases), bunker_rob_ledger (per voyage × fuel × period).
- Calculations: 08 §B1–B3.

## M13 Port DA (Phase 8)
- Tables: port_das, port_da_items.
- Calculations: variance per category and total.

## M14 Laytime (Phase 8)
- Tables: laytime_calculations, laytime_terms, laytime_sof_events, laytime_exceptions.
- Calculations: 08 §L1–L6.

## M15 Finance: Revenue/Expense, Invoices, Payments (Phase 9)
- Tables: voyage_revenues, voyage_expenses, invoices, invoice_lines, payments, payment_allocations, payables (supplier invoices received), tax_codes.
- Rules: 07 §Finance.

## M16 Finalization & Balancing (Phase 10)
- Tables: voyage_snapshots (type `final`), finalization fields on voyages.

## M17 Reports & Statistics (Phase 11)
Reports: Fleet Status, Voyage Status, Voyage Summary, Voyage P&L, Estimated vs Actual, Vessel Profitability, Vessel Utilization, Revenue, Expenses, Outstanding Invoices (aging), Port Cost, Bunker Consumption, Vessel Performance, Offshore Activities, Contract Status, Chartering Activity, Laytime, Demurrage, CII, AIS Position History.
Statistics: Revenue/Profit by Vessel, Customer, Month; Cost by Category; Avg Voyage Profit; Avg TCE; Utilization; Billable/Standby hours; Port Costs; Bunker Consumption.
Exports: CSV (streamed), Excel (Maatwebsite), PDF (DomPDF) — reuse `ExportsReports`.

## M18 AIS (Phase 12) — see 09.

## M19 CII (Phase 13)
- Tables: cii_formula_sets (versioned), cii_reference_lines, cii_vessel_years.
- Rule: no regulatory constants seeded until verified against the official IMO resolutions (BR-CII-01..04).

## M20 Notifications (cross-cutting)
Scheduled jobs (daily 06:00 unless noted), thresholds in settings:
| Notification | Trigger |
|---|---|
| Contract expiry | end_date in 60/30/7 days |
| Vessel certificate/document expiry | expiry_date in 90/60/30/7 days (CM thresholds) |
| Invoice due / overdue | due in 7 days / past due (daily) → also flips status to Overdue |
| Upcoming port call | ETA within 48 h (hourly) |
| Voyage milestone | on milestone recorded (event) |
| Low ROB | closing ROB < vessel threshold per fuel (on report) |
| Pending approval | on submit (event) to approvers |
| AIS data stale | no position > N h for vessel on active voyage (hourly) |
| Laytime threshold / potential demurrage | used ≥ 80 % / > 100 % of allowed (on SOF update) |

## M21 Documents (cross-cutting) — polymorphic `documents` (see 05).
