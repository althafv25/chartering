# Pending Items Summary

## Immediate (Blocking Go-Live)

### Business Approvals (All Modules)
Each module is **TESTED** but requires **BUSINESS APPROVAL** via demo:
- [ ] Masters (vessels, companies, ports, contacts)
- [ ] Chartering (enquiries → offers → fixtures → contracts)
- [ ] Operations (voyages, bunkers, port calls, Port DAs, laytime)
- [ ] Finance (invoices, payables, payments, allocations, approvals)
- [ ] Reports (aging, balancing, P&L, statistics)
- [ ] AIS (fleet map, position recording)

**Action:** Schedule demos with business stakeholders. Each demo should cover:
- Data entry workflows
- Approval chains (where applicable)
- Report accuracy
- Edge cases (e.g., what happens when you cancel a voyage after invoicing?)

### Phase 13 CII (BR-CII-02..04 Confirmation)
**Status:** Code complete, blocked on business input

**What Marine Operations must confirm:**
1. IMO MARPOL Annex VI resolution and year range (e.g., "MEPC.457(77), 2023–2026")
2. Reference line coefficients (a) per ship type (bulk carrier, tanker, container, etc.)
3. Annual reduction factors (e.g., 2023: 0.98, 2024: 0.96, ...)
4. Rating boundary vectors (d1, d2, d3, d4 as attained/required ratios)
5. Emission factors (Cf) per fuel type (HFO, MGO, LNG, etc.)
6. Applicability per vessel (5,000 GT+? ISO tonnage or ITC tonnage?)

**Action:** Provide `CiiFormulaSet` parameters JSON. Once confirmed, calculations will work immediately.

---

## Short-Term (Phase 14 Completion)

### Penetration Test
**Status:** Not started  
**Why:** Requires external firm  
**Effort:** ~1–2 weeks (external)  
**Blocking?** No (can go live without, but recommended before production)

### Vitest Major Upgrade (optional)
**Status:** Current: Vitest 1.x  
**Issue:** 2 moderate dev-only issues when upgrading to 2.x  
**Effort:** ~4 hours to investigate and resolve  
**Blocking?** No (dev-only; no runtime impact)

### Automated Production Backups
**Status:** Manual backup/restore verified (works)  
**What's needed:** Cron job + monitoring  
**Effort:** ~1 hour (add backup script to crontab, test weekly restore)  
**Blocking?** No (can be added post-launch)

### Frontend Unit Tests for Untested Pages
**Status:** 13 test files, 34 tests. Most pages have none.  
**What's missing:** Tests for ~40+ pages  
**Effort:** ~20–30 hours (page count vs. test time ratio)  
**Blocking?** No (integration/E2E tests cover workflows)

---

## Optional Enhancements (Post-Launch)

### Caching for Production Scale (>50 concurrent users)
**What:** Cache non-real-time reports (aging, statistics, P&L) in Redis  
**Why:** Current ~1–2s per report with 160k ledger lines; acceptable for <50 users, but cache would help at scale  
**Effort:** ~8 hours (add Redis layer, invalidation logic)  
**Blocking?** No (monitor response times in production first)

### Read Replicas for Scaling
**What:** Add MySQL read replicas for reports  
**Why:** Reports are read-heavy; offload them from the main DB  
**Effort:** ~20 hours (failover logic, query routing)  
**Blocking?** No (until hitting single-node limits)

### Production Vessel Performance Index
**Status:** Index review showed MySQL optimizer ignored a covering index on voyage_revenues  
**Decision:** Not worth optimizing further without 10x data growth; monitor in production  
**Blocking?** No

---

## Known Issues (Won't Fix Before Launch)

### AIS Track Points Map
**Issue:** Map tiles requested from OpenStreetMap public servers; not asserted in E2E tests  
**Why:** Tests run offline; no internet access during CI/CD  
**Workaround:** Tiles work in production where internet is available  
**Blocking?** No (cosmetic feature, not data-driven)

### Mobile UI
**Status:** Not tested  
**Why:** E2E tests use desktop Chromium only  
**Decision:** Test on real devices post-launch; responsive design in MUI handles most cases  
**Blocking?** No (desktop-first for now)

### CII Calculations at Scale
**Status:** Formula structure ready; calculations disabled until parameters are confirmed  
**Note:** Once confirmed, calculations are straightforward; no performance concerns expected  
**Blocking?** No (blocked by business confirmation, not implementation)

---

## Files to Review Before Launch

| File | Purpose | Status |
|---|---|---|
| `docs/01-PRODUCT-REQUIREMENTS.md` | Feature list & requirements | Complete |
| `docs/02-SYSTEM-ARCHITECTURE.md` | System design & constraints | Complete |
| `docs/04-DATABASE-ERD.md` | Database schema | Complete |
| `docs/07-BUSINESS-RULES.md` | Business logic (52 rules, mostly `[CONFIRM]`) | Complete (pending BR-CII-02..04) |
| `docs/12-DEPLOYMENT.md` | Go-live checklist | Complete |
| `docs/14-FINAL-STATUS.md` | This release summary | Complete |

---

## Test Coverage Summary

| Suite | Count | Status |
|---|---|---|
| Backend PHPUnit | 292 | ✓ Pass |
| Backend Static Analysis | — | ✓ Clean (Larastan, Pint) |
| Frontend Unit | 34 | ✓ Pass |
| Frontend Static | — | ✓ Clean (TypeScript, ESLint) |
| Frontend Build | — | ✓ Pass |
| Browser E2E | 10 | ✓ Pass (Chromium) |
| Load Test | 790k rows | ✓ Complete (results in §6 of deployment guide) |
| Backup/Restore | — | ✓ Verified (data identical) |

---

## Estimated Effort to Address Pending Items

| Item | Effort | Priority |
|---|---|---|
| Business demos (all 6 modules) | 12 hours (external) | **Critical** |
| CII constants confirmation | 2 hours (external) | **Critical** |
| Penetration test | 80 hours (external firm) | High |
| Automated backups | 1 hour | Medium |
| Redis caching layer | 8 hours | Medium |
| Vitest 2.x upgrade | 4 hours | Low |
| Frontend unit tests (pages) | 20–30 hours | Low |

---

## Go-Live Prerequisites Checklist

- [ ] Business demos completed and signed off (all 6 modules)
- [ ] CII constants confirmed and formula sets populated (if CII is in scope)
- [ ] Deployment checklist (docs/12-DEPLOYMENT.md §5–6) completed
  - [ ] APP_DEBUG=false, CORS origin set
  - [ ] Real mail setup (SMTP or service)
  - [ ] HSTS/CSP headers in Nginx
  - [ ] SSL/TLS certificate (Let's Encrypt or CA)
  - [ ] Production admin credentials changed
  - [ ] Scheduler cron configured
  - [ ] Database user least-privilege grant
- [ ] Backup script added to crontab
- [ ] Load balancer configured (if multi-node)
- [ ] Monitoring/alerting configured (APM, error tracking)
- [ ] Data migration plan (if migrating from legacy system)
- [ ] Runbook created (on-call procedures, known issues, rollback plan)

---

**Last Updated:** 2026-10-05  
**For:** Go-live checklist and phase completion tracking
