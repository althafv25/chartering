# 07 — Business Rules

Status: DRAFT v0.1. Rules marked **[CONFIRM]** are **BUSINESS RULE REQUIRES CONFIRMATION** and will not be implemented as hard logic until confirmed. They will either be configurable or blocked behind an explicit user choice.

## 1. General

- G-01 Single company. No tenancy columns.
- G-02 Data flows forward by **conversion**. Each conversion copies (snapshots) the commercial values valid at that point. Changing master data never changes existing transactions.
- G-03 Posted financial records (issued invoices, recorded payments, finalized voyages) are never hard-deleted. They are cancelled, reversed or credited.
- G-04 Every status change goes through a service method that checks the allowed source statuses (`assertStatus`), runs in a transaction with a row lock when it affects other records, and writes an activity-log entry.
- G-05 Calculations run on the server, are versioned (`calculation_version`) and store a trace.
- G-06 All times are stored in UTC. Laytime and milestone inputs are entered in port-local time and converted using `ports.timezone`.

## 2. Snapshot principle

| Stage | Snapshotted when | What |
|---|---|---|
| Scenario | Created / recalculated | vessel particulars used, speeds, consumptions, fuel prices, port costs, distances (+provider), FX |
| Offer revision | Sent / received | rate, terms, commissions, laycan, ports |
| Fixture | Created | full recap (parties, vessel particulars, terms, rates, commissions) |
| Contract | Approved / each amendment | headers, rate schedule, clauses (versioned) |
| Voyage | Conversion → `initial`; user milestones; finalization → `final` | estimate and actual figures |
| Invoice | Issued | billing party details, lines, tax rates, FX |
| Payment | Recorded | FX rate |

## 3. Vessel status

- VS-01 Status history periods must not overlap within a track. Recording a new status closes the open period at the new `effective_from`.
- **BR-VS-01 [CONFIRM]** Use two parallel tracks? Proposed: Commercial (Available/Open, On Hire, Off Hire, Under Charter, Laid Up) and Operational (At Sea, At Port, Mobilizing, Demobilizing, Offshore Operation, Standby, Maintenance, Dry Dock).
- **BR-VS-02 [CONFIRM]** Should voyage milestones update operational status automatically (e.g. ATD → At Sea)? Proposed: suggest the change; the user confirms.
- **BR-VSL-02 [CONFIRM]** Which ownership types are in scope: owned, managed, chartered-in, third-party for estimation only?

## 4. Chartering, offers, estimation

- CH-01 An enquiry may have many estimations and offers. Marking it `fixed` requires a fixture.
- CH-02 Offer revisions are immutable once sent or received. A correction is a new revision.
- CH-03 Only one revision per offer can be `accepted`. Accepting it supersedes the earlier open revisions.
- EST-01 An estimation needs at least one scenario. At most one scenario is Selected.
- EST-02 An approved estimation and its selected scenario are read-only. Changes require a clone (new scenario/estimation).
- EST-03 Rejected and unselected scenarios are kept (status `final` or `draft`) and never deleted after submission.
- EST-04 Master defaults (consumption, port costs from DA history, last fuel price) are copied into the scenario at creation. Recalculating does not pull fresh master data unless the user runs "Refresh defaults", which is audited.
- **BR-EST-01 — CONFIRMED 2026-10-01:** sea margin applies to **sea time** (not distance), as a percentage; a per-leg override is allowed.
- **BR-EST-02 [CONFIRM]** Weather factor separate from sea margin?
- **BR-EST-03 — CONFIRMED 2026-10-01:** commission is **configurable per revenue category and per item**. Freight, hire and offshore day-rate are commissionable by default; demurrage, standby, ballast bonus, mob/demob and other categories default to non-commissionable and can be switched on. Commission = Σ item amount × (address + brokerage + other)% for commissionable items only.
- **BR-EST-04 — CONFIRMED 2026-10-01:** TCE uses **total elapsed operational days** (sea + port + offshore days). TCE = (net revenue − owner-account voyage costs) / total days; operational (OPEX) costs are excluded from TCE but included in profit. Per type: see 08 §S.
- **BR-EST-05 — CONFIRMED 2026-10-01:** Cargo Relet is in scope for v1 with a dedicated strategy (08 §R).
- **BR-EST-06 [CONFIRM]** Is a loadable-quantity/draft calculator needed (dry-bulk centric)?
- **BR-EST-07 — CONFIRMED 2026-10-01:** port fuel = working days × working + idle days × idle + waiting days × idle consumption, per fuel. Offshore DP days use dedicated DP consumption; standby days use standby consumption.
- **BR-EST-08 [CONFIRM]** Bunker cost method: consumption × scenario price (proposed), or replacement/FIFO from ROB value.
- **BR-EST-09 [CONFIRM]** Approval threshold and approvers for estimations.

