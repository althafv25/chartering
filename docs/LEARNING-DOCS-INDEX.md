# Documentation Index - How to Learn This System

**Goal:** Help you find the right document for your learning stage

---

## Quick Navigation

### 🚀 "I Just Cloned the Repo - What Now?"
Start here: **QUICKSTART.md**
- 30-minute guide to get running
- Create your first business flow (enquiry → offer)
- Run tests
- **Time:** 30 minutes
- **After this:** You can navigate the system

---

### 📚 "I Want to Understand the System"
Read in order:
1. **SYSTEM-OVERVIEW.md** (1.5 hours)
   - What does it do?
   - Architecture (frontend → backend → database)
   - Core business flows
   - Module descriptions
   - **After this:** You understand the big picture

2. **04-DATABASE-SCHEMA.md** (45 min)
   - 84 tables explained
   - Relationships visualized
   - **After this:** You can read database queries

3. **07-BUSINESS-RULES.md** (1 hour)
   - 60+ business rules documented
   - Approval workflows
   - Permissions matrix
   - **After this:** You understand the business logic

---

### 💻 "I Want to Learn the Code"
Follow: **LEARNING-PATH.md** (4 weeks, 10-15 hours/week)

**Week 1:** Foundations
- Business context
- Architecture overview
- Database schema
- Business rules & workflows
- Local setup

**Week 2:** Code Exploration
- Models (how data is represented)
- Services (where business logic lives)
- Controllers (how API requests are handled)
- Permissions & Authorization
- Testing basics

**Week 3:** Hands-On Development
- Write your first test
- Modify code & verify tests catch issues
- Explore database with tinker
- Test API endpoints with cURL
- Understand React components

**Week 4:** Advanced Topics
- Database migrations
- Deep-dive policies
- Performance optimization
- Build a feature end-to-end

---

### 🧪 "I Want to Test Everything"
Read: **SYSTEM-OVERVIEW.md** → "Testing Guide"
- Run backend tests: `php artisan test`
- Run frontend tests: `npm test`
- Write your own test
- Test via cURL
- Manual browser testing

---

### 🚢 "I Want to Deploy This"
Read: **DEPLOYMENT-RUNBOOK.md**
- Pre-deployment checklist (48h, 24h, 1h before)
- Step-by-step deployment
- Post-deployment verification
- Rollback procedures (3 scenarios)
- Known issues & troubleshooting
- On-call playbook
- **Time:** Complete before production deployment

---

### 💾 "I Want to Set Up Backups"
Read: **BACKUP-SETUP.md**
- Backup script configuration
- Cron setup
- Restore procedures
- S3 integration (optional)
- Verification steps
- **Time:** 30 minutes

---

### 📖 "I Want a Complete Overview"
Read: **INDEX.md** (main docs navigation)
- Links to all 22 documentation files
- What each file covers
- Recommended reading order

---

## Document Guide

### By Purpose

| Purpose | Read This | Time |
|---------|-----------|------|
| Get running locally | QUICKSTART | 30 min |
| Understand architecture | SYSTEM-OVERVIEW § "Architecture" | 20 min |
| Learn the code | LEARNING-PATH | 40 hours |
| Write a test | SYSTEM-OVERVIEW § "Testing Guide" | 1 hour |
| Test via API | SYSTEM-OVERVIEW § "API Testing" | 30 min |
| See business flows | SYSTEM-OVERVIEW § "Core Business Flows" | 45 min |
| Know database tables | 04-DATABASE-SCHEMA | 45 min |
| Understand permissions | 07-BUSINESS-RULES § "Permissions" | 30 min |
| Deploy to production | DEPLOYMENT-RUNBOOK | 1 hour |
| Set up backups | BACKUP-SETUP | 30 min |
| Understand CII Phase | CII-REQUIREMENTS | 1 hour |

---

## Document Descriptions

### QUICKSTART.md
**What:** 30-minute guide to get the system running and test a business flow
**For:** New developers who just cloned the repo
**Covers:** Setup, database, servers, login, test chartering flow
**After:** You can navigate the system

