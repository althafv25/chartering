# CII (Carbon Intensity Indicator) Business Requirements Template

**Version:** 1.1
**Phase:** 13 (CII Implementation — Built, pending Marine Operations sign-off on formula figures)
**Status:** Infrastructure built and tested; BR-CII-02 through BR-CII-04 await Marine Operations confirmation before the seeded formula set is marked `verified`
**Last Updated:** 2026-10-09

---

## Executive Summary

The Carbon Intensity Indicator (CII) module calculates and tracks compliance with IMO MARPOL Annex VI carbon intensity regulations. The module (migration, models, pure calculator, service, controller, routes, frontend page) is built and tested (see `docs/13-DEVELOPMENT-PROGRESS.md` phase 13). It ships with one `draft` formula set (`imo-2023-draft`) pre-loaded with the published IMO MEPC.336(76)/337(76)/338(76) reference lines, rating boundaries and emission factors so the module is usable immediately for estimates. **This document's open questions (BR-CII-01 through BR-CII-04) still need Marine Operations confirmation before that formula set — or any new one — is marked `verified` and relied on for regulatory filing.** Until then, every calculation and rating produced by the system should be treated as provisional.

**Current status:** Phase 13 built and tested (`CiiCalculator`, `CiiService`, `CiiController`, `/fleet/cii` page). Formula-set figures (reference lines, reduction factors, rating boundaries, emission factors) are seeded as a sensible IMO-2023 default but remain `draft` pending the confirmations below.

---

## CII Scope

### Applicability Rule

**BR-CII-01:** Determine which vessels in the Offshore fleet are subject to CII reporting requirements.

**Requirement:** IMO MARPOL Annex VI applies to ships of 5,000 GT and above. Many offshore support vessels may be out of scope.

**Questions for Marine Operations:**
- [ ] List of vessel types covered (e.g., Anchor Handling Tug Supply, Platform Supply Vessels, Multi-Purpose Supply Vessels)
- [ ] How many vessels in current fleet are in scope?
- [ ] Are there size thresholds other than 5,000 GT that apply to our vessel types?
- [ ] Which reporting year(s) apply? (e.g., 2025, 2026+)

**Implementation (built):** `cii_vessel_years.scope_status` indicates each vessel-year as `in_scope` or `exempt`; the fleet summary (`GET /cii/fleet-summary`) only counts `in_scope` records. The seeded formula set's `reference_lines` currently cover the fleet's actual vessel-type codes (PSV, AHTS, CREW, UTIL, DSV, ROVSV, SURVEY, TUG, plus BULK/TANKER/GENCARGO) using the general offshore-support reference line as a placeholder for most offshore types — confirm which of these are genuinely in scope and correct the per-type figures accordingly.

---

## Formula Definition

### Formula Sets and Parameters

**BR-CII-02:** Obtain and verify IMO CII calculation formula parameters from Marine Operations.

**Current Regulations:** IMO IMO 2021 amendments (Resolution MEPC.325(75) and related):
- Reference emission lines per ship type and size
- Annual reduction factors (arctan curve: year 0→0%, year 1→1%, year 2→3%, etc.)
- Attained CII rating boundaries (A: < 50%, B: 50-72%, C: 72-83%, D: 83-100%, E: > 100% of reference line)

**Required Parameters for Each Formula Set:**

1. **Formula Set Metadata**
   - [x] Code: `imo-2023-draft` (seeded; create a new code for any revised set — codes are immutable once created)
   - [x] Name: "IMO MARPOL Annex VI CII 2023 (MEPC.337(76)/MEPC.338(76)) — draft, pending Marine Operations confirmation"
   - [x] Effective Date: 2023-01-01
   - [x] Expiration Date: none (open-ended until retired)
   - [x] Status: seeded as `draft`. Lifecycle is `draft → verified → retired`, enforced by `CiiService::markFormulaSetStatus` (one-way; a verified set cannot go back to draft, only forward to retired).

2. **Reference Lines** (per ship type)
   - [ ] Reference line coefficient (a): varies by ship type — **seeded with published figures, confirm fleet coverage**
   - [ ] Reference line exponent (c): typically 3.0 — **seeded; confirm**
   - Seeded example (BULK): a = 961.79, c = 0.477. Offshore support types (PSV, AHTS, etc.) are seeded with a = 69.72, c = 0.195 as a placeholder — **this must be confirmed or replaced per vessel type**