### Phase 4 implemented rules (as built)

- EST-10 Estimation lifecycle: draft → submitted (selected + fully calculated scenario required) → approved | rejected; rejected → draft (reopen). Approve/reject cannot be done by the submitter unless `approvals.self_approval_allowed` (APR-02; applies to Super Admin too).
- EST-11 Only draft estimations are editable. Approved/submitted/rejected estimations and all their scenarios are read-only; changes require **Clone** (new draft, `cloned_from_id` on estimation and each scenario, selection preserved).
- EST-12 Scenario codes A, B, … Z, AA … are sequential per estimation; scenarios are never deleted.
- EST-13 Exactly zero or one selected scenario per estimation (service + DB generated-column unique key). Only a scenario whose stored result matches its current `inputs_hash` can be selected.
- EST-14 Scenario inputs are a snapshot. Saving re-snapshots master attributes **only for new or changed items** (fuel code/ECA flag, category code/group, FX when left empty); recalculation never reads master data. "Refresh defaults" (vessel particulars and/or consumption profile) is an explicit, audited action.
- EST-15 Calculation is idempotent: unchanged `inputs_hash` + same `calculation_version` → no recomputation. Incomplete inputs produce `calc_status = incomplete` with a list of issues — never a zero result.
- OFR-1 Revision numbers are sequential per offer; a revision is editable only while `draft`; once sent (outbound) or received (inbound) it is immutable (service check + model guard). One open draft per offer at a time.
- OFR-2 Sending/receiving a revision supersedes the previously open (sent/received) revision. Accepting supersedes every other open or draft revision and closes the offer; at most one accepted revision per offer (DB unique generated column).
- OFR-3 The pricing scenario of a revision must belong to an estimation for the same enquiry and vessel.
- FIX-1 Fixture only from an **accepted** revision linked to the **selected scenario of an approved estimation**; unique per revision; idempotent (repeat call returns the existing fixture, concurrent duplicate caught by the unique key). Enquiry → fixed.
- ENQ-1 Enquiry status: manual transitions open ⇄ evaluating → offered, any → lost (reason required) / cancelled, lost/cancelled → open. "fixed" only via fixture. Automatic forward-only progression: estimation created → evaluating; revision sent/received → offered; fixture → fixed. Closed enquiries accept no new estimations/offers and cannot be edited.

## 5. Fixture and contract

- FX-01 A fixture is created only from an accepted offer revision with a linked scenario (unique per revision).
- FX-02 A fixture can create at most one contract and at most one voyage (unique FKs). The UI offers "open existing" instead.
- CT-01 Allowed contract transitions: Draft → Under Review → Approved → Active → Completed. Approved/Active → Expired is done by a scheduled job when `end_date` passes without an extension. Draft, Under Review or Approved → Cancelled.
- CT-02 An Active contract's commercial terms change only through an amendment (new `version_no`). The previous version stays queryable.
- CT-03 Rates are effective-dated. An offshore activity uses the rate effective at its `start_at`.
- **BR-CT-01 [CONFIRM]** Can a contract cover several vessels (frame agreements)? Proposed v1: one vessel per contract, plus service contracts without a vessel.
- **BR-CT-02 [CONFIRM]** Options and extensions: tracked as amendments or as separate option records?
- **BR-CT-03 [CONFIRM]** Is "Expired" automatic or manual?

