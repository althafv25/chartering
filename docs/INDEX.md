# Documentation Index

Documentation for the Offshore Chartering & Vessel Operations system. Last cleaned up: 2026-10-10.

## Start here

| If you want to… | Read |
|---|---|
| Install and run the app | [`../README.md`](../README.md) |
| See how a voyage charter flows, with every value to type and the exact numbers | [dataflow.md](dataflow.md) |
| See the same for an offshore day-rate charter (activities, mob/demob, standby) | [offshore-dataflow.md](offshore-dataflow.md) |
| Read a narrative P&L walkthrough with formula references | [pl.md](pl.md) |
| Understand the system in one document (architecture, roles, flows) | [SYSTEM-OVERVIEW.md](SYSTEM-OVERVIEW.md) |
| Follow what users do and what data results from each action | [USER_WORKFLOW.md](USER_WORKFLOW.md), [DATA-FLOW-GUIDE.md](DATA-FLOW-GUIDE.md), [DATA-FLOW-REFERENCE.md](DATA-FLOW-REFERENCE.md) |

The two walkthroughs are replayed by automated tests (`DataflowWalkthroughTest`, `OffshoreDataflowWalkthroughTest`), so their numbers stay correct.

## Status and planning

- **[14-FINAL-STATUS.md](14-FINAL-STATUS.md)** — current verification results, what is built, known limits.
- **[15-PENDING-ITEMS.md](15-PENDING-ITEMS.md)** — go-live checklist, business sign-offs, backlog.
- **[13-DEVELOPMENT-PROGRESS.md](13-DEVELOPMENT-PROGRESS.md)** — phase-by-phase history and the latest follow-up changes.
- **[14-DECISIONS.md](14-DECISIONS.md)** — architecture decision log.

## Design and requirements

- [01-PRODUCT-REQUIREMENTS.md](01-PRODUCT-REQUIREMENTS.md) — scope, users, capabilities, out-of-scope items.
- [02-SYSTEM-ARCHITECTURE.md](02-SYSTEM-ARCHITECTURE.md) — stack, layering, security, integration design.
- [03-MODULES.md](03-MODULES.md) — feature breakdown by module.
- [04-DATABASE-ERD.md](04-DATABASE-ERD.md) and [05-DATABASE-DICTIONARY.md](05-DATABASE-DICTIONARY.md) — data model.
- [07-BUSINESS-RULES.md](07-BUSINESS-RULES.md) — business rules; items marked `[CONFIRM]` await a business decision.
- [08-VOYAGE-CALCULATIONS.md](08-VOYAGE-CALCULATIONS.md) — estimation, offshore revenue, laytime, bunker and finance formulas.
- [10-PERMISSIONS.md](10-PERMISSIONS.md) — roles and permission catalogue.

## Interfaces and modules

- [06-API-SPECIFICATION.md](06-API-SPECIFICATION.md) — REST endpoints, envelopes, error codes.
- [09-AIS-INTEGRATION.md](09-AIS-INTEGRATION.md) — AIS providers and fleet map.
- [CII-REQUIREMENTS.md](CII-REQUIREMENTS.md) — CII scope, formula sets and the confirmations still needed.

## Quality and operations

- [11-TESTING.md](11-TESTING.md) — commands, suites, walkthrough tests.
- [12-DEPLOYMENT.md](12-DEPLOYMENT.md) — environments, production checklist, load-test results.
- [DEPLOYMENT-RUNBOOK.md](DEPLOYMENT-RUNBOOK.md) — release checks, deploy and rollback steps.
- [BACKUP-SETUP.md](BACKUP-SETUP.md) — backup and restore procedure.

## Current status

Phases 2–13 complete and tested (333 backend tests, 59 frontend tests). CII figures await Marine Operations sign-off. Modules await business demos. See [14-FINAL-STATUS.md](14-FINAL-STATUS.md).