3. **Reduction Factors (Arctan Curve)**
   - [ ] Year 1 reduction: typically 1% — seeded 2023 = 5%, 2024 = 7%, 2025 = 9%, 2026 = 11% (confirm against the current IMO schedule; **note: this field is captured in `parameters.reduction_factors` but is not yet consumed by `CiiCalculator`, which computes the attained/reference ratio directly without a year-based reduction adjustment — see "Known Gap" below**)

4. **Rating Thresholds** (as % of reference line)
   - [x] Rating A: < 50% — seeded and implemented
   - [x] Rating B: 50% to < 72% — seeded and implemented
   - [x] Rating C: 72% to < 83% — seeded and implemented
   - [x] Rating D: 83% to ≤ 100% — seeded and implemented
   - [x] Rating E: > 100% — implemented as the fallback case

**Implementation (built):** `cii_formula_sets.parameters` JSON column, exactly as specified in this document (`reference_lines`, `reduction_factors`, `rating_boundaries`, `emission_factors`). Managed via `GET/POST /cii/formula-sets`, `PUT /cii/formula-sets/{id}`, `POST /cii/formula-sets/{id}/verify`, `POST /cii/formula-sets/{id}/retire`.

**Known gap:** `App\Domain\Cii\CiiCalculator` implements the attained-vs-reference-line ratio and A–E rating exactly as specified, but does **not** currently apply the year-based `reduction_factors` to adjust the reference line before computing the ratio. If BR-CII-02 confirmation determines the reduction factor must shift the reference line year over year (rather than being purely informational), `CiiCalculator::calculate()` needs a small follow-up change before any `verified` formula set is relied on for filing.

---

## Vessel Capacity Metrics

**BR-CII-03:** Confirm which capacity metric (DWT vs GT) applies per vessel type.

**Background:** CII calculation may use Deadweight Tonnage (DWT) or Gross Tonnage (GT) depending on ship type. Most require DWT, but some (e.g., General Cargo ships) may use GT.

**Questions for Marine Operations:**
- [ ] For each ship type, which capacity metric should CII use: DWT or GT?
- [ ] Are all vessels in our fleet correctly classified by type in the database?
- [ ] Do we have accurate DWT and GT measurements for all vessels?
- [ ] Are there any vessels with dual classification that require different CII metrics?

**Implementation (built):** `cii_vessel_years.capacity_metric` = `DWT` | `GT`, selected per record when it is created or edited; `capacity_value` is a snapshot of the vessel's `dwt_mt` or `gt` at that time (not a live reference), so later corrections to the vessel master record do not silently change a past calculation.

---

## Emission Factors

**BR-CII-04:** Obtain fuel emission factors (Cf values) for CII calculation.

**Background:** Fuel emission factors convert fuel consumption to CO2 emissions. IMO publishes standard factors per fuel type in the CII Guidelines.

**Emission Factors (CO2 per mass of fuel, kg CO2/kg fuel) — seeded, confirm before verifying:**

| Fuel Type | Factor (Cf) | Notes |
|-----------|-------------|-------|
| Heavy Fuel Oil (HFO) | 3.151 | Standard bunker fuel |
| Marine Gas Oil / Diesel Oil (MGO/MDO) | 3.206 | Lighter distillate |
| Liquefied Petroleum Gas (LPG) | 3.000 | Seeded; confirm |
| Liquefied Natural Gas (LNG) | 2.750 | Methane slip potential — not separately modelled |
| Marine Biofuel | 1.500 | Placeholder; confirm actual blend factor |

**Questions for Marine Operations:**
- [ ] Confirm emission factors (Cf) for each fuel type used by our fleet
- [ ] Do we have any LNG-powered vessels requiring the methane slip adjustment?
- [ ] Are there any low-carbon or biofuel options planned? (If so, what are their factors?)
- [ ] Should we use the IMO 2023 factors or if updated, the 2024+ figures?
- [ ] What is the accounting period: calendar year or IMO reporting year (Sept-Aug)?

**Implementation (built):** `CiiFormulaSet.parameters.emission_factors`, keyed by the fuel code used elsewhere in the system (`fuel_types.code`: HFO, VLSFO, ULSFO, LSMGO, MGO, MDO, LNG). If a vessel-year's `fuel_consumption` references a fuel code with no `Cf` in the linked formula set, `calculate()` returns `422 cii_calculation_incomplete` naming the missing fuel — it never silently drops that fuel's emissions from the total.

---

## CII Calculation Formula

**Implemented in `App\Domain\Cii\CiiCalculator` (pure PHP, no Eloquent/HTTP, `bcmath`-exact, version `1.0.0`):**

