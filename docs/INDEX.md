# Documentation Index

Complete documentation for the Offshore Chartering & Vessel Operations system.

## Essential Reading (Start Here)

- **[01-PRODUCT-REQUIREMENTS.md](01-PRODUCT-REQUIREMENTS.md)** — What the system does. Feature list, user roles, scope.
- **[02-SYSTEM-ARCHITECTURE.md](02-SYSTEM-ARCHITECTURE.md)** — How it's built. Tech stack, constraints, deployment model.
- **[14-FINAL-STATUS.md](14-FINAL-STATUS.md)** — Phase 14 completion status. Test results, known limits, what's blocked.
- **[15-PENDING-ITEMS.md](15-PENDING-ITEMS.md)** — Go-live checklist. Business approvals, CII unblock, penetration test.

## Design & Requirements

- **[03-MODULES.md](03-MODULES.md)** — Feature breakdown by module (Masters, Chartering, Operations, Finance, AIS).
- **[04-DATABASE-ERD.md](04-DATABASE-ERD.md)** — Entity Relationship Diagram and schema overview.
- **[05-DATABASE-DICTIONARY.md](05-DATABASE-DICTIONARY.md)** — Detailed table/column reference. Required/optional, types, indices.
- **[07-BUSINESS-RULES.md](07-BUSINESS-RULES.md)** — 52 business rules (52 marked `[CONFIRM]` pending decision). Tax, FX, laytime, CII, offshore.

## Development & Operations

- **[06-API-SPECIFICATION.md](06-API-SPECIFICATION.md)** — REST API endpoints, request/response schemas, error codes.
- **[08-VOYAGE-CALCULATIONS.md](08-VOYAGE-CALCULATIONS.md)** — Voyage financials: revenue, expense, gross profit, daily costs.
- **[09-AIS-INTEGRATION.md](09-AIS-INTEGRATION.md)** — AIS data flow, providers, position import, fleet map.
- **[10-PERMISSIONS.md](10-PERMISSIONS.md)** — User roles and permission model. 9 roles, 50 permissions.
- **[11-TESTING.md](11-TESTING.md)** — Test suites: PHPUnit (292 tests), Vitest (34 tests), E2E (10 tests). Load test, backup drill.
- **[12-DEPLOYMENT.md](12-DEPLOYMENT.md)** — Infrastructure setup, deploy checklist, performance results, backup procedure.

## Project Management

- **[13-DEVELOPMENT-PROGRESS.md](13-DEVELOPMENT-PROGRESS.md)** — Phase-by-phase status. Phases 2–12 complete, phase 13 blocked, phase 14 in progress.
- **[14-DECISIONS.md](14-DECISIONS.md)** — Architecture decisions and tradeoffs.
- **[15-PENDING-ITEMS.md](15-PENDING-ITEMS.md)** — Pre-launch checklist and effort estimates.

## Quick Navigation

**Starting Development:**
1. Read [01-PRODUCT-REQUIREMENTS.md](01-PRODUCT-REQUIREMENTS.md) to understand scope
2. Read [02-SYSTEM-ARCHITECTURE.md](02-SYSTEM-ARCHITECTURE.md) for tech choices
3. Check [README.md](../README.md) for local setup

**Before Go-Live:**
1. Review [15-PENDING-ITEMS.md](15-PENDING-ITEMS.md) for blockers
2. Follow [12-DEPLOYMENT.md](12-DEPLOYMENT.md) §5–6 for infrastructure
3. Schedule business demos from [15-PENDING-ITEMS.md](15-PENDING-ITEMS.md) checklist

**Troubleshooting:**
1. Check [11-TESTING.md](11-TESTING.md) for test commands and coverage
2. Review [07-BUSINESS-RULES.md](07-BUSINESS-RULES.md) for logic questions
3. See [06-API-SPECIFICATION.md](06-API-SPECIFICATION.md) for API behavior

**Business Questions:**
1. [05-DATABASE-DICTIONARY.md](05-DATABASE-DICTIONARY.md) — Data model reference
2. [07-BUSINESS-RULES.md](07-BUSINESS-RULES.md) — Business logic and assumptions (many marked `[CONFIRM]`)
3. [08-VOYAGE-CALCULATIONS.md](08-VOYAGE-CALCULATIONS.md) — How financials are computed
4. [09-AIS-INTEGRATION.md](09-AIS-INTEGRATION.md) — AIS behavior and data flow

---

**Current Status:** Phases 2–12 complete and tested. Phase 13 (CII) awaits business confirmation. Phase 14 testing in progress.  
**Last Updated:** 2026-10-05