### SYSTEM-OVERVIEW.md
**What:** Comprehensive guide to the entire system
**For:** Developers wanting to understand architecture and business logic
**Covers:** What the system does, architecture, business flows, modules, testing, API, troubleshooting
**After:** You understand everything

### LEARNING-PATH.md
**What:** 4-week structured learning program
**For:** Developers who want to become expert in the codebase
**Covers:** Week 1 (foundations), Week 2 (code), Week 3 (hands-on), Week 4 (advanced)
**After:** You can write features, fix bugs, optimize code

### 04-DATABASE-SCHEMA.md
**What:** Complete database structure documentation
**For:** Developers who need to understand data relationships
**Covers:** All 84 tables, foreign keys, indexes
**After:** You can write database queries

### 07-BUSINESS-RULES.md
**What:** All business logic documented
**For:** Developers who need to understand workflows
**Covers:** 60+ business rules, approval workflows, permissions, validations
**After:** You understand approval flows and permissions

### DEPLOYMENT-RUNBOOK.md
**What:** Complete deployment procedures for production
**For:** DevOps, SRE, release engineers
**Covers:** Pre-deployment, deployment steps, verification, rollback, on-call procedures
**After:** You can deploy safely to production

### BACKUP-SETUP.md
**What:** Database backup and recovery procedures
**For:** DevOps, SRE, database admins
**Covers:** Backup script, cron setup, restore, S3 integration, verification
**After:** You have automated backups

### CII-REQUIREMENTS.md
**What:** Business requirements for CII Phase 13
**For:** Developers working on CII implementation
**Covers:** CII scope, formulas, calculations, reporting, go-live plan
**After:** You understand CII requirements

### PHASE14-VERIFICATION.md
**What:** Test results and deployment readiness assessment
**For:** Project managers, release leads
**Covers:** Test pass rates by module, known issues, readiness status
**After:** You know if system is ready for go-live

### PHASE14-SESSION-FINAL.md
**What:** Summary of Phase 14 completion work
**For:** Project stakeholders, team leads
**Covers:** Accomplishments, test improvements, remaining work, timeline
**After:** You know the current status

---

## Learning Paths by Role

### 👨‍💻 Backend Developer
1. QUICKSTART (30 min)
2. SYSTEM-OVERVIEW (1.5 hours)
3. 04-DATABASE-SCHEMA (45 min)
4. LEARNING-PATH Week 1-2 (12 hours)
5. Read code: Models, Services, Controllers
6. LEARNING-PATH Week 3-4 (15 hours)
**Total:** ~35 hours

### 🎨 Frontend Developer
1. QUICKSTART (30 min)
2. SYSTEM-OVERVIEW (1.5 hours)
3. Learn React/TypeScript basics (outside these docs)
4. Read frontend components: `src/pages/`, `src/components/`
5. Understand API integration: `src/api.ts`
6. LEARNING-PATH Week 3 § "Frontend Components"
**Total:** ~20 hours

### 🏗️ DevOps/SRE
1. SYSTEM-OVERVIEW § "Architecture" (20 min)
2. DEPLOYMENT-RUNBOOK (1 hour)
3. BACKUP-SETUP (30 min)
4. Read: `docs/12-DEPLOYMENT.md`
5. Set up monitoring, scaling, alerts (outside these docs)
**Total:** ~5 hours

### 🧪 QA/Test Engineer
1. QUICKSTART (30 min)
2. SYSTEM-OVERVIEW § "Testing Guide" (45 min)
3. Create test data (30 min)
4. Write tests: Follow LEARNING-PATH Week 3
5. Run manual tests in browser
**Total:** ~15 hours

### 📊 Product Manager
1. QUICKSTART (30 min)
2. SYSTEM-OVERVIEW § "Core Business Flows" (45 min)
3. SYSTEM-OVERVIEW § "Modules" (30 min)
4. 07-BUSINESS-RULES (1 hour)
5. Create test data in UI (30 min)
**Total:** ~3.5 hours