```
CII_attained = (Total CO2 emissions in kg) / (Capacity × Distance sailed)
CII_attained = (Σ fuel_consumption[i] (tonnes) × Cf[i] × 1000) / (Capacity × Distance)
```

Where:
- `fuel_consumption[i]` = mass of fuel type i used during year (tonnes), entered manually per vessel-year today (not yet aggregated automatically from verified captain reports — see Integration Points)
- `Cf[i]` = emission factor for fuel type i (kg CO2 / kg fuel), from the linked formula set
- `Capacity` = DWT or GT per vessel type (tonnes), snapshotted from the vessel master at calculation time
- `Distance` = nautical miles sailed, entered manually per vessel-year today (not yet aggregated from AIS/voyage data — see Integration Points)

**Rating Calculation (implemented exactly as specified):**
```
CII_reference = a × (Capacity)^(-c)
CII_ratio = CII_attained / CII_reference

If CII_ratio < 0.50: Rating = A
Else if CII_ratio < 0.72: Rating = B
Else if CII_ratio < 0.83: Rating = C
Else if CII_ratio <= 1.00: Rating = D
Else: Rating = E
```

---

## Data Collection Requirements

### Input Data Needed

For each vessel per reporting year:

1. **Vessel Identity**
   - [ ] IMO number
   - [ ] Vessel name
   - [ ] Vessel type (from IMO classification)
   - [ ] Gross Tonnage (GT)
   - [ ] Deadweight (DWT)
   - [ ] Year of Build

2. **Fuel Consumption** (daily logs from fuel receipts or bunker notes)
   - [ ] Heavy Fuel Oil (tonnes)
   - [ ] Marine Gas Oil (tonnes)
   - [ ] Any other fuel types used
   - [ ] Date range covered

3. **Voyage Data**
   - [ ] Distance sailed (nautical miles) - from AIS tracking or noon reports
   - [ ] Days at sea
   - [ ] Port time vs transit time

4. **Approval Trail**
   - [ ] Reported by (crew/captain)
   - [ ] Verified by (shore staff)
   - [ ] Approved by (compliance officer)

