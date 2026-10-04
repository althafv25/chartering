# 08 — Voyage Calculations

Status: v1.0.0 IMPLEMENTED (phases 4, 6, 7, 8). Code: `app/Domain/Estimation` (pure, no DB/HTTP), `app/Domain/Offshore/ActivityRevenueCalculator`, `app/Domain/Laytime/LaytimeCalculator`. Tests: `tests/Unit/Domain/*CalculatorTest.php`. These are the formulas implemented by `App\Domain\Estimation`, `App\Domain\Laytime`, `App\Domain\Bunker` (ROB ledger) and the finance services. `calculation_version = "1.0.0"` until a formula changes. Phase 6 adds the voyage actuals and comparison (section A, `App\Services\Operations\VoyageMetricsService`, snapshot `calculation_version = "ops-1"`). Phase 7 adds offshore activity revenue (section O, `ActivityRevenueCalculator`, version stored per activity). Phase 8 adds bunker ROB ledger (section B, `RobLedgerService`, on-demand calculation) and laytime (section L, `LaytimeCalculator`, version "1.0.0").

Principles:
- All arithmetic uses `bcmath` through a `Decimal` value object with scale 10 for intermediate values. Stored values are rounded with **ROUND_HALF_UP** to the column scale (money to currency decimals, normally 2). Rounding happens **only at storage/presentation**, never in intermediate steps.
- Every function is pure: inputs → results + trace. No database access inside calculators.
- Rules marked **[CONFIRM]** use the proposed default and expose a setting. See 07.
- Notation: Σ = sum over items. All money values are first converted to the scenario main currency `M`: `amount_M = amount_ccy × fx(ccy→M)` using the FX stored on the item.

## E — Voyage / scenario estimation

| # | Output | Formula |
|---|---|---|
| E1 | Sea distance | `D = Σ legs.distance_nm`; `D_eca = Σ legs.eca_distance_nm` |
| E2 | Leg sea days | `t_leg = distance_nm × (100 + margin_pct)/100 / (speed_kn × 24)` — margin on **time** (BR-EST-01 confirmed); leg override else scenario margin |
| E3 | Sea days | `SD = Σ t_leg` (split laden/ballast) |
| E4 | Port days | `PD = Σ (working_days + idle_days + waiting_days)` per call |
| E5 | Total days | `TD = SD + PD (+ off-hire/other days if modelled)` |
| E6 | Sea fuel per fuel f | `F_sea,f = Σ_leg t_leg × cons(mode = condition, speed, f)`. ECA split: the ECA portion `t_leg × D_eca/D_leg` uses the ECA-compliant fuel's consumption |
| E7 | Port/offshore fuel per fuel f | `Σ_call (working × cons_port_working + idle × cons_port_idle + waiting × cons_port_idle + DP × cons_dp_operation + standby × cons_standby)` (BR-EST-07 confirmed) |
| E8 | Fuel cost | `FC = Σ_f (F_sea,f + F_port,f) × price_f` **[BR-EST-08]** |
| E9 | Voyage costs | `PC = Σ port_cost`, `AC = Σ agency_cost`, `CC = Σ canal_cost`, `OC = Σ cost_items` (`per_day` × TD, `per_mt` × qty, `pct_of_revenue` × GR) |
| E10 | Revenue | `item = qty × rate × fx` (`lump_sum` qty 1; `per_day`/`per_hour` qty defaults to total elapsed days / ×24). `COM = Σ commissionable items × (address+brokerage+other)%/100` (BR-EST-03 confirmed). `NR = GR − COM` |
| E11 | Total voyage cost | `TVC = FC + PC + AC + CC + OC` (commissions shown separately, not double-counted) |
| E12 | Voyage result | `P = NR − TVC`; margin `= P / GR × 100` (null if GR = 0); profit/day `= P / TD` |
| E13 | TCE | `TCE = (NR − owner-account voyage costs) / TD`, TD = total elapsed days incl. port/offshore (BR-EST-04 confirmed). Voyage costs = fuel + port + agency + canal + other (+ tonnage outside cargo relet). Operational (OPEX) costs excluded |
| E14 | Break-even rate (implemented) | Profit is linear in the primary (★) item rate, so `r* = −P(0) / (P(1) − P(0))` using two full engine runs — honours per-item commission and %-of-revenue costs exactly. Null if profit does not depend on the rate |
| E14-doc | Break-even rate (closed form) | Rate `r*` at which P = 0. For a single rate-driven revenue item with quantity Q and total commission c%: `r* = (TVC + other_net_revenue) / (Q × (1 − c/100))`, where `other_net_revenue` is the net of the remaining revenue items, subtracted with the sign shown in the trace. Same form with Q = days for per-day rates. For a target TCE T: `r_T = (TVC + T × TD − other_net) / (Q × (1 − c/100))` |