### Phase 5 implemented rules (as built)

- FX-10 Fixture lifecycle: draft → submitted → approved (different user, APR-02); submitted → draft (reject, reason); approved → failed (reason); draft/submitted/approved → cancelled (reason). Failed/cancelled is refused once a contract (non-cancelled) or voyage exists. Failing/cancelling sets the enquiry back from fixed → evaluating (**proposal BR-FX-02 [CONFIRM]**).
- FX-11 Commercial terms on a fixture (rate, basis, currency, quantity, laycan, ports, commissions) are the accepted-revision snapshot and are never editable; draft allows cargo description, terms, remarks and parties only.
- FX-12 Only an approved fixture converts to a contract (draft, one per fixture) or to a voyage (one per fixture, `conversion_type = fixture`, initial snapshot with fixture terms, recap and scenario results; links the contract if one exists). Both idempotent.
- CT-10 Contract from fixture: customer = fixture charterer, vessel/currency/commissions/terms copied; one rate line derived from the fixture basis (per MT → freight_per_mt, per day → day_rate for offshore/service else hire_per_day, per hour → hourly, lump sum → lump_sum). Customer, vessel and currency are then locked. **Assumption A-CT-1:** cargo relet is formalised as a voyage-charter contract.
- CT-11 Contract lifecycle: draft → under_review (requires start & end date and ≥ 1 rate) → approved (different user; writes immutable version 1 effective from start date) → active → completed; under_review → draft (reject); draft/under_review/approved → cancelled (refused if voyages are linked). Contracts are never deleted.
- CT-12 Rates: unit must match the rate type (except "other"); periods of the same type and activity must not overlap; amounts are 4-dp decimal strings. Effective rate for date D = version with the latest effective_from ≤ D, rate lines whose period covers D, none after the contract end date; an activity-specific line wins over a generic one.
- CT-13 Amendments: only for approved/active contracts; one open (draft/submitted) at a time; proposal = amendable header fields (end date, extension options, payment terms, commissions, terms, title) and/or a full replacement rate set and/or clause set; effective date ≥ start of the current version; approval (different user) creates version N+1 with before/after changes recorded; earlier versions/rates/clauses untouched. Currency, customer, vessel and start date cannot be amended.
- **BR-CT-01 implemented as proposed [CONFIRM]:** one vessel per contract; service/other contracts may have none.
- **BR-CT-02 implemented as proposed [CONFIRM]:** options/extensions are amendments of the end date (plus free-text extension options).
- **BR-CT-03 implemented as proposed [CONFIRM]:** expiry is automatic — daily `contracts:check-expiry` (06:00) moves approved/active contracts past their end date to expired (setting `contracts.auto_expire`, default on) and notifies users with `contracts.update` plus the creator `notifications.contract_expiry_days` (default 60) before end, once per end date.
- Commercial rates are hidden from users without `contracts.rates.view`.

## 6. Voyage / operation lifecycle

```
draft → nominated → mobilizing → (loading → loaded → sailing → discharging) | (offshore_operation ⇄ standby)
      → demobilizing → completed → finalized
any non-final → cancelled (if nothing invoiced)
finalized → reopened (permission voyages.reopen, reason) → completed
```
- OP-01 Conversion is idempotent. A second request returns the existing voyage (409 with link if the idempotency key differs).
- OP-02 The `initial` snapshot is written in the same transaction as the voyage.
- OP-03 A voyage can be Completed only when all port calls are sailed or cancelled and there is no captain report in draft.
- OP-04 Finalization requires: Completed, ledger reconciled or the discrepancy acknowledged, all confirmed revenue invoiced or explicitly waived, DA finals approved, laytime agreed or waived.
  - As built (phase 10): the four gates (ledger, revenue, port_da, laytime) are evaluated on finalize. Any failing gate needs a waiver reason of at least 10 characters, stored per gate on the voyage (`finalization_waivers`: reason, user, time). Waiver is the only override; there is no silent bypass.
