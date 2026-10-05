# START HERE - Complete Learning Package for Offshore System

**Welcome!** You've just cloned the Offshore Chartering & Vessel Operations system. This guide will help you understand how everything works and get you productive quickly.

---

## What This System Does (30 seconds)

This is a maritime shipping company management platform. It helps companies:
- **Quote & sell** ship capacity (chartering)
- **Plan & execute** voyages (operations)
- **Track costs** and generate **invoices** (finance)
- **Report** financial performance and compliance

**Real example:** Client calls → We quote $300k for a ship → Ship sails → We invoice $300k → Client pays → We report profit.

## Initial Data and the Complete Workflow

Read **[DATA-FLOW-GUIDE.md](DATA-FLOW-GUIDE.md)** to follow the seeded demo records and understand each screen/action from enquiry to final settlement. Use **[DATA-FLOW-REFERENCE.md](DATA-FLOW-REFERENCE.md)** for record links, snapshots, and API examples where the current finance forms do not expose relationship fields.

---

## Your Learning Path (Choose One)

### Option A: "Get Running in 30 Minutes"
**For:** Developers who just want to see it working
1. Open: **docs/QUICKSTART.md**
2. Follow the 7 steps
3. You'll have the system running and understand one complete business flow
**Time:** 30 minutes
**After:** You know how to use the system

---

### Option B: "Understand Everything in 3-4 Hours"
**For:** Developers who want complete understanding
1. **docs/SYSTEM-OVERVIEW.md** (main guide, 1.5 hours)
   - What it does, architecture, all business flows, modules, testing, API
2. **docs/04-DATABASE-SCHEMA.md** (database structure, 45 min)
3. **docs/07-BUSINESS-RULES.md** (business logic, 1 hour)
4. **docs/QUICKSTART.md** (hands-on, 30 min)
**Time:** ~4 hours
**After:** You understand everything

---

### Option C: "Become an Expert (4 Weeks, Part-Time)"
**For:** Developers who want to write code and fix bugs
1. Follow: **docs/LEARNING-PATH.md** (structured 4-week program)
   - Week 1: Foundations (business, architecture, database, setup)
   - Week 2: Code exploration (models, services, controllers, tests)
   - Week 3: Hands-on development (write tests, create features)
   - Week 4: Advanced topics (migrations, policies, optimization)
**Time:** 40 hours (10-15 hours/week)
**After:** You can write features, fix bugs, optimize code

---

## Documentation at a Glance

| Document | Purpose | Time |
|----------|---------|------|
| **START-HERE.md** | This file - navigation guide | 5 min |
| **QUICKSTART.md** | Get running + test one flow | 30 min |
| **SYSTEM-OVERVIEW.md** | Complete system guide | 1.5 hrs |
| **04-DATABASE-SCHEMA.md** | Database structure (84 tables) | 45 min |
| **07-BUSINESS-RULES.md** | Business logic & workflows | 1 hour |
| **LEARNING-PATH.md** | 4-week structured learning | 40 hrs |
| **LEARNING-DOCS-INDEX.md** | Navigation & topic search | 10 min |
| **DEPLOYMENT-RUNBOOK.md** | Deploy to production | 1 hour |
| **BACKUP-SETUP.md** | Backup procedures | 30 min |
| **CII-REQUIREMENTS.md** | CII Phase 13 requirements | 1 hour |

---

## Quick Setup (3 Minutes)

```bash
# Navigate to project
cd /Applications/ServBay/www/offshore

# Backend
cd backend && composer install

# Frontend
cd ../frontend && npm install

# Done! (Full setup: docs/QUICKSTART.md steps 2-4)
```

---

## Key Concepts (2 Minutes)

**The Business Flow:**
```
ENQUIRY → ESTIMATION → OFFER → FIXTURE
                                  ├─ CONTRACT
                                  └─ VOYAGE → OPERATIONS → ACTUAL REVENUE / EXPENSES
                                                              ├─ INVOICE → RECEIPT
                                                              └─ PAYABLE → PAYMENT
```

**The Architecture:**
```
Browser (React UI)
    ↓ HTTP/JSON
REST API (Laravel)
    ↓ ORM
Database (MySQL)
```

**The Code:**
- **Models** = Database tables (Voyage, Invoice, etc.)
- **Services** = Business logic (calculations, workflows)
- **Controllers** = API endpoints (handle requests)
- **Tests** = Verify everything works

**The Permissions:**
- SuperAdmin = Full access
- Finance = Create/approve invoices, payments
- Operations = Create voyages, record operations
- (+ 6 more roles with specific permissions)

---

## Next Steps (Choose One)

### I'm in a Hurry
→ Go to **docs/QUICKSTART.md** (30 min, get running)

### I Want Deep Understanding
→ Read **docs/SYSTEM-OVERVIEW.md** (1.5 hours)

### I Want to Code
→ Follow **docs/LEARNING-PATH.md** (4 weeks)

### I Need to Deploy
→ Read **docs/DEPLOYMENT-RUNBOOK.md** (1 hour)

### I Have Questions
→ Check **docs/LEARNING-DOCS-INDEX.md** (topic search)

---

## System Status (As of Oct 4, 2026)

**Frontend:** ✅ 100% ready (34/34 tests passing)
**Backend:** ⚠️ 41% ready (121/292 tests passing - route restoration in progress)
**Documentation:** ✅ 100% complete
**Deployment:** ✅ Ready with runbook

**Next milestone:** 90%+ backend tests passing → Production ready

---

## Support

**Stuck?**
1. Check the troubleshooting section in SYSTEM-OVERVIEW
2. Search LEARNING-DOCS-INDEX for your topic
3. Ask your team

**Found a bug in docs?**
1. Fix it
2. Note the date: "Last verified: [today]"
3. Tell the team

---

**Choose your learning path above and get started! 🚀**

The best next step is **docs/QUICKSTART.md** (30 minutes to see it all working).