Edge cases: speed = 0 → validation error. Distance 0 is allowed (port-only operation). Division by zero → result `null` with a trace note, never 0.

### Reference test case EST-TC-01 (to be confirmed by Chartering before use as golden data)

Inputs: one ballast leg of 1,200 NM at 12 kn, no margin; one laden leg of 2,400 NM at 12 kn; port days 2 (load) + 2 (discharge) working; consumption sea 20 MT/day VLSFO, port working 3 MT/day MGO; prices VLSFO 600, MGO 800 USD/MT; port costs 30,000 + 30,000; freight 50,000 MT × 15.00 USD; commission 3.75 % + 1.25 %.

| Step | Value |
|---|---|
| Sea days | 1200/288 + 2400/288 = 4.1666667 + 8.3333333 = **12.5** |
| Port days | **4** → TD **16.5** |
| Fuel | VLSFO 12.5×20 = 250 MT → 150,000; MGO 4×3 = 12 MT → 9,600; FC **159,600** |
| Port costs | **60,000** → TVC **219,600** |
| GR | 750,000; COM 5 % = 37,500; NR **712,500** |
| P | 712,500 − 219,600 = **492,900**; margin 65.72 %; per day 29,872.73 |
| TCE | (712,500 − 219,600)/16.5 = **29,872.73** USD/day |
| Break-even freight | 219,600 / (50,000 × 0.95) = **4.6231579 → 4.62** USD/MT |

### S — Strategies (one per estimation type, no type conditionals in the engine)

| Type | Default accounts at creation | Default revenue line | P&L |
|---|---|---|---|
| Voyage charter | bunkers & port costs: owner | FREIGHT per MT (enquiry rate idea / quantity) | StandardStrategy |
| Time charter | bunkers & port costs: **charterer** (shown, excluded from P&L) | HIRE per day × elapsed days | StandardStrategy |
| Offshore day-rate | owner (fuel recharge open: BR-OA-04 — set account per price) | DAYRATE per day × elapsed days | StandardStrategy |
| Cargo relet | owner | FREIGHT per MT + TONNAGE cost line | CargoReletStrategy (§R) |

Every amount carries `account` (owner|charterer). Charterer-account amounts are reported in `breakdown.charterer_account` and excluded from costs, profit and TCE.

### R — Cargo relet (BR-EST-05 confirmed)

| # | Formula |
|---|---|
| R1 | Relet revenue = net revenue (E10) |
| R2 | Tonnage cost = Σ cost items in expense group `tonnage` (head-charter hire/freight we pay) |
| R3 | Voyage costs = fuel + port + agency + canal + other (our account) |
| R4 | Net relet margin (profit) = R1 − R2 − R3 − operational |
| R5 | TCE = (R1 − R3) / elapsed days |

**Assumption A-RELET-1 (documented, not a business rule invented):** tonnage cost is excluded from TCE so the relet TCE is comparable with the daily hire paid for the tonnage; the margin after tonnage is reported as profit. Change requires a `calculation_version` bump.

**Assumption A-ECA-1:** inside ECA, non-ECA-compliant sea fuel is replaced 1:1 by mass with the scenario's ECA fuel (`eca_fuel_type_id`); ECA days = adjusted leg days × ECA distance / leg distance. Without an ECA fuel, a warning is raised and the non-compliant fuel is costed.

**Precision:** bcmath scale 12; multiplication before division where possible; storage rounding half-up (money to currency decimals, days 6 dp, MT 3 dp, rates 4 dp). Trace values are exact (trailing zeros trimmed).