- OP-05 A finalized voyage is read-only. Reopening creates an audit entry, keeps the old `final` snapshot (renamed to a `milestone`) and requires a new finalization.
- **BR-OP-01 — CONFIRMED 2026-10-01:** an **approved** estimation may convert directly to a voyage without a fixture (internal positioning, owner operation, spot offshore job). Requires a direct-operation reason (≥10 chars) and `operations.voyages.create`; `fixture_id` NULL, `conversion_type = direct_estimation`, selected scenario preserved and copied into the immutable `initial` snapshot. Idempotent (one direct voyage per scenario). Refused if a fixture already exists for the scenario.
- Phase 6 as built:
  - OP-10 Operational moves follow `Voyage::TRANSITIONS` (draft → nominated → mobilizing/loading/sailing/offshore_operation …; loading → loaded → sailing; sailing ⇄ loading/discharging; offshore_operation ⇄ standby; → demobilizing). Complete is allowed from sailing, discharging, offshore_operation, standby or demobilizing. An event time may be back-dated, but not before the previous status change and not into the future.
  - OP-11 **BR-OP-03 implemented as proposal [CONFIRM]:** commencement = time of the first move out of draft/nominated; completion = the time given on Complete.
  - OP-12 Completed voyages stay correctable (port calls, reports, milestones, off-hire) until finalized; finalized and cancelled voyages are read-only.
  - OP-13 Finalization (phase 6 gates): status completed and no draft off-hire. The ledger, invoicing, DA and laytime gates of OP-04 are added in phases 8–10. Finalize writes the immutable `final` snapshot. Reopen reclassifies it as a milestone ("Final before reopen #n") without changing its payload.
  - OP-14 Cancel needs a reason and is allowed before completion. No invoices exist yet, so the "nothing invoiced" check arrives with phase 10.
  - OP-15 Port calls: either a port or an offshore location; ETA ≤ ETB ≤ ETD and ATA ≤ ATB ≤ ATD; ATA is required before ATB/ATD; actual times cannot be in the future. Status is derived from actual times (arrived/berthed/sailed); planned/nominated are set manually. Cancelling is only possible before arrival. Deleting is only possible without actual times, reports or milestones.
  - OP-16 Milestone types must match the operation (offshore → offshore|both; voyage/time charter → voyage|both). Changing the actual time clears verification. Milestones never change voyage or vessel status (**BR-VS-02 proposal**).
  - OP-17 Off-hire: hours are calculated exactly (4 dp); periods on one voyage must not overlap or start before commencement; an open-ended period cannot be agreed; agreed periods are read-only; disputed periods are excluded from actual off-hire days. **Assumption A-OH-1:** no hire deduction is calculated until BR-OP-02 is confirmed.
- **BR-OP-02 [CONFIRM]** Off-hire: is it deducted from hire automatically on time charter or offshore contracts? What rate applies (e.g. day rate × off-hire days) and how is fuel during off-hire handled?
- **BR-OP-03 [CONFIRM]** Voyage commencement and completion definition (e.g. from last discharge of the previous voyage, or delivery/redelivery).

## 7. Offshore activities

