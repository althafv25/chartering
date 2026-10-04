# CII (Carbon Intensity Indicator) Business Requirements Template

**Version:** 1.0  
**Phase:** 13 (CII Implementation - Blocked on Approvals)  
**Status:** Awaiting Marine Operations Confirmation  
**Last Updated:** 2026-10-04

---

## Executive Summary

The Carbon Intensity Indicator (CII) module calculates and tracks compliance with IMO MARPOL Annex VI carbon intensity regulations. This document outlines the business requirements that must be confirmed by Marine Operations before CII implementation proceeds.

**Current Status:** Phase 13 infrastructure built (models, controllers, services ready). Blocked on confirmation of BR-CII-02 through BR-CII-04 by Marine Operations.

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

**Implementation:** `CiiVesselYear.scope_status` will indicate vessel-year combinations as `in-scope` or `exempt`.

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
   - [ ] Code: e.g., `cii-2023-mepc325` (unique identifier)
   - [ ] Name: e.g., "MARPOL Annex VI CII 2023 (MEPC.325(75))"
   - [ ] Effective Date: e.g., 2023-01-01
   - [ ] Expiration Date: e.g., 2026-12-31 (or null if ongoing)
   - [ ] Status: `draft` (for testing) → `verified` (approved) → `retired` (superseded)

2. **Reference Lines** (per ship type)
   - [ ] Reference line coefficient (a): varies by ship type
   - [ ] Reference line exponent (c): typically 3.0
   - [ ] Example: For Bulk Carriers: a = 961.79, c = 0.486

3. **Reduction Factors (Arctan Curve)**
   - [ ] Year 1 reduction: typically 1%
   - [ ] Year 2 reduction: typically 3%
   - [ ] Year 3 reduction: typically 5%
   - [ ] Year 4 reduction: typically 7%
   - [ ] And so on through 2030+

4. **Rating Thresholds** (as % of reference line)
   - [ ] Rating A: < 50%
   - [ ] Rating B: 50% to < 72%
   - [ ] Rating C: 72% to < 83%
   - [ ] Rating D: 83% to ≤ 100%
   - [ ] Rating E: > 100%

**Questions for Marine Operations:**
- [ ] Which IMO resolution applies? (MEPC.325(75), MEPC.328(76), future amendments?)
- [ ] Provide reference lines for each ship type in our fleet (Bulk Carrier, Container Ship, etc.)
- [ ] Confirm reduction factors for each year from 2023 to 2030
- [ ] Confirm rating boundaries (are they fixed at 50-72-83-100 or do they change per regulation?)
- [ ] Are there regional or flag-state specific adjustments?

**Implementation:** `CiiFormulaSet.parameters` stored as JSON:
```json
{
  "reference_lines": {
    "bulk_carrier": { "a": 961.79, "c": 0.486 },
    "tanker": { "a": 107.48, "c": 0.456 }
  },
  "reduction_factors": {
    "2023": 0.01,
    "2024": 0.03,
    "2025": 0.05
  },
  "rating_boundaries": {
    "A": 0.50,
    "B": 0.72,
    "C": 0.83,
    "E": 1.00
  }
}
```

---

## Vessel Capacity Metrics

**BR-CII-03:** Confirm which capacity metric (DWT vs GT) applies per vessel type.

**Background:** CII calculation may use Deadweight Tonnage (DWT) or Gross Tonnage (GT) depending on ship type. Most require DWT, but some (e.g., General Cargo ships) may use GT.

**Questions for Marine Operations:**
- [ ] For each ship type, which capacity metric should CII use: DWT or GT?
- [ ] Are all vessels in our fleet correctly classified by type in the database?
- [ ] Do we have accurate DWT and GT measurements for all vessels?
- [ ] Are there any vessels with dual classification that require different CII metrics?

**Implementation:** `CiiVesselYear.capacity_metric` = 'DWT' | 'GT'

---

## Emission Factors

**BR-CII-04:** Obtain fuel emission factors (Cf values) for CII calculation.

**Background:** Fuel emission factors convert fuel consumption to CO2 emissions. IMO publishes standard factors per fuel type in the CII Guidelines.

**Emission Factors (CO2 per mass of fuel, kg CO2/kg fuel):**

| Fuel Type | Factor (Cf) | Notes |
|-----------|-------------|-------|
| Heavy Fuel Oil (HFO) | 3.151 | Standard bunker fuel |
| Marine Gas Oil (MGO) | 3.206 | Lighter distillate |
| Liquefied Natural Gas (LNG) | 2.750 | Methane slip potential |
| Marine Biofuel | Reduced per source | Typically 0-50% of fossil baseline |

**Questions for Marine Operations:**
- [ ] Confirm emission factors (Cf) for each fuel type used by our fleet
- [ ] Do we have any LNG-powered vessels requiring the methane slip adjustment?
- [ ] Are there any low-carbon or biofuel options planned? (If so, what are their factors?)
- [ ] Should we use the IMO 2023 factors or if updated, the 2024+ figures?
- [ ] What is the accounting period: calendar year or IMO reporting year (Sept-Aug)?

**Implementation:** `CiiFormulaSet.parameters.emission_factors`:
```json
{
  "emission_factors": {
    "HFO": 3.151,
    "MGO": 3.206,
    "LNG": 2.750,
    "marine_biofuel": 1.500
  }
}
```

---

## CII Calculation Formula

**Core Formula (once parameters are confirmed):**

```
CII_attained = (Total CO2 emissions) / (Capacity × Distance sailed)
CII_attained = (Sum(fuel_consumption[i] × Cf[i])) / (Capacity × Distance)
```

Where:
- `fuel_consumption[i]` = mass of fuel type i used during year (tonnes)
- `Cf[i]` = emission factor for fuel type i (kg CO2 / kg fuel)
- `Capacity` = DWT or GT per vessel type (tonnes)
- `Distance` = nautical miles sailed

**Rating Calculation:**
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

- [ ] BR-CII-01: Vessel applicability list confirmed and loaded
- [ ] BR-CII-02: Formula set(s) verified and entered into `cii_formula_sets` table
- [ ] BR-CII-03: Capacity metrics (DWT/GT) confirmed for all vessel types
- [ ] BR-CII-04: Emission factors confirmed and entered
- [ ] Fuel consumption data source identified (captain reports, bunker receipts, etc.)
- [ ] Distance data source identified (AIS, voyage plan, manual)
- [ ] Approval workflow documented and tested
- [ ] Sample calculation performed and manually verified
- [ ] Reporting templates created
- [ ] User documentation written
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
