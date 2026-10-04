# Quick Start Guide - Get Running in 30 Minutes

**Goal:** Get the system running locally and test a complete business flow

---

## Step 1: Setup (5 minutes)

```bash
# Navigate to project
cd /Applications/ServBay/www/offshore

# Backend: Install dependencies & configure
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Frontend: Install dependencies
cd ../frontend
npm install

# Return to root
cd ..
```

## Step 2: Database (5 minutes)

```bash
# Create database (use any MySQL client or this command)
mysql -h 127.0.0.1 -u root -p -e "
CREATE DATABASE IF NOT EXISTS offshore;
"

# Run migrations & seed test data
cd backend
php artisan migrate --seed
```

Expected output:
```
Migrating: 2026_01_01_000000_create_users_table
...
Migrating: 2026_09_04_130000_create_cii_tables
✓ Seeding: RolesAndPermissionsSeeder
✓ Seeding: ReferenceDataSeeder
✓ Seeding: AdminUserSeeder
```

## Step 3: Start Servers (2 minutes)

**Terminal 1:**
```bash
cd /Applications/ServBay/www/offshore/backend
php artisan serve --port=8001
# Wait for: "Laravel development server started at http://127.0.0.1:8001"
```

**Terminal 2:**
```bash
cd /Applications/ServBay/www/offshore/frontend
npm run dev
# Wait for: "Local: http://localhost:5173"
```

## Step 4: Login (1 minute)

1. Open browser: `http://localhost:5173`
2. Login with:
   - Email: `admin@offshore.local`
   - Password: `Admin@12345`

You should see the Dashboard with live KPIs.

---

## Step 5: Test Complete Business Flow (12 minutes)

### Scenario: Quote a ship for cargo

**5.1 Create a Vessel (2 min)**
1. Click sidebar: **Masters → Vessels**
2. Click **+ Add Vessel**
3. Fill:
   - Code: `SHIP-TEST-001`
   - Name: `MV Test Container`
   - IMO: `1234567`
   - DWT: `25000`
   - Type: `Container Ship`
4. Click **Save**

**5.2 Create a Company (Customer) (2 min)**
1. Click: **Masters → Companies**
2. Click **+ Add Company**
3. Fill:
   - Code: `CLIENT-01`
   - Legal Name: `Test Shipping Co`
   - Country: `Singapore`
   - Role: `Charterer`
4. Click **Save**

**5.3 Create an Enquiry (Request) (2 min)**
1. Click: **Chartering → Enquiries**
2. Click **+ New Enquiry**
3. Fill:
   - Business Type: `Voyage Charter`
   - Tonnage Needed: `20000`
   - Route: `Singapore to Rotterdam`
   - Dates: `Oct 15 to Nov 15, 2026`
4. Click **Save**
5. Note the enquiry ID (shown in URL or list)

**5.4 Create an Estimation (Cost Calculation) (2 min)**
1. Click: **Chartering → Estimations**
2. Click **+ New Estimation**
3. Fill:
   - Link Enquiry: Select the enquiry you just created
   - Select Vessel: `MV Test Container`
   - Bunker Cost: `150000` (fuel)
   - Port Cost: `80000` (harbors, pilotage)
   - Crew Cost: `20000`
4. Click **Calculate**
5. System shows: **Total Cost = $250,000**

**5.5 Create an Offer (Quote) (2 min)**
1. Click: **Chartering → Offers**
2. Click **+ New Offer**
3. Fill:
   - Link Estimation: Select estimation from above
   - Freight Rate: `300000` (our quote price to client)
   - Valid Until: `Oct 10, 2026`
4. Click **Save**
5. Notice: **Margin = $50,000** (price $300k - cost $250k)

**5.6 See the Results (Optional, 1 min)**
- Profit on this quote: **$50,000**
- If client accepts at this rate, we make this profit
- Dashboard now shows:
  - Number of enquiries: 1
  - Number of open offers: 1
  - Pipeline value: $300,000

---

## Step 6: Run Tests (3 minutes)