- OA-01 `billable_hours + non_billable_hours + standby_hours` must not exceed `(end_at − start_at)` in hours. Equality is not forced.
- OA-02 The rate is taken from the contract rate schedule by activity type and rate type, effective at `start_at`, and snapshotted.
- OA-03 Revenue = billable_hours × hourly rate, or (billable_hours / 24) × day rate, plus standby_hours × standby rate.
- Phase 7 as built:
  - OA-10 Lifecycle: draft → submitted → verified (by a different user, APR-02); submitted → draft on rejection (reason required). `invoiced` is reserved for phase 10. Only drafts can be edited or deleted; verified activities are frozen (hours, rate snapshot, revenue).
  - OA-11 Activities of one vessel must not overlap. The end time cannot be in the future. If no split is given on creation, the whole duration is booked as billable or non-billable according to the activity type's `is_billable_default`.
  - OA-12 Links: contract taken from the activity, else the voyage, else the project. The contract must be approved, active, completed or expired and for the same vessel (if it has one). The voyage must be the same vessel and still open. A project linked to a contract must use that contract. The client is the contract customer, else the project client, else the voyage charterer.
  - OA-13 Rate selection at `start_at` (version and period via ContractRateResolver): billable = activity-specific day_rate/hourly/hire_per_day, else the generic one in that order; standby = standby_day_rate (activity-specific first). Lines are snapshotted with `contract_rate_id` and `contract_version_no`, re-priced on every draft save and on submit. Verification is refused while needed rates are missing (`no_contract`, `no_rates_in_force`, `no_billable_rate`, `no_standby_rate`, `mixed_currency`).
  - OA-14 Revenue, rates and totals are visible only with `contracts.rates.view`.
  - **BR-OA-01–03 implemented as switchable proposals [CONFIRM]** (Settings → Offshore): proration `hourly` (default, h/24), `half_day` (per started 12 h) or `full_day` (per started 24 h); standby at the `standby_rate` (default) or the `full_rate`, **no cap**; mob/demob fee added automatically (default on) to the first MOB/DEMOB activity of the contract, once per contract. The setting in force is stored per activity in `calculation_basis`.
  - **BR-OA-04 not implemented:** fuel used is recorded per activity (`fuel_used`) but not recharged or priced.
- **BR-OA-01 [CONFIRM]** Day-rate proration: per hour (hours/24) or per started half-day/day?
- **BR-OA-02 [CONFIRM]** Are standby hours billed at the standby rate or the full rate? Is there a cap?
- **BR-OA-03 [CONFIRM]** How are mobilization/demobilization lump sums vs day rates recognised?
- **BR-OA-04 [CONFIRM]** Is fuel recharged to the client (common in offshore charters)? If so, at which price basis?

## 8. Captain reports and AIS

- REP-01 Only verified reports feed actual ROB, consumption and distance.
- REP-02 Report times per voyage must increase. Out-of-order reports are rejected with a clear message.
- Phase 6: reports go draft|rejected → submitted → verified | rejected (reason). Only draft or rejected reports are editable; only drafts can be deleted. When a report has a voyage, the vessel must match. On verification the user may choose to copy an arrival/departure report's time into the linked port call's ATA/ATD; nothing happens automatically.
- AIS-01 AIS data is advisory. It never creates milestones or changes statuses automatically. It may suggest them.
- AIS-02 A position is stale when it is older than `ais.stale_hours` (default 6) **[CONFIRM]**.

## 9. Bunkers (Phase 8 — as built)

- BK-01 Closing ROB = opening ROB + received − consumed, per fuel per period. ✅ Implemented (RobLedgerService).
- BK-02 Opening ROB of a period = closing ROB of the previous period. Mismatch → flag `rob_discontinuity` (tolerance 0.001 MT). ✅ Implemented.
- BK-03 Discrepancy is flagged when |actual − estimated consumption| / estimated > `bunker.discrepancy_threshold_pct` (default 5 %) **[CONFIRM default]**. ✅ Implemented.
- **BR-FUEL-01 [CONFIRM]** Are blended bunkers needed (Netpas supports them)?
- **BR-BK-02 [CONFIRM]** On time charter, are bunkers on delivery/redelivery priced and settled with the charterer, and at which prices?