**Inputs hash:** SHA-256 of the canonical (key-sorted) JSON of `{type, currency, currency_decimals, inputs}` — stored on the scenario and its result; a result is "current" only if hashes match.

## O — Offshore day-rate

| # | Formula |
|---|---|
| O1 | Activity hours `H = (end_at − start_at)` in hours; `billable + non_billable + standby ≤ H` |
| O2 | Revenue: hourly `= billable_h × rate_h`; day rate `= billable_h/24 × rate_d` **[BR-OA-01]**; standby `= standby_h/24 × standby_rate_d` **[BR-OA-02]**; plus mob/demob lump sums **[BR-OA-03]** |
| O3 | Fuel per activity `= Σ hours_mode/24 × cons_mode,f` (estimate) or reported actuals |
| O4 | Activity profit `= revenue − (fuel cost + direct expenses)`; utilization `= billable_h / available_h` (available = period hours − maintenance/dry-dock hours) **[CONFIRM]** |

### O-impl — as built (phase 7, `App\Domain\Offshore\ActivityRevenueCalculator`, `OffshoreActivityService`)

| Line | Quantity | Amount |
|---|---|---|
| billable, per_hour rate | billable_h | qty × rate |
| billable, per_day rate | days(billable_h) | qty × rate |
| standby (`standby_rate`) | days(standby_h) | qty × standby day rate |
| standby (`full_rate`) | as billable | qty × billable rate |
| mob/demob fee | 1 | lump sum (once per contract) |

days(h): `hourly` = h/24; `half_day` = ceil(h/12)/2; `full_day` = ceil(h/24). Line amounts are rounded to 2 dp (half-up) and revenue is the sum of the lines. Revenue is null if a needed rate is missing or currencies differ. Example: 30 h billable at 14 500/day, hourly → 1.25 d → 18 125.00. With half_day, 13 h → 1 d → 14 500.00. Golden tests: `tests/Unit/Domain/ActivityRevenueCalculatorTest.php`. O3/O4 (activity fuel cost, profit, utilization) are not built yet.

## A — Voyage actuals & comparison (phase 6, `VoyageMetricsService`)

| Metric | Actual (current / milestone / final) |
|---|---|
| total_days | (completed_at or now − commenced_at) / 24 h |
| port_days | Σ (ATD − ATA) over non-cancelled calls with both times |
| sea_days | total_days − port_days |
| off_hire_days | Σ hours / 24 of non-disputed events |
| sea_distance_nm | Σ distance_since_last_nm of verified reports (REP-01) |
| fuel_total_mt | Σ consumed_mt of verified report fuel lines (also per fuel type) |
| gross_revenue, total_costs, profit, tce_per_day | **carried from the estimate** (`financials_source = estimate`, assumption A-OP-2) until phases 9–10 |

All values are computed with bcmath and rounded only for output (days/money 2 dp, fuel 3 dp). For the initial column the values come from the scenario results in the `initial` snapshot, with off-hire = 0. Variance = column − initial.

## B — Bunkers (Phase 8 — implemented)

**Implemented:** `RobLedgerService::forVoyage()` computes ROB ledger on-demand from verified `captain_report_fuel_lines` and `bunker_stems`. No `bunker_rob_ledger` table.

| # | Formula |
|---|---|
| B1 | `closing = opening + received − consumed` per fuel and period ✅ |
| B2 | Variance `= actual_consumed − estimated_consumed`; `% = variance / estimated × 100`; flag if `|%| > threshold` (setting `bunker.discrepancy_threshold_pct`, default 5%) ✅ |
| B3 | Continuity check `opening(n) = closing(n−1)` (tolerance 0.001 MT); flag `rob_discontinuity` if violated ✅ |

**Implementation:**
- Each verified captain report closes a period from the previous report time
- Opening ROB = previous report's ROB (or null for first report)
- Received = report `received_mt` (compared against delivered stems in same period → `received_mismatch` flag)
- Consumed = report `consumed_mt`
- Estimated = (initial snapshot fuel per day) × period days (assumption A-BK-1)
- Computed closing vs reported ROB → discontinuity if diff > 0.001 MT
- Variance percentage → flagged if exceeds threshold