### Backend Tests
```bash
cd /Applications/ServBay/www/offshore/backend

# Run all tests (takes ~4 min)
php artisan test

# Expected: "121 passed, 171 failed"
# (We're at 41% pass rate; working on reaching 90%)

# Run specific tests
php artisan test tests/Unit/Services/Finance/

# Watch one test file
php artisan test tests/Feature/Admin/RoleManagementTest.php
```

### Frontend Tests
```bash
cd /Applications/ServBay/www/offshore/frontend

# Run all tests
npm test

# Expected: "34 tests passed" ✓
```

---

## Step 7: Understanding What Just Happened

**The Flow You Just Tested:**

```
ENQUIRY          ESTIMATION        OFFER
(Request)        (Cost Calc)       (Quote)
│                │                 │
V                V                 V
Client asks      We calculate      We tell client
for ship         costs             our price
                 $250k cost        $300k freight
                                   = $50k profit!
```

**What the system did:**
1. ✓ Stored enquiry in database
2. ✓ Calculated estimation automatically
3. ✓ Generated quote (offer) from estimation
4. ✓ Shows profit margin in UI
5. ✓ Recorded audit trail (who, when, what)

**Next would be:**
- Client accepts offer → Create Contract
- Contract signed → Create Voyage
- Voyage sails → Record port calls
- Port operations complete → Create Invoice
- Payment received → Mark as paid
- Generate reports

---

## Step 8: Explore the Code

### Look at the Database
```bash
mysql -h 127.0.0.1 -u root -p offshore

# See all tables
SHOW TABLES;

# See enquiry data you just created
SELECT * FROM enquiries;

# See the offer (quote)
SELECT * FROM offers;

# See the estimation
SELECT * FROM estimations;

# Check relationships
SELECT e.*, o.freight_rate 
FROM enquiries e 
LEFT JOIN offers o ON e.id = o.enquiry_id;
```

### Look at the Code

**Routes (API definitions):**
```bash
cd /Applications/ServBay/www/offshore/backend
grep -n "enquiries" routes/api.php
grep -n "estimations" routes/api.php
grep -n "offers" routes/api.php
```

**Models (Database relationships):**
```bash
cat app/Models/Enquiry.php          # See Enquiry model
cat app/Models/Estimation.php       # See Estimation model
cat app/Models/Offer.php            # See Offer model

# Notice: Models show relationships to other data
# e.g., Enquiry hasMany Offers
#       Offer belongsTo Estimation
```

**Services (Business Logic):**
```bash
ls app/Services/Chartering/
cat app/Services/Chartering/EnquiryService.php
```

**Tests (How to verify it works):**
```bash
ls tests/Feature/Chartering/
cat tests/Feature/Chartering/EnquiryTest.php
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| **"Cannot connect to database"** | Check MySQL is running: `mysql -u root -p -e "SHOW DATABASES;"` |
| **"Port 8001 in use"** | `php artisan serve --port=8002` |
| **"npm packages missing"** | `cd frontend && npm install` |
| **"Migrations fail"** | `php artisan migrate:refresh --seed` |
| **"Tests fail with 404"** | Routes are being rebuilt; this is expected (41% pass rate) |

---

## What's Next?

**To deepen your understanding:**

1. **Read:** `docs/SYSTEM-OVERVIEW.md` (comprehensive guide)
2. **Read:** `docs/07-BUSINESS-RULES.md` (all business logic)
3. **Write a test:** Copy `tests/Feature/Admin/RoleManagementTest.php`, make your own
4. **Make an API call:** Use cURL or Postman (see SYSTEM-OVERVIEW for examples)
5. **Modify code:** Change a business rule, see how tests break, fix it

---

## Key Files Reference

| Path | Purpose |
|------|---------|
| `backend/app/Models/` | Database models (Enquiry, Offer, etc.) |
| `backend/app/Services/` | Business logic (calculations, workflows) |
| `backend/routes/api.php` | API endpoints (160+ routes) |
| `backend/tests/` | Automated tests (292 total) |
| `frontend/src/pages/` | UI screens (React components) |
| `frontend/src/api.ts` | HTTP client |
| `docs/` | Documentation (22 files) |
| `database/migrations/` | Database schema versions |
| `database/seeders/` | Test data generators |

---

**You're now ready to explore and learn!**

Open `docs/SYSTEM-OVERVIEW.md` for deep dives into each module.
