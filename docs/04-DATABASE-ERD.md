# 04 — Database ERD

Status: DRAFT v0.1. Logical model; column detail in 05. MySQL 8 / InnoDB / utf8mb4. All tables have `id` bigint PK, `created_at`, `updated_at`; business tables add `created_by`, `updated_by` (FK users, nullOnDelete) and `deleted_at` where marked (SD).

## 1. Master data

```mermaid
erDiagram
    vessel_types ||--o{ vessels : classifies
    vessel_types ||--o{ vessel_type_attributes : defines
    vessels ||--o{ vessel_attribute_values : has
    vessel_type_attributes ||--o{ vessel_attribute_values : typed_by
    vessels ||--o{ vessel_consumption_profiles : versions
    vessel_consumption_profiles ||--o{ vessel_consumption_rates : rates
    fuel_types ||--o{ vessel_consumption_rates : fuel
    vessels ||--o{ vessel_status_history : status
    companies ||--o{ vessels : "owner/manager (FKs)"
    companies ||--o{ company_roles : plays
    companies ||--o{ company_aliases : known_as
    companies ||--o{ contacts : employs
    companies ||--o{ company_bank_accounts : banks
    ports ||--o{ port_agents : served_by
    companies ||--o{ port_agents : agent
    ports ||--o{ port_distances : from
    ports ||--o{ port_distances : to
    currencies ||--o{ exchange_rates : quoted
```

## 2. Commercial chain

```mermaid
erDiagram
    enquiries ||--o{ enquiry_ports : itinerary
    enquiries ||--o{ estimations : evaluated_by
    estimations ||--|{ estimation_scenarios : contains
    estimation_scenarios ||--o{ scenario_legs : legs
    estimation_scenarios ||--o{ scenario_port_calls : calls
    estimation_scenarios ||--o{ scenario_bunkers : prices
    estimation_scenarios ||--o{ scenario_cost_items : costs
    estimation_scenarios ||--o{ scenario_revenue_items : revenue
    estimation_scenarios ||--o| scenario_results : results
    enquiries ||--o{ offers : answered_by
    offers ||--|{ offer_revisions : history
    estimation_scenarios ||--o{ offer_revisions : priced_by
    offer_revisions ||--o| fixtures : fixed_as
    fixtures ||--o{ fixture_ports : ports
    fixtures ||--o{ fixture_commissions : commissions
    fixtures ||--o| contracts : formalised_by
    contracts ||--o{ contract_rates : rates
    contracts ||--o{ contract_clauses : clauses
    contracts ||--o{ contract_amendments : amended_by
```

## 3. Operations

```mermaid
erDiagram
    fixtures ||--o| voyages : converted_to
    contracts ||--o{ voyages : governs
    estimation_scenarios ||--o{ voyages : "source (direct conversion)"
    vessels ||--o{ voyages : performs
    voyages ||--|{ voyage_snapshots : initial_milestone_final
    voyages ||--o{ port_calls : calls
    ports ||--o{ port_calls : at
    voyages ||--o{ voyage_milestones : events
    milestone_types ||--o{ voyage_milestones : typed
    port_calls ||--o{ voyage_milestones : at_call
    voyages ||--o{ off_hire_events : off_hire
    voyages ||--o{ captain_reports : reports
    captain_reports ||--o{ captain_report_fuel_lines : fuel
    voyages ||--o{ offshore_activities : activities
    contracts ||--o{ offshore_activities : billed_under
    offshore_projects ||--o{ offshore_activities : project
    offshore_locations ||--o{ offshore_activities : location
    voyages ||--o{ bunker_rob_ledger : rob
    voyages ||--o{ bunker_stems : stems
    port_calls ||--o{ bunker_stems : at_call
    port_calls ||--o{ port_das : da
    port_das ||--o{ port_da_items : items
    port_calls ||--o{ laytime_calculations : laytime
    laytime_calculations ||--o{ laytime_sof_events : sof
    laytime_calculations ||--o{ laytime_exceptions : exceptions
```

## 4. Finance

```mermaid
erDiagram
    voyages ||--o{ voyage_revenues : earns
    voyages ||--o{ voyage_expenses : incurs
    contracts ||--o{ voyage_revenues : "contract-level revenue"
    offshore_activities ||--o{ voyage_revenues : source
    port_das ||--o{ voyage_expenses : source
    bunker_stems ||--o{ voyage_expenses : source
    companies ||--o{ invoices : billed
    invoices ||--|{ invoice_lines : lines
    voyage_revenues ||--o| invoice_lines : invoiced_as
    companies ||--o{ payments : pays
    payments ||--o{ payment_allocations : allocates
    invoices ||--o{ payment_allocations : settled_by
    companies ||--o{ payables : bills_us
    payables ||--o{ voyage_expenses : covers
```

## 5. Cross-cutting

```mermaid
erDiagram
    users ||--o{ activity_log : causes
    users ||--o{ notifications : receives
    documents }o--|| document_types : typed
    approval_requests }o--|| users : requested_by
    vessels ||--o{ ais_positions : observed
    vessels ||--o{ cii_vessel_years : rated
    cii_formula_sets ||--o{ cii_vessel_years : computed_with
```

`documents` and `approval_requests` are polymorphic (`documentable_type/id`, `approvable_type/id`) using a morph map (short aliases, not class names).

## 6. Reference vs snapshot (Snapshot Principle)

| Data | Referenced (FK) | Snapshotted (copied value) at |
|---|---|---|
| Vessel identity | `vessel_id` everywhere | Name/IMO/DWT copied into fixture & contract recap and invoice header |
| Speed / consumption | profile id for traceability | Scenario stores speed and per-fuel consumption values; voyage `initial` snapshot |
| Fuel prices | — | Scenario bunkers; bunker stems (actual price) |
| Port costs | port_id | Scenario port costs; DA actuals |
| Distances | cache row id + provider | Scenario legs store `distance_nm`, `eca_distance_nm`, provider, calculated_at |
| Rates & commissions | — | Offer revision → fixture → contract rates (each its own copy); invoice lines |
| Exchange rates | rate row id (nullable) | Every monetary row stores `fx_rate`, `base_amount` |
| Counterparties | `company_id` | Invoice stores billing name/address/tax no. at issue |
| Commercial terms | — | Fixture terms, contract clauses (versioned via amendments) |

## 7. Uniqueness and integrity constraints (key ones)

- `vessels.imo_number` unique (nullable for non-IMO craft), `vessels.mmsi` unique nullable.
- `ports.unlocode` unique nullable.
- `estimation_scenarios (estimation_id, code)` unique; at most one `is_selected=1` per estimation (enforced in service with lock; MySQL lacks partial index — use generated column `selected_key = IF(is_selected, estimation_id, NULL)` unique).
- `offer_revisions (offer_id, revision_no)` unique.
- `fixtures.offer_revision_id` unique; `fixtures.fixture_number` unique.
- `contracts.contract_number` unique; `contract_amendments (contract_id, amendment_no)` unique.
- `voyages.voyage_number` unique; `voyages.fixture_id` unique nullable.
- `voyage_snapshots (voyage_id, type)` unique for `initial` and `final` (generated-column technique).
- `invoices.invoice_number` unique; `invoice_lines.voyage_revenue_id` unique nullable (no double-invoicing of a revenue line — BR-INV-02).
- `payment_allocations (payment_id, invoice_id)` unique.
- `exchange_rates (rate_date, base_currency, quote_currency, source)` unique.
- `ais_positions (vessel_id, observed_at, provider)` unique.
- `bunker_rob_ledger (voyage_id, fuel_type_id, period_start)` unique.