---

## Topic-Based Search

### I Need to Learn About...

**Users & Authentication**
- SYSTEM-OVERVIEW § "User Roles & Permissions"
- 07-BUSINESS-RULES § "Permissions"
- LEARNING-PATH Week 2 Day 4 § "Permissions & Authorization"

**Chartering Module**
- SYSTEM-OVERVIEW § "Flow 1: Chartering & Contract"
- SYSTEM-OVERVIEW § "Module 2: Chartering"
- QUICKSTART § "Step 5.3-5.5: Create Enquiry, Estimation, Offer"
- LEARNING-PATH Week 3 § "Hands-on: Create test data"

**Voyage & Operations**
- SYSTEM-OVERVIEW § "Flow 2: Voyage Execution"
- SYSTEM-OVERVIEW § "Module 3: Operations"
- QUICKSTART § "Step 5.6: See the results"

**Finance & Invoicing**
- SYSTEM-OVERVIEW § "Flow 3: Invoicing & Payment"
- SYSTEM-OVERVIEW § "Module 4: Finance"
- QUICKSTART § "Step 5.7: Explore the code"

**Testing**
- SYSTEM-OVERVIEW § "Testing Guide"
- LEARNING-PATH Week 2 Day 5 § "Testing Code"
- LEARNING-PATH Week 3 Day 1 § "Create Your First Test"

**Database**
- 04-DATABASE-SCHEMA
- LEARNING-PATH Week 1 Day 3 § "Database Schema"
- LEARNING-PATH Week 3 Day 3 § "Create Test Data & Run Queries"

**API**
- SYSTEM-OVERVIEW § "API Testing"
- LEARNING-PATH Week 2 Day 3 § "Controllers & API Endpoints"
- LEARNING-PATH Week 3 Day 4 § "API Testing with cURL"

**Deployment**
- DEPLOYMENT-RUNBOOK
- docs/12-DEPLOYMENT.md
- PHASE14-SESSION-FINAL § "Go-Live Readiness"

**Backups**
- BACKUP-SETUP
- LEARNING-PATH Week 1 § "Troubleshooting"

---

## How to Use This Documentation

### For Self-Study
1. Start with QUICKSTART
2. Follow LEARNING-PATH Week-by-week
3. Refer to specific docs as needed

### For Onboarding New Team Members
1. Week 1: Send QUICKSTART + SYSTEM-OVERVIEW
2. Week 2-3: They follow LEARNING-PATH
3. Pair program on real features Week 4+

### For Reference While Coding
1. Keep SYSTEM-OVERVIEW open for architecture questions
2. Keep 04-DATABASE-SCHEMA open for table structure
3. Use LEARNING-PATH examples for test templates

### For Deployment
1. Read DEPLOYMENT-RUNBOOK § "Pre-Deployment Checklist"
2. Follow step-by-step deployment procedures
3. Reference on-call playbook for issues

---

## Document Maintenance

These docs are updated as the system evolves:
- **QUICKSTART:** Updated when setup steps change
- **SYSTEM-OVERVIEW:** Updated when modules change or new flows added
- **LEARNING-PATH:** Updated with new code patterns
- **DEPLOYMENT-RUNBOOK:** Updated after each production deployment

**Last Updated:** 2026-10-04  
**Next Review:** 2026-11-04

---

## Getting Help

**If you can't find something:**
1. Search the docs: Use browser Ctrl+F
2. Check SYSTEM-OVERVIEW § "Modules" for module-specific info
3. Check LEARNING-PATH for code examples
4. Ask a team member: "Have you read [document]?"

**If documentation is wrong:**
1. Update the file
2. Add a note: "Last verified: [date]"
3. Inform the team

**If you have questions:**
1. See if the answer is in the docs
2. Ask in Slack: #offshore-dev or #offshore-ops
3. Schedule a pairing session with someone who knows the code

---

**Happy Learning! 🚀**