Tests: `tests/Feature/Operations/BunkerTest::test_rob_ledger_continuity_received_check_and_consumption_flag`

## L — Laytime (Phase 8 — implemented)

**Implemented:** `App\Domain\Laytime\LaytimeCalculator` (pure functions), `LaytimeService`, `LaytimeController`. Tests: `tests/Feature/Operations/LaytimeTest.php`.

| # | Output | Formula |
|---|---|---|
| L1 | Allowed hours | `A = fixed_hours` or `qty / rate_per_day × 24` ✅ |
| L2 | Counting window | `[commenced_at, completed_at]` with commencement per **[BR-LT-01]** ✅ |
| L3 | Used hours | `U = Σ over window segments (segment_hours × pct_counted/100)`. Exceptions overlay the window, and overlapping exceptions take the lowest pct. Weekend/holiday exclusion per terms **[BR-LT-02]** ✅ |
| L4 | Time on demurrage | Starts when cumulative counted time reaches A. After that, exceptions apply only if `once_on_demurrage_rule = exceptions_apply` **[BR-LT-04]** ✅ |
| L5 | Difference | `Diff = A − U`. Demurrage `= max(0, −Diff)/24 × dem_rate_per_day`. Despatch `= max(0, Diff)/24 × des_rate_per_day` **[BR-LT-05]** ✅ |
| L6 | Reversible | A and U summed across linked calculations **[BR-LT-03]** ⚠️ Not yet implemented (single calculation only) |

**Implementation notes:**
- Timeline segmentation: exceptions create event boundaries; segments are merged where they overlap, using the lowest `pct_counted`
- Once-on-demurrage: cumulative time tracked during segmentation; once threshold passed, remaining exception segments forced to 100% if rule is `always_on_demurrage`
- All times stored UTC; inputs converted from port-local via `LocalTime::toUtc()`
- Trace stored in `laytime_calculations.trace` JSON for audit

Test LT-TC-01 (illustrative, SHINC, no exceptions): A = 72 h, commenced 01 Oct 06:00, completed 05 Oct 12:00 → U = 102 h → 30 h on demurrage at 24,000/day → **30,000.00**.

## F — Finance

| # | Formula |
|---|---|
| F1 | `base_amount = round(amount × fx_rate_to_base, base_decimals)` |
| F2 | Invoice line `amount = round(qty × rate, d)`, `tax = round(amount × tax% / 100, d)` **[BR-FIN-04]** |
| F3 | Invoice `subtotal = Σ amount`, `tax = Σ tax`, `total = subtotal + tax`, `balance = total − Σ allocations (invoice ccy)` |
| F4 | Allocation in a different currency: `invoice_ccy_amount = round(allocated × fx(pay→inv), d)`; `fx_difference_base = allocated_base_at_payment_rate − invoice_ccy_amount_base_at_invoice_rate` |
| F5 | P&L per voyage: `Revenue = Σ revenues (base)`, `Expenses = Σ expenses (base)`, `Gross Profit = Revenue − Expenses (excl. commission)`, `Net = Gross − Commission (− overheads per BR-FIN-02)`, `Margin`, `Profit/day = Net / voyage_days`, TCE per E13 |
| F6 | Variance `= Actual − Estimate`; `% = variance / |estimate|` (null if estimate = 0) |
| F7 | Aging buckets on `due_date`: current, 1–30, 31–60, 61–90, > 90 days |

## C — CII (architecture only; constants not seeded)

`CO2 = Σ_f fuel_mass_f × Cf_f`; `attained CII = CO2 × 10^6 / (capacity × distance_nm)`; `required CII = reference(a, c, capacity) × (1 − Z_year/100)`; rating from boundary vectors d1–d4. Every parameter comes from a **verified** `cii_formula_sets` row **[BR-CII-01..04]**.

## Test policy

Each formula gets PHPUnit unit tests with hand-calculated fixtures (`tests/Fixtures/estimation/*.json`), covering edge cases (zero distance, zero revenue, multi-currency, ECA split), and property checks (e.g. P monotonic in rate; break-even rate yields |P| < 0.01).