**Questions for Marine Operations:**
- [ ] Who collects fuel consumption data? (Captain's noon reports, fuel receipts, automated systems?)
- [ ] Who collects distance data? (AIS, manual noon report calculations, voyage planning system?)
- [ ] Where are these currently recorded? (Paper logs, Excel, ERP system, email?)
- [ ] What is the approval workflow for CII data before submission to IMO?
- [ ] How frequently should CII data be updated? (daily, weekly, monthly, annually?)

---

## Reporting and Compliance

### CII Report Structure

Once calculated, the system will generate:

1. **Annual CII Report** (per vessel, per year)
   - Vessel identifiers (IMO, name, call sign)
   - Attained CII value
   - Reference CII value
   - Rating (A-E)
   - Fuel consumption breakdown
   - Distance sailed
   - Comparison to prior year
   - Comparison to fleet average

2. **Fleet Summary Report**
   - Count of vessels by rating (A, B, C, D, E)
   - Fleet average CII ratio
   - Trend analysis (improving/declining)
   - Vessels at risk of non-compliance

3. **Submission Document** (for IMO/authorities)
   - Data Quality Statement
   - Verification Statement
   - Management Plan (if rating D or E)

**Questions for Marine Operations:**
- [ ] Who reviews and approves CII reports before filing with authorities?
- [ ] What is the filing deadline with IMO? (e.g., typically by June 30 following reporting year)
- [ ] Are there penalty or incentive mechanisms in our charter agreements based on CII rating?
- [ ] Should the system automatically flag vessels approaching non-compliance?
- [ ] Do we need to share CII reports with customers/charterers?

---

## Integration Points

### Existing System Links

1. **Vessel Master Data** → `CiiVesselYear` (vessel type, DWT, GT)
2. **Voyage Data** → Distance sailed, dates
3. **Port Call Data** → Port times, fuel replenishment events
4. **Captain Reports** → Fuel consumption from noon reports
5. **AIS Tracking** → Distance validation
6. **Document Storage** → CII reports and compliance files

**Questions for Marine Operations:**
- [ ] Is fuel consumption data currently in the Offshore system? If not, how does it enter the system?
- [ ] Should distance data come from AIS, voyage plans, or manual entry?
- [ ] Who is the "owner" of CII data in the organization? (Marine Ops, Compliance, Finance?)
- [ ] What is the audit trail requirement for CII data changes?

---

## Go-Live Checklist

Before CII module goes to production:

- [ ] BR-CII-01: Vessel applicability list confirmed and loaded (`scope_status` field is built; data entry is manual per vessel-year)
- [ ] BR-CII-02: Formula set(s) verified — mark the seeded `imo-2023-draft` set `verified` via `POST /cii/formula-sets/{id}/verify` once Marine Operations confirms the figures (code, UI and API are built; only the business sign-off is outstanding)
- [ ] BR-CII-03: Capacity metrics (DWT/GT) confirmed for all vessel types (field is built and snapshotted per record; confirm the correct metric choice per type)
- [ ] BR-CII-04: Emission factors confirmed and entered (seeded with published IMO figures; confirm or correct per fuel code)
- [ ] Fuel consumption data source identified (captain reports, bunker receipts, etc.) — **currently manual entry only; automatic aggregation from verified captain reports/ROB ledger is not built**
- [ ] Distance data source identified (AIS, voyage plan, manual) — **currently manual entry only; automatic aggregation from AIS or voyage data is not built**
- [ ] Decide whether `reduction_factors` should adjust the reference line year over year — if yes, `CiiCalculator` needs a small follow-up change (see "Known gap" above)
- [ ] Approval workflow documented and tested — **built and tested**: draft → calculated → verified, self-approval blocked, reopen requires a reason
- [ ] Sample calculation performed and manually verified — **covered by automated tests** (`CiiTest.php`); a manual sign-off calculation by Marine Operations is still recommended before go-live
- [ ] Reporting templates created — not built; CII does not yet appear in `GET /reports` or `GET /statistics`
- [ ] User documentation written — this document; a short end-user guide for the `/fleet/cii` page is not yet written
- [ ] Training completed for crew and shore staff
- [ ] Regulatory filing checklist prepared

---

## Next Steps

1. **Marine Operations Review** (Target: Oct 18, 2026)
   - Present this template to Marine Operations
   - Schedule 1-hour working session to confirm BR-CII-02, BR-CII-03, BR-CII-04
   - Get sign-off on vessel applicability

2. **Development** (Target: Oct 25 - Nov 8, 2026)
   - Load confirmed formula sets
   - Implement fuel consumption data entry
   - Implement distance/voyage integration
   - Build CII calculation service
   - Create reporting screens

3. **Testing** (Target: Nov 9 - Nov 22, 2026)
   - Unit tests for CII calculation
   - Sample vessel calculation validation
   - Report accuracy testing
   - Regulatory compliance verification

4. **Staging & UAT** (Target: Nov 23 - Dec 6, 2026)
   - Deploy to staging
   - Marine Operations user acceptance testing
   - Data accuracy sign-off

5. **Go-Live** (Target: Dec 7, 2026)
   - Production deployment
   - Live data collection begins
   - Monitoring for calculation anomalies

---

## Appendices

### A. IMO Reference Documents
- IMO MEPC.328(76) - 2023 Amendments to MARPOL Annex VI (CII Guidelines 2023)
- IMO 2021 Guidelines on the Method of Calculation of the Attained Energy Efficiency Design Index (EEDI)
- IMO 2023 Guidelines on the CII 2023

### B. Database Schema (Draft)

```sql
CREATE TABLE cii_formula_sets (
    id BIGINT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    parameters JSON NOT NULL, -- {reference_lines, reduction_factors, rating_boundaries, emission_factors}
    effective_date DATE NOT NULL,
    expiration_date DATE NULL,
    status ENUM('draft', 'verified', 'retired') DEFAULT 'draft',
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE cii_vessel_years (
    id BIGINT PRIMARY KEY,
    vessel_id BIGINT NOT NULL,
    reporting_year INT NOT NULL,
    formula_set_id BIGINT NOT NULL,
    fuel_consumption JSON NOT NULL, -- {HFO: 100, MGO: 50, LNG: 0}
    distance_sailed DECIMAL(10,2) NOT NULL,
    attained_cii DECIMAL(10,4) NOT NULL,
    required_cii DECIMAL(10,4) NOT NULL,
    rating ENUM('A', 'B', 'C', 'D', 'E') NOT NULL,
    source ENUM('calculated', 'manual') DEFAULT 'calculated',
    verified_by_id BIGINT NULL,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (vessel_id) REFERENCES vessels(id),
    FOREIGN KEY (formula_set_id) REFERENCES cii_formula_sets(id),
    UNIQUE KEY (vessel_id, reporting_year)
);
```

---

**Document Owner:** Development Team  
**Stakeholders:** Marine Operations, Compliance Officer, CFO  
**Next Review:** Upon Marine Operations Confirmation  
**Distribution:** Internal (Offshore System Team)
