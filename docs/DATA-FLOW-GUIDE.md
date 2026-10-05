# End-to-End Data Flow: From Initial Data to Final Settlement

**Verified against the implementation:** 2026-10-05

**Audience:** Business users, administrators, and testers

This guide explains what each record means, where to create it, what passes to the next stage, and how to explore the seeded example.

For database relationships, API examples, and source-code locations, use the companion [Data Flow Reference](DATA-FLOW-REFERENCE.md).

## Contents

1. [The complete flow](#1-the-complete-flow)
2. [What the initial data contains](#2-what-the-initial-data-contains)
3. [How information moves](#3-how-information-moves)
4. [Step-by-step workflow](#4-step-by-step-workflow)
5. [Automatic handoffs](#5-automatic-handoffs)
6. [Status reference](#6-status-reference)
7. [Walk through the seeded example](#7-walk-through-the-seeded-example)
8. [Common questions](#8-common-questions)
9. [Glossary](#9-glossary)

## 1. The complete flow

In simple terms:

> Prepare the companies, vessels, and locations → record the customer's requirement → estimate the job → negotiate a price → fix the business → formalize the contract → execute the voyage → record actual income and costs → invoice and settle → review the final result.

```text
MASTER DATA
Companies / Vessels / Ports & Locations / Consumption / Distances / Currencies
    ↓
ENQUIRY: What does the customer need?
    ↓
ESTIMATION + SCENARIOS: Can we do it profitably?
    ↓ Calculate, select, submit, approve
OFFER + REVISIONS: What price and terms do we negotiate?
    ↓ Mark sent / record received, then accept a revision
FIXTURE: What business was agreed?
    ↓ Submit and approve
    ├── CONTRACT: Formal terms, rates, clauses, and versions
    └── VOYAGE: Operational execution; links the contract if it already exists
            ↓
        OPERATIONS: Calls / Reports / Bunkers / Port DA / Laytime / Activities
            ↓
        ACTUAL REVENUE & EXPENSES
            ├── Customer invoice → Received payment → Allocate → Paid
            └── Supplier payable → Outgoing payment → Allocate → Paid
            ↓
        COMPLETE / FINALIZE VOYAGE + REPORTS
```

The contract and voyage are both created from an **approved fixture**. For a fully linked commercial example, create the contract first, then return to the fixture to create the voyage. The voyage conversion uses the contract that exists at that time; contract creation does not automatically create a voyage.

There is also an operational shortcut: an **approved, calculated estimation** can create a voyage directly using **Direct operation → voyage**, with a reason. This is intended for work such as internal positioning. It is blocked when a fixture already exists for that scenario.

Finance work can occur during the voyage. You do not have to wait until the ship finishes to prepare or issue an invoice.

## 2. What the initial data contains

### 2.1 Reusable master data

| Data | Seeded examples | Used by |
|---|---|---|
| Companies: 7 | Gulf Offshore Energy LLC, Bluewater Marine Holdings Ltd, Harborline Chartering FZE, Seaway Port Agency LLC, Oceanic Fuels & Services, Northstar Offshore Operations, Meridian Marine Surveyors | Customers, owners, brokers, agents, suppliers, operators, surveyors |
| Ports: 6 | Jebel Ali, Fujairah, Abu Dhabi, Doha, Singapore, Rotterdam | Enquiry itinerary, estimation legs, voyage calls, bunkers, Port DA |
| Offshore locations: 3 | Alpha Field, Omega Platform, Sigma Wind Farm | Offshore itinerary points, projects, and activities |
| Vessels: 5 | Gulf Endeavour, Gulf Pioneer, Northstar Guardian, Seaway Swift, Meridian Explorer | Vessel shortlist, estimations, contracts, voyages, reports |
| Vessel consumption profiles | Demo design profile for each vessel | Starting assumptions for estimation fuel consumption |
| Vessel status history | Commercial and operational status entries | Fleet status board and availability review |
| Stored distances: 5 | Examples include Jebel Ali–Fujairah and Fujairah–Alpha Field | Estimation route-distance defaults |
| Reference data | Vessel types, fuels, cargo types, revenue/expense categories, currencies, and other catalogues | Dropdowns, calculation classification, and financial records |

Master records are reused. For example, you select **Gulf Offshore Energy LLC** as the customer rather than retyping its details for each new job.

### 2.2 Linked demo business records

The demo chain uses **Gulf Endeavour / PSV001**, **Gulf Offshore Energy LLC**, **USD**, and a **USD 300,000 lump-sum rate**.

| Stage | Identifier | Initial seeded state | Where to find it |
|---|---|---|---|
| Enquiry | `ENQ-DEMO-001` | Evaluating | Chartering → Enquiries |
| Estimation | `EST-DEMO-001` | Submitted | Chartering → Estimations |
| Scenario | A — Demo base case | Selected, not calculated | Open the estimation |
| Offer | `OFF-DEMO-001`, revision 1 | Accepted | Chartering → Offers |
| Fixture | `FX-DEMO-001` | Approved | Chartering → Fixtures |
| Contract | `CON-DEMO-001`, version 1 | Active | Contracts |
| Voyage | `VOY-DEMO-001` | Sailing | Operations → Voyages |
| Voyage calls | Jebel Ali → Alpha Field → Fujairah | First sailed; others planned | Voyage → Itinerary |

These are **linked demonstration records inserted directly by the seeder**. They were not created by clicking every workflow action. Consequently:

- The enquiry remains Evaluating even though its seeded offer and fixture are further along.
- The estimation is Submitted and its scenario has no calculated result.
- The seeded fixture recap and contract header snapshot are simplified.
- The seeded voyage has no initial estimate snapshot. Estimated figures and estimate-vs-actual comparisons can therefore be blank.
- The seeder adds no captain reports, bunker orders, Port DAs, laytime calculations, offshore projects/activities, actual ledger lines, invoices, payables, or payments.

The demo is useful for navigation and learning relationships. Use a fresh enquiry for a full approval-and-calculation exercise.

The counts and states above describe the seed and were confirmed for the demo chain in the local database on 2026-10-05. Records you subsequently edit or add can differ. Dates are relative to the first seed run, and sample quantities and costs should be reviewed before using them in an estimation.

## 3. How information moves

The system uses three kinds of handoff:

| Handoff | Meaning | Example |
|---|---|---|
| **Link** | Stores the ID of another record so you can trace its origin | A voyage links to its fixture, contract, estimation, scenario, and vessel |
| **Copy / snapshot** | Captures the values at that point in time | Offer terms are copied into the fixture; the original estimate is saved when converting to a voyage |
| **Derived record / calculation** | Produces new information after an action | Approving a final Port DA creates voyage expense lines |

**A link does not mean every later edit propagates everywhere.** Changing a vessel consumption profile does not silently change an existing scenario. Refresh defaults explicitly while the estimation is editable. Sent offer revisions, fixture commercial terms, and approved contract versions preserve their history.

Likewise, **estimated revenue and costs are a plan**. They are not automatically actual ledger entries or invoices when a voyage is created.

## 4. Step-by-step workflow

### Step 0 — Prepare the master data

**Screens:** Masters → Companies, Vessels, Ports & Locations, Distances, Reference Data, Currencies & FX; Fleet → Vessel Status.

1. Review the customer's company and roles. The enquiry customer picker includes Charterer and Customer companies.
2. Review an active vessel's particulars, status, and consumption profile.
3. Check the required ports/offshore locations and stored route distances.
4. Check fuels, categories, currency, and any necessary FX rates.

**Output:** Reusable records that the business forms can select and snapshot.

For calculation practice, use valid consumption modes such as `sea_laden`, `sea_ballast`, `port_working`, `port_idle`, `standby`, or `dp_operation`. The demo profile contains generic `sea` and `port` rows; these need to be replaced with calculator-compatible modes in an editable scenario or profile.

### Step 1 — Record the customer enquiry

**Screen:** Chartering → Enquiries → **New enquiry**.

Enter the business type, customer, broker if applicable, cargo/service description, quantity or period, date window, currency, rate idea, and ordered itinerary.

Open the saved enquiry and use **Vessels** to add candidates to the shortlist.

**Stored:** Enquiry header, itinerary points, and shortlisted vessels. A new enquiry starts Open.

**Passed forward:** The customer requirement and itinerary feed estimation defaults and offer terms. Shortlisting alone does not create an estimation.

### Step 2 — Estimate the work and approve a scenario

**Screen:** Open the enquiry → **Vessels → Estimate**, or **Estimations → New estimation**.

1. Select the active vessel, currency, and estimation type.
2. The system creates scenario A using vessel, consumption-profile, and enquiry-itinerary defaults.
3. Under **Inputs**, complete the legs, speeds, distances, call durations, consumption rows, bunker prices, revenue, costs, and commissions.
4. Change a value and click **Save & calculate**. Review **Results**, **Calculation trace**, and any calculation issues.
5. Clone or add scenarios if you want to compare alternatives. Use **Compare** to review them.
6. Click **Select scenario** on the fully calculated scenario you want to use.
7. Click **Submit for approval**, then have an authorized approver click **Approve**.

**Stored:** Scenario inputs and vessel snapshot, calculation results, input hash/version, selected scenario, submission and approval information.

**Passed forward:** The scenario's primary revenue item supplies rate, basis, and commissions to the initial offer draft. The enquiry supplies dates, quantity, and itinerary.

**Readiness rules:** Submission requires a selected scenario with a current calculation. Missing distance, speed, fuel price, consumption mode, or other required inputs must be completed. An approved estimation is read-only; use **Clone** for a new revision.

**Practical checks for the demo assumptions:**

- Confirm every enquiry leg has a distance; the stored demo distance list does not cover every possible pair.
- Match sea-consumption speed to the speed used by the leg.
- Use `port_working`, not generic `port`, and add consumption for any DP/standby time being modeled.
- Check the revenue basis: a lump-sum enquiry does not automatically fill a per-tonne default revenue row.
- Avoid counting the same fuel or port cost both in calculated fuel/call costs and in additional cost items.

### Step 3 — Create and negotiate the offer

**Screen:** Open the approved, enquiry-linked estimation → **Create offer**.

1. Confirm creation from the intended scenario. The system opens a new offer with draft revision 1.
2. Click **Edit draft** to review or change the negotiated rate, dates, quantity, commissions, and terms.
3. Click **Mark sent** for your outbound proposal.
4. Use **Record counter** and **Record received** for an inbound customer counterproposal, or **New revision** for a new outbound proposal.
5. Click **Accept** on the agreed sent/received revision.

**Stored:** One offer header and a numbered revision history. Sent/received revisions are immutable. Acceptance closes the offer and supersedes its other open/draft revisions.

**Passed forward:** The accepted revision becomes the commercial basis for the fixture.

**Important:** **Mark sent** records the workflow state. It is not an automatic customer-email action. Offers are created from the estimation detail workflow; the Offers listing has no standalone New offer form.

### Step 4 — Create and approve the fixture

**Screen:** Open the accepted offer → accepted revision → **Create fixture**.

1. Create the fixture.
2. Review **Recap**: vessel, parties, rate, quantity, date window, itinerary, commissions, and estimated figures.
3. Add permitted descriptive terms or remarks while it is Draft.
4. Click **Submit for approval**, then **Approve** using an authorized approver.

**Required:** The revision must be Accepted and linked to the selected scenario of an approved estimation.

**Stored:** A fixture with a frozen commercial recap. Rate, currency, quantity, date window, ports, and commissions come from the accepted revision and cannot be edited on the fixture.

**Automatic change:** Creating the fixture advances the enquiry to Fixed in the normal workflow. Fixture approval is a separate action.

**Passed forward:** An approved fixture can create a draft contract and a draft voyage. The Fixtures listing has no standalone New fixture form.

### Step 5 — Formalize the contract

**Screen:** Open the approved fixture → **Create contract**.

1. Review customer, vessel, currency, commissions, terms, and the rate copied from the fixture.
2. Set both start and end dates. Conversion initially leaves the end date empty.
3. Review **Rates**, **Clauses**, and payment terms; save your changes.
4. Click **Submit for review → Approve → Activate**.
5. Use **Amendments** for later changes to approved/active terms. Approved amendments create new versions with effective dates.

**Stored:** Contract header, rate schedule, clauses, approved version snapshots, and amendments.

**Passed forward:** The voyage can link the contract; offshore activities can use its effective rate schedule. An active contract can be completed separately after the work.

### Step 6 — Create and plan the voyage

**Screen:** Return to the approved fixture → **Create voyage**.

1. The system creates a Draft voyage linked to the fixture, estimation, scenario, vessel, customer, and existing contract.
2. Normal conversion writes an **initial snapshot** of the pricing basis and available estimate results.
3. Open **Itinerary → Add call** to build the operational route. The conversion creates the voyage shell; it does not automatically populate operational port calls.
4. Add milestones where needed.
5. Use the available status buttons to move through Nomination, Loading, Sailing, Offshore operation, Discharging, and other applicable states.

**Stored:** The operational job, original estimate baseline, actual itinerary, milestones, and status history.

**Passed forward:** Operational and finance records identify the job through its voyage ID. A voyage status change does not automatically synchronize the vessel's separate Fleet Status tracks.

### Step 7 — Record operational actuals

These records accumulate against the voyage; they are not one mandatory linear sequence.

| Record | Where / actions | Data captured | Downstream use |
|---|---|---|---|
| Port/location call | Voyage → Itinerary | ETA/ETB/ETD, ATA/ATB/ATD, purpose, agent | Call status, operational timing, Port DA, laytime |
| Captain report | Voyage → Captain reports, or Operations → Captain Reports | Position, time, distance, fuel consumed/received, ROB, notes | Verified reports feed operational metrics and the ROB ledger |
| Bunker order/delivery | Voyage → Bunkers, or Operations → Bunkers | Supplier, fuel, ordered/delivered tonnes, price, BDN, time | Delivery amount and fuel-received reconciliation |
| Port DA | Operations → Port DA → New DA | Voyage/call/port/agent, proforma or final costs, cost items | Approval of a final DA books confirmed voyage expenses |
| Laytime | Operations → Laytime → New calculation | Allowed/used time, exceptions, demurrage/despatch rates | Calculate → Submit → Agree; positive demurrage creates revenue, positive despatch creates an expense |
| Off-hire | Voyage → Off-hire | Time intervals, reason, disputed/agreed state | Operational metrics and finalization readiness |
| Offshore activity | Offshore voyage → Offshore activities, or Operations → Offshore Activities | Service type, period, billable/non-billable/standby hours, contract | Verify a priced activity to generate confirmed revenue |

Port actual times derive call states: **ATA → Arrived**, **ATB → Berthed**, **ATD → Sailed**. Enter call times in the location's local timezone; they are stored in UTC.

Captain reports follow **Draft → Submitted → Verified**, with rejected reports editable for correction. Add reports chronologically. On verification, copying arrival/departure time into the linked port call is an explicit option.

**Bunker delivery is operational fuel data.** The delivery service does not automatically create a voyage expense or supplier payable. Book its financial cost explicitly through the finance workflow.

**Offshore variant:** Hour-based service revenue requires an applicable day/hour/hire rate effective at the activity start time. The demo contract has only a lump-sum freight rate, so it is not a ready-to-use hourly offshore activity contract. Use a suitable contract/rate schedule for that exercise. Offshore projects can group activities by client, contract, and location.

### Step 8 — Record actual revenue and expenses

**Screen:** Commercial → Revenue & Expenses.

- Revenue: **New revenue → Confirm**, or use revenue generated by activity verification / laytime agreement.
- Expenses: **New expense → Confirm → Approve**, or use expenses generated by final Port DA approval, laytime agreement, or payable approval.

Examples include actual freight, hire, service revenue, port charges, fuel supplier bills, and despatch.

**Passed forward:** Confirmed revenue can be attached to an invoice. Confirmed/approved expenses contribute to voyage P&L. Draft and cancelled lines do not contribute to actual voyage P&L.

**Current form coverage:** The manual New revenue/New expense drawers do not expose voyage or contract selectors. A line created there is not linked to a voyage simply because its description mentions the voyage number. Use an operational source that supplies the link, or the API's `voyage_id` / `contract_id` fields for linked manual entries. See the [linked-finance example](DATA-FLOW-REFERENCE.md#5-linked-finance-example) in the reference.

### Step 9 — Bill the customer

**Screen:** Commercial → Invoices → **New invoice**.

1. Select customer, type, currency, issue date, and due date.
2. Open the draft invoice → **Add line** to enter billable descriptions and amounts.
3. Submit and approve where applicable, then click **Issue**. The backend permits direct Draft → Issued when mandatory invoice approval is disabled.
4. Generate a PDF if needed.

**Stored:** Customer billing snapshot, invoice lines, taxes/totals, FX snapshot, and invoice number assigned at Issue.

**Linked flow:** The API can create a voyage/contract-linked invoice and attach confirmed revenue IDs. Attaching a revenue line marks it Invoiced, even while the invoice is still a draft; the line is reserved against duplicate billing.

**Current form coverage:** The New invoice drawer does not expose voyage/contract selectors, and Add line does not expose a confirmed-revenue picker. Manual invoice lines alone do not create or invoice a voyage revenue ledger entry. Use the API for the linked flow; do not expect the manual UI invoice alone to populate voyage P&L or clear a confirmed-revenue finalization gate.

### Step 10 — Record supplier bills

**Screen:** Commercial → Payables → **New payable**.

1. Select supplier, supplier invoice reference, currency, issue/due dates, subtotal, and tax.
2. Approve the payable.
3. When a payable has a voyage link, approval creates/updates its sourced voyage expense with Approved status.

**Current form coverage:** New payable does not expose a voyage selector. Include `voyage_id` through the API for the voyage-linked cost/P&L handoff.

Port DA expenses and payable-sourced expenses have different sources. The application does not automatically match a supplier payable to an existing Port DA expense; check the ledger when representing the same cost through multiple sources.

### Step 11 — Record and allocate payments

**Screen:** Commercial → Payments → **Record payment**.

For customer money:

1. Choose **Received**, the same customer as the invoice, amount, currency, payment date, and bank reference.
2. Open the payment → **Allocate** → select an Issued/Partially paid/Overdue invoice.
3. Enter the allocation amount and confirm.

For supplier money:

1. Choose **Paid**, the same supplier as the payable, and the outgoing amount.
2. Allocate to an Approved/Partially paid payable.

**Automatic result:** Allocation updates the target's paid amount and balance. A nonzero remainder gives Partially paid; zero balance gives Paid. Cross-currency allocations store the realized FX difference.

Recording a payment alone does not settle a bill. The allocation is the link that settles it. The payment record itself remains Recorded unless reversed; Paid is the invoice/payable status. Paying a payable does not currently change its linked voyage expense status to Paid automatically.

### Step 12 — Complete, finalize, and review

**Screen:** Operations → Voyages → open voyage.

1. Complete the remaining calls: each must be Sailed or Cancelled.
2. Resolve Draft/Submitted captain reports.
3. Click **Complete** from an eligible operational status.
4. Review finalization readiness: bunker ROB flags, confirmed revenue not yet invoiced, final DA approvals, and laytime agreement.
5. Resolve Draft off-hire events by agreeing or disputing them.
6. Issue the necessary linked invoices before finalizing; invoice issue is blocked on a finalized voyage.
7. Click **Finalize**. Failing financial gates can be explicitly waived with a reason of at least 10 characters; waivers are saved and audited.

**Stored:** An immutable final operational snapshot and Finalized status. Reopening returns the voyage to Completed and preserves the previous final snapshot as a milestone.

Finalization does **not** require every customer invoice or supplier payable to be fully paid. Settlement and cash reporting can continue after operational finalization. Completing the contract is also a separate action.

**Review:** Voyage → P&L / Estimate vs actual; Commercial → Receivables Aging / Voyage P&L / Balancing; Reports.

Actual voyage P&L uses voyage-linked, non-estimate ledger lines:

```text
Gross profit = actual revenue − actual expenses
Net profit   = gross profit − commissions
```

Cash receipts and supplier payments are reported separately from profit. A profitable voyage can still have unpaid invoices.

## 5. Automatic handoffs

| When you do this | The system does this |
|---|---|
| Create an enquiry-linked estimation | Creates a scenario from defaults and advances the enquiry to Evaluating |
| Mark an outbound revision sent / inbound revision received | Freezes the revision and advances the enquiry to Offered |
| Accept a revision | Closes the offer as Accepted and supersedes other open/draft revisions |
| Create fixture from accepted revision | Copies the recap and advances the enquiry to Fixed |
| Create contract from approved fixture | Creates a draft contract and initial rate rows |
| Create voyage from approved fixture/estimation | Creates a draft voyage and an initial snapshot; calls are added separately |
| Verify a positively priced offshore activity | Creates/updates confirmed voyage revenue |
| Agree positive demurrage / despatch | Creates/updates confirmed revenue / expense respectively |
| Approve final Port DA | Books nonzero actual DA items as confirmed voyage expenses |
| Approve voyage-linked payable | Creates/updates an approved voyage expense |
| Attach confirmed revenue to invoice | Marks it Invoiced and reserves it from duplicate billing |
| Issue invoice | Assigns its number and snapshots FX |
| Allocate payment | Updates invoice/payable paid amount, balance, and status |
| Finalize voyage | Saves final snapshot and locks operational editing |

An approval or conversion action does not automatically perform the next approval or conversion action.

## 6. Status reference

These are the main successful paths; rejection/cancellation routes exist where supported.

| Record | Main path |
|---|---|
| Enquiry | Open → Evaluating → Offered → Fixed |
| Estimation | Draft → Submitted → Approved; Rejected → Reopen as draft |
| Offer revision | Draft → Sent (outbound) / Received (inbound) → Accepted |
| Fixture | Draft → Submitted → Approved; returned submission → Draft |
| Contract | Draft → Under review → Approved → Active → Completed |
| Voyage example | Draft → Nominated → Loading → Loaded → Sailing → Discharging → Completed → Finalized |
| Captain report | Draft → Submitted → Verified; Rejected → correction / resubmission |
| Offshore activity | Draft → Submitted → Verified |
| Bunker stem | Ordered → Delivered |
| Port DA | Draft → Submitted → Approved |
| Laytime | Draft → Calculated inputs → Submitted → Agreed; Disputed is an alternative decision |
| Revenue | Draft → Confirmed → Invoiced |
| Expense | Draft → Confirmed → Approved; Paid exists as a status |
| Invoice | Draft → Submitted → Approved → Issued → Partially paid → Paid; direct issue depends on configuration |
| Payable | Draft → Approved → Partially paid → Paid |
| Payment | Recorded; allocations affect target bills; Reverse → Reversed |

Buttons depend on both the record's state and your permissions. Most business approvals prevent self-approval by default; use another authorized approver. Port DA approval uses its own configuration check, and captain-report verification is permission-based without the shared self-approval check.

## 7. Walk through the seeded example

### A. Explore the records already present

1. Open `ENQ-DEMO-001`; inspect Overview, Vessels, Estimations, and Offers.
2. Open `EST-DEMO-001`; inspect scenario A and its assumptions. Its Submitted state is read-only and it has no results.
3. Open `OFF-DEMO-001`; inspect accepted revision 1 and its pricing-scenario link.
4. Open `FX-DEMO-001`; inspect Recap and the Contract/Voyage chips.
5. Open `CON-DEMO-001`; inspect Rates, Clauses, and Versions.
6. Open `VOY-DEMO-001`; inspect Itinerary, then add captain reports, bunker data, or finance records as part of your exercise.
7. Visit Commercial screens to create and allocate bills/payments; use the reference's API examples when voyage-linked finance is required.

Search by the stable demo numbers. Numeric IDs may differ in another database.

### B. Practice the normal path from first to last

1. Create a **new enquiry** using the existing demo companies, vessel, and locations. Choose plausible quantities and dates.
2. Create a new estimation from that enquiry; complete calculator inputs and select a calculated scenario.
3. Submit and approve it using the intended approval users.
4. **Create offer → Edit draft → Mark sent → Accept → Create fixture**.
5. **Submit fixture → Approve → Create contract**.
6. Fill dates/rates → **Submit for review → Approve → Activate**.
7. Return to the fixture → **Create voyage → Itinerary → Add call**.
8. Execute the operation and record actuals.
9. Create linked revenue/expenses, issue linked invoices, and approve linked payables.
10. Record Received/Paid payments and **Allocate** them.
11. Complete and finalize the voyage, complete the contract when appropriate, and review the reports.

This produces normal calculated results, snapshots, and workflow activity rather than relying on the simplified seeded chain.

## 8. Common questions

| Question | Explanation / action |
|---|---|
| Why is there no New offer button in the Offers list? | Use **Create offer** on an approved, enquiry-linked estimation. That is where the pricing context is available. |
| Why is there no New fixture button in the Fixtures list? | Use **Create fixture** on an accepted offer revision linked to an approved selected scenario. |
| Why can I not calculate the demo estimation? | It is Submitted, so inputs are read-only. Use a fresh estimation, or clone while the linked enquiry accepts work. |
| Why can an approver see a button but still get a rejection? | The backend also checks readiness, relationships, and self-approval rules. |
| Why are estimated voyage figures blank? | The seeded voyage has no initial snapshot; normal conversion writes one. |
| Why is voyage P&L zero after creating an invoice? | P&L reads actual voyage ledger lines, not free-text invoice lines. Check voyage links and line states. |
| Why is a payment recorded but the invoice still unpaid? | Open the payment and allocate it to the issued invoice. |
| Why is Complete blocked? | Calls remain open or reports remain Draft/Submitted. |
| Why is Finalize blocked? | Resolve Draft off-hire and review the four readiness gates. |

## 9. Glossary

- **Enquiry:** Customer's request for a vessel, transport, or service.
- **Scenario:** One set of costing/pricing assumptions for an estimation.
- **Fixture:** The recap of agreed chartering business, created from the accepted offer revision.
- **Laycan:** Contractual loading/readiness date window.
- **TCE:** Time Charter Equivalent, an estimation measure of daily earning performance.
- **ETA / ETB / ETD:** Estimated arrival / berthing / departure time.
- **ATA / ATB / ATD:** Actual arrival / berthing / departure time.
- **ROB:** Remaining On Board fuel quantity.
- **BDN:** Bunker Delivery Note.
- **Port DA:** Port Disbursement Account, covering agent/port costs.
- **Demurrage:** Charge for excess allowed laytime.
- **Despatch:** Payment for finishing within the allowed laytime where applicable.
- **Invoice / payable:** Customer bill / supplier bill.
- **Allocation:** Applying recorded cash to a specific invoice or payable.
- **Snapshot:** Preserved values from a particular stage of the workflow.

---

**Next reference:** [Data Flow Reference — relationships, endpoints, and linked-finance examples](DATA-FLOW-REFERENCE.md).