## 10. Port DA (Phase 8 — as built)

- DA-01 A final DA may reference a proforma. Variance = actual − estimated per category and in total. ✅ Implemented (server-side calculation).
- DA-02 Approved final DA lines create voyage expenses (source `port_da`). Re-approval updates them in place only while not paid. ⚠️ Phase 9 (voyage_expenses not yet implemented).
- **BR-DA-01 [CONFIRM]** Does the agent's advance payment need tracking (funds sent vs final DA)?

## 11. Laytime, demurrage, despatch (Phase 8 — as built)

Implemented as a configurable engine (`LaytimeCalculator` domain, L1-L6). **No charter-party interpretation is hard-coded.** Every rule below is a setting on the calculation (`terms_definition`), and defaults require confirmation.

- LT-01 Allowed time = fixed hours, **or** quantity ÷ rate per day × 24. ✅ Implemented (L1).
- LT-02 Time used counts from laytime commencement to completion, minus excluded exceptions (`pct_counted`). ✅ Implemented (L3 with timeline segmentation).
- LT-03 Difference = allowed − used. Negative → demurrage, positive → despatch. ✅ Implemented (L5).
- LT-04 (calculator v1.0.1 — fixed in phase 12: earlier versions crashed or under-counted when laytime ran out) Once on demurrage: after cumulative used reaches allowed, exceptions apply only if `once_on_demurrage_rule = exceptions_apply`. ✅ Implemented (L4).
- LT-05 Demurrage = max(0, −difference)/24 × rate; despatch = max(0, difference)/24 × rate. ✅ Implemented (L6).
- **BR-LT-01 [CONFIRM]** Laytime commencement rule after NOR (e.g. notice time of N hours after NOR tendered/accepted, or the next working period).
- **BR-LT-02 [CONFIRM]** SHEX/SSHEX/FHEX definitions: which days and hours count as weekends/holidays, holiday calendar per port, and "unless used" handling.
- **BR-LT-03 [CONFIRM]** Reversible laytime across load/discharge ports, and averaging.
- **BR-LT-04 [CONFIRM]** "Once on demurrage, always on demurrage": whether exceptions stop counting after laytime expiry. ✅ Implemented as configurable `once_on_demurrage_rule`.
- **BR-LT-05 [CONFIRM]** Despatch basis: all time saved or working time saved. Default ratio despatch = ½ demurrage, only if the contract says so.
- **BR-LT-06 [CONFIRM]** Shifting time, waiting for berth, and weather interruptions: count or not.
- **BR-LT-07 [CONFIRM]** Do laytime and demurrage apply to offshore contracts at all, or only to voyage charters?

## 12. Finance

- FIN-01 Each monetary row stores currency, `fx_rate` (to base) and `base_amount`, fixed at transaction time.
- FIN-02 Gross Profit = revenue − direct voyage expenses. Net Profit = gross − commissions − overhead allocations (if any — BR-FIN-02).
- INV-01 Invoice lifecycle: Draft → (Submitted → Approved when approval is enabled) → Issued → Partially Paid → Paid. Issued and unpaid past the due date → Overdue (daily job). Draft/Approved → Cancelled. Issued → Cancelled only by credit note.
- INV-02 A revenue line can appear on only one active invoice (unique FK). Cancelling the invoice releases it.
- INV-03 The number is assigned at Issue from a gap-free yearly sequence (`INV-2026-00001`) under a row lock. Drafts have no number.
- INV-04 Totals are calculated server-side. Line tax = round(line amount × rate, currency decimals). Invoice tax = Σ line tax (round per line) **[CONFIRM: per-line vs per-invoice rounding, BR-FIN-04]**.
- PAY-01 Σ allocations ≤ payment amount. Allocation to an invoice ≤ invoice balance.
- PAY-02 Status after allocation: balance = 0 → Paid; 0 < paid < total → Partially Paid.
- PAY-03 Duplicate guard: same company + bank_reference + amount + date → warning requiring confirmation. Idempotency key on create.
- PAY-04 Cross-currency allocation: the payment FX snapshot and the invoice FX snapshot differ, and the difference in base currency is stored as `fx_difference_base` (realised FX gain/loss).
- **BR-FX-01 [CONFIRM]** Base (reporting) currency: proposed USD.
- **BR-FX-02 [CONFIRM]** FX source: manual daily entry vs a provider (e.g. central bank), and which rate applies (invoice date, payment date, month-end).
- **BR-FX-03 [CONFIRM]** AED/SAR are pegged to USD. Use fixed rates or still store a daily rate?
- **BR-FX-04 [CONFIRM]** Statistics: convert everything to base currency using the stored FX (proposed), instead of Netpas's documented approach of excluding non-default-currency operations.
- **BR-FIN-01 [CONFIRM]** VAT/tax rules: UAE VAT 5 %? Zero-rating for international shipping services? Reverse charge? The system stores tax codes. It does not decide tax treatment.
- **BR-FIN-02 [CONFIRM]** Are overheads/OPEX (crew, insurance, maintenance) allocated to voyages for Net Profit, or excluded?
- **BR-FIN-03 [CONFIRM]** Payment terms, and whether hire is invoiced in advance (e.g. 15 days in advance on TC) with automatic schedule generation.
- **BR-FIN-04 [CONFIRM]** Rounding method and per-line vs total tax.
- **BR-FIN-05 [CONFIRM]** Accounting system integration (export format, GL codes).
- **BR-FIN-06 [CONFIRM]** Credit notes and write-offs: approval required?

## 13. Approvals

- APR-01 Configured per area. When disabled, Submit is skipped (Draft → Approved directly by a user with the approve permission, or the step is not shown).
- APR-02 Self-approval is disallowed unless `self_approval_allowed`.
- APR-03 Approving locks the record (row lock) and stores `snapshot_hash`. Editing after approval resets it to Draft (only where the area allows) and is audited.
- **BR-APR-01 [CONFIRM]** Which areas require approval at go-live, and the amount thresholds.

## 14. CII

- **BR-CII-01 [CONFIRM]** Applicability: CII (MARPOL Annex VI) applies to ships of 5,000 GT and above of specified types. Many offshore support vessels may be out of scope. Confirm the fleet's applicability per vessel.
- **BR-CII-02 [CONFIRM]** Formula source: current IMO resolutions (reference lines, reduction factors, rating boundaries) must be supplied and verified by Marine Operations before a formula set is marked `verified`.
- **BR-CII-03 [CONFIRM]** Capacity metric (DWT vs GT) per ship type.
- **BR-CII-04 [CONFIRM]** Emission factors (Cf) per fuel from the applicable guidelines.

## 15. Integration

- **BR-INT-01 [CONFIRM]** Shared login with Crew Management (SSO) required?
- **BR-INT-02 [CONFIRM]** Which Crew Management data is needed in Offshore (crew list per vessel, POB, crew change at port calls)?
- **BR-INT-03 [CONFIRM]** Is the vessel master data in Crew Management to be fed from Offshore (Offshore as system of record)?

## 16. Masters

- **BR-PORT-01 [CONFIRM]** Initial port dataset source and licensing.
- **BR-DIST-01 [CONFIRM]** Distance provider: manual table only in v1, or a commercial API (which one, licence)?

## BR-Confirm — consolidated list

BR-VS-01, BR-VS-02, BR-VSL-02, BR-EST-01…09, BR-CT-01…03, BR-OP-01…03, BR-OA-01…04, AIS-02 default, BK-03 default, BR-FUEL-01, BR-BK-02, BR-DA-01, BR-LT-01…07, BR-FX-01…04, BR-FIN-01…06, BR-APR-01, BR-CII-01…04, BR-INT-01…03, BR-PORT-01, BR-DIST-01.
