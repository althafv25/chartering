# Offshore Chartering & Vessel Operations System - Complete Overview

**Last Updated:** 2026-10-04  
**For:** New developers, testers, and system learners

---

## Table of Contents
1. [What This System Does](#what-this-system-does)
2. [System Architecture](#system-architecture)
3. [Core Business Flows](#core-business-flows)
4. [How to Set Up Locally](#how-to-set-up-locally)
5. [Step-by-Step Learning Path](#step-by-step-learning-path)
6. [Testing Guide](#testing-guide)
7. [Module Deep Dives](#module-deep-dives)
8. [API Testing](#api-testing)

---

## What This System Does

This is a **maritime chartering and vessel operations management platform**. It helps shipping companies manage:

- **Chartering:** Quote requests, offers, contract negotiations
- **Voyage Planning:** Route planning, port coordination, fuel management
- **Operations:** Port activities, laytime calculations, crew reports
- **Finance:** Invoicing, payments, expense tracking, financial reporting
- **Compliance:** AIS tracking, vessel documentation, regulatory reports

**Real-world example:**
1. Client asks for a ship to carry cargo
2. Company responds with price quote (Chartering module)
3. Client accepts, contract signed (Contracts module)
4. Ship sails (Voyages module)
5. Port operations happen (Operations module)
6. Invoice generated and paid (Finance module)

---

## System Architecture

### Technology Stack

```
┌─────────────────────────────────────────────────────────────────┐
│                         FRONTEND LAYER                           │
│  React 19 + TypeScript + Vite + Material-UI (SPA)               │
│  http://localhost:5173 (development)                             │
│  34 unit tests (100% passing)                                    │
└─────────────────────────────────────────────────────────────────┘
                              ↕
                    (REST API via HTTP/JSON)
                              ↕
┌─────────────────────────────────────────────────────────────────┐
│                        BACKEND LAYER                             │
│  Laravel 12 + PHP 8.2 + REST API                                │
│  http://localhost:8001 (development)                             │
│  292 tests (121 passing / 41% pass rate)                        │
│  Routes: 160+ API endpoints at /api/v1/*                        │
└─────────────────────────────────────────────────────────────────┘
                              ↕
                    (Eloquent ORM + Queries)
                              ↕
┌─────────────────────────────────────────────────────────────────┐
│                       DATABASE LAYER                             │
│  MySQL 5.7 @ 127.0.0.1:3306                                     │
│  Database: "offshore" (production) or "offshore_test" (tests)   │
│  Tables: 84 tables covering all modules                         │
│  Relations: Companies, Vessels, Voyages, Contracts, Invoices... │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
offshore/
├── backend/                    # Laravel REST API
│   ├── app/
│   │   ├── Models/            # Database models (Voyage, Invoice, etc.)
│   │   ├── Http/
│   │   │   ├── Controllers/   # API endpoints
│   │   │   ├── Requests/      # Input validation
│   │   │   └── Resources/     # JSON response formatting
│   │   ├── Services/          # Business logic (InvoiceService, etc.)
│   │   ├── Enums/             # Constants (UserRole, Permission, etc.)
│   │   └── Policies/          # Authorization rules
│   ├── database/
│   │   ├── migrations/        # Database schema versions
│   │   └── seeders/           # Test data generators
│   ├── routes/api.php         # API endpoint definitions (160+ routes)
│   ├── tests/                 # 292 test files
│   │   ├── Unit/              # Business logic tests (68 passing)
│   │   ├── Feature/           # API endpoint tests (53 passing)
│   │   └── TestCase.php       # Test helpers
│   └── phpstan.neon           # Static analysis config
│
├── frontend/                   # React SPA
│   ├── src/
│   │   ├── pages/             # Page components (DashboardPage, etc.)
│   │   ├── components/        # Reusable components (DataGrid, etc.)
│   │   ├── hooks/             # Custom React hooks
│   │   ├── types/             # TypeScript interfaces
│   │   ├── api.ts             # HTTP client
│   │   └── main.tsx           # App entry point
│   ├── tests/                 # 34 unit tests (100% passing)
│   ├── e2e/                   # Browser tests (2/10 passing)
│   └── vite.config.ts         # Build configuration
│
├── docs/                       # Documentation (22 files)
│   ├── 03-ARCHITECTURE.md     # System design
│   ├── 04-DATABASE-SCHEMA.md  # Table definitions
│   ├── 06-API.md              # API documentation
│   ├── 07-BUSINESS-RULES.md   # Business logic rules
│   ├── 12-DEPLOYMENT.md       # Production deployment
│   ├── BACKUP-SETUP.md        # Backup procedures
│   ├── DEPLOYMENT-RUNBOOK.md  # On-call procedures
│   └── ...
│
└── uploads/                    # Document storage (private, not web-served)
```

### User Roles & Permissions

The system has 9 user roles with specific permissions:

```
SuperAdmin
└─ All permissions (full system access)

Management          Chartering           Operations
├─ View reports     ├─ Manage enquiries  ├─ Manage voyages
├─ Manage users     ├─ Create offers     ├─ Record port activities
├─ View invoices    ├─ Create contracts  ├─ Track fuel/bunkers
└─ Audit logs       ├─ Estimations      └─ Verify captain reports

Finance              Commercial           MarineOps
├─ Create invoices  ├─ View reports      ├─ View vessel status
├─ Process payments ├─ Create contracts  ├─ Manage CII compliance
├─ Balancing        ├─ View invoices     └─ Track AIS positions
└─ Export reports   └─ Export data

Accounts             ReadOnly
├─ View invoices    └─ View-only access to all modules
├─ Record payments   (no create/delete permissions)
└─ View aging

MarineOperations (same as MarineOps)
ReadOnly           (same as above)
```

---

## Core Business Flows

### Flow 1: Chartering & Contract (Client asks for a ship)

```
┌─────────────────────────────────────────────────────────────────┐
│ STEP 1: ENQUIRY                                                 │
│ Client: "I need a ship for cargo from Singapore to Rotterdam"   │
│ User creates Enquiry with:                                      │
│  - Business type (voyage_charter / trip_charter)                │
│  - Required capacity (tonnage)                                   │
│  - Route (ports, dates)                                         │
│  - Vessel requirements                                          │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 2: ESTIMATION                                              │
│ Internal team: "What does this voyage cost?"                    │
│ User creates Estimation scenario:                              │
│  - Select vessel that fits                                      │
│  - Calculate fuel costs (bunker prices)                         │
│  - Calculate port costs (pilotage, wharfage)                   │
│  - Calculate crew costs                                         │
│  - System calculates: TOTAL COST                                │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 3: OFFER                                                   │
│ Sales: "Here's our price for your cargo"                        │
│ User creates Offer from Estimation:                            │
│  - Copy estimated costs                                         │
│  - Add margin/profit                                            │
│  - Set final price (Freight rate)                              │
│  - Send to client (email integration)                           │
│ Client accepts/rejects offer                                    │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 4: FIXTURE & CONTRACT                                      │
│ Legal: "Draw up the charter party"                              │
│ User creates Fixture (spot market booking):                     │
│  - Lock in vessel + dates                                       │
│  - Record key terms (rate, duration, cancellation clause)       │
│  - Create Contract (legal document)                             │
│  - Both signed in system (audit trail)                          │
└─────────────────────────────────────────────────────────────────┘
                              ↓
           ✓ Contract is now ACTIVE - Ready for voyage
```

### Flow 2: Voyage Execution (Ship sails and operates)

```
┌─────────────────────────────────────────────────────────────────┐
│ STEP 1: VOYAGE CREATED                                          │
│ Operations: "The ship is sailing now"                           │
│ User creates Voyage from Contract:                             │
│  - Vessel assigned                                              │
│  - Cargo details                                                │
│  - Expected ports & dates                                       │
│  - Revenue estimate (from contract)                             │
│  - Expense budget (bunker, port, crew)                         │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 2: PORT OPERATIONS                                         │
│ For each port call:                                             │
│                                                                 │
│  ├─ Port DA (Disbursement Account)                             │
│  │  - Record: arrival time, departure time                      │
│  │  - Pilotage cost, wharfage fee, agency fee                  │
│  │  - System auto-calculates totals                             │
│  │                                                              │
│  ├─ Laytime Calculation                                         │
│  │  - When is ship "on-hire" vs "off-hire"?                    │
│  │  - Port waiting → paid by voyage                             │
│  │  - Demurrage (delay penalty) for client                      │
│  │  - Despatch (early finish bonus) to client                   │
│  │                                                              │
│  └─ Captain Report                                              │
│     - Fuel consumption (bunkers used)                           │
│     - Crew changes, incidents                                   │
│     - Weather, navigation notes                                 │
│     - System records in voyage history                          │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 3: VOYAGE COMPLETION                                       │
│ Operations: "Ship arrived at destination"                       │
│ User completes Voyage:                                          │
│  - Final port call processed                                    │
│  - All revenue recorded (including demurrage/despatch)          │
│  - All expenses recorded (fuel, ports, crew)                    │
│  - System calculates VOYAGE PROFIT/LOSS                         │
│  - Revenue vs actual cost reconciliation                        │
└─────────────────────────────────────────────────────────────────┘
                              ↓
           ✓ Voyage complete - Ready for invoicing
```

### Flow 3: Invoicing & Payment (Getting paid)

```
┌─────────────────────────────────────────────────────────────────┐
│ STEP 1: CREATE INVOICE                                          │
│ Finance: "Bill the client"                                      │
│ User creates Invoice:                                           │
│  - Customer (cargo owner)                                       │
│  - Invoice type: freight, demurrage, other                      │
│  - Line items (auto-populated from voyage)                      │
│  - Amount in USD (multi-currency support)                       │
│  - Due date (payment terms)                                     │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 2: INVOICE APPROVAL WORKFLOW                               │
│ Finance submits → Manager approves → Controller issues          │
│  - Submit: Marks invoice as "submitted" (read-only)             │
│  - Approve: Finance manager reviews & approves                  │
│  - Issue: Controller releases invoice (sent to client)          │
│  - Audit trail: Who, when, what changed                         │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 3: PAYMENT RECEIVED                                        │
│ Accounts: "Payment received from client"                        │
│ User records Payment:                                           │
│  - Amount received (e.g., $50,000)                              │
│  - Payment date & method (wire, check, etc.)                    │
│ System: ALLOCATES payment to invoice                            │
│  - Can partially pay (multiple invoices)                        │
│  - Tracks: paid amount vs invoice amount                        │
│  - Updates invoice status → PAID                                │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ STEP 4: FINANCIAL REPORTING                                     │
│ Finance: "Show me the money"                                    │
│ System generates reports:                                       │
│  - Aging Report: Which invoices are overdue?                   │
│  - Balancing: Do receivables match profit & loss?              │
│  - Voyage P&L: Profit by voyage                                 │
│  - Customer profitability: Best customers by margin             │
└─────────────────────────────────────────────────────────────────┘
                              ↓
           ✓ Cash collected, reports generated
```

---

## How to Set Up Locally

### Prerequisites

```bash
# Check you have these installed
php -v                  # Should be 8.2+
node -v                 # Should be 20+
npm -v                  # Should be 10+
mysql --version         # Should be 5.7+
```

### Step 1: Clone & Install Dependencies

```bash
cd /Applications/ServBay/www/offshore

# Backend
cd backend
composer install

# Frontend
cd ../frontend
npm install
```

### Step 2: Configure Environment

```bash
# Backend configuration
cd backend
cp .env.example .env

# Edit .env and set:
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=offshore
# DB_USERNAME=root
# DB_PASSWORD=your_mysql_password

php artisan key:generate
```

### Step 3: Create Databases

```bash
# Using MySQL CLI
mysql -h 127.0.0.1 -u root -p <<EOF
CREATE DATABASE offshore;
CREATE DATABASE offshore_test;
EOF

# Or using phpMyAdmin (easier)
```

### Step 4: Run Migrations & Seeders

```bash
cd backend

# Create all tables
php artisan migrate

# Populate with test data (roles, permissions, sample data)
php artisan db:seed

# Result: System ready with demo data
```

### Step 5: Start Servers

**Terminal 1 - Backend:**
```bash
cd /Applications/ServBay/www/offshore/backend
php artisan serve --port=8001
# Backend running at http://localhost:8001
```

**Terminal 2 - Frontend:**
```bash
cd /Applications/ServBay/www/offshore/frontend
npm run dev
# Frontend running at http://localhost:5173
```

### Step 6: Login

Open http://localhost:5173 in browser:

```
Email:    admin@offshore.local
Password: Admin@12345
```

You're now in the system as a SuperAdmin with full access.

---

## Step-by-Step Learning Path

### Week 1: Understand the System

**Day 1-2: Read Documentation**
- [ ] Read: `docs/03-ARCHITECTURE.md` (30 min)
- [ ] Read: `docs/04-DATABASE-SCHEMA.md` (1 hour)
- [ ] Read: `docs/07-BUSINESS-RULES.md` (1 hour)

**Day 3-4: Explore the Database**
```bash
cd backend

# Open MySQL and explore tables
mysql -h 127.0.0.1 -u root -p offshore

# See all tables
SHOW TABLES;

# Explore table structures
DESCRIBE voyages;
DESCRIBE invoices;
DESCRIBE contracts;

# See sample data
SELECT * FROM companies LIMIT 5;
SELECT * FROM vessels LIMIT 5;
SELECT * FROM voyages LIMIT 5;
```

**Day 5: Explore the Codebase**
- [ ] Read: `backend/routes/api.php` (160 API routes defined)
- [ ] Read: `backend/app/Models/Voyage.php` (see Model relationships)
- [ ] Read: `backend/app/Services/Finance/InvoiceService.php` (see business logic)

### Week 2: Run Tests & See Things Work

**Day 1-2: Run Test Suite**

```bash
cd backend

# Run all tests
php artisan test

# Run by module
php artisan test tests/Unit/Services/       # Business logic tests
php artisan test tests/Feature/Admin/       # Admin API tests
php artisan test tests/Feature/Finance/     # Finance workflow tests

# Run specific test file
php artisan test tests/Feature/Admin/RoleManagementTest.php
```

**Day 3: Understand Test Structure**

```bash
# Look at a test file
cat tests/Feature/Admin/RoleManagementTest.php

# Key patterns:
# 1. setUp() - Create test data
# 2. $this->actingAsRole() - Simulate logged-in user
# 3. $this->getJson('/api/v1/...') - Make API call
# 4. ->assertOk() - Check response
```

**Day 4-5: Run Frontend Tests**

```bash
cd frontend

# Run unit tests
npm test

# Watch mode (re-run on file change)
npm test -- --watch

# Build for production
npm run build
```

### Week 3: Use the Application

**Day 1: Login & Explore Dashboard**

1. Start backend & frontend servers
2. Open http://localhost:5173
3. Login as admin@offshore.local / Admin@12345
4. Explore:
   - Dashboard (live KPIs)
   - Vessels list
   - Companies list
   - Reports

**Day 2: Create Test Data**

1. Create a new Vessel:
   - Go to Masters → Vessels
   - Click "Add Vessel"
   - Fill: Name, IMO, DWT, vessel type
   - Save

2. Create a new Company (Client):
   - Go to Masters → Companies
   - Click "Add Company"
   - Fill: Name, legal entity, country
   - Save

**Day 3: Create an Enquiry**

1. Go to Chartering → Enquiries
2. Click "New Enquiry"
3. Fill:
   - Business type: "Voyage Charter"
   - Required tonnage: 25,000
   - Route: Singapore → Rotterdam
   - Expected dates
4. Save
5. Note the enquiry ID (e.g., EQ-2026-001)

**Day 4: Create Estimation**

1. Go to Chartering → Estimations
2. Click "New Estimation"
3. Link to enquiry created above
4. Select vessel (from step 1)
5. Enter costs:
   - Bunker (fuel): $150,000
   - Port costs: $80,000
   - Crew: $20,000
6. System shows: Total cost = $250,000
7. Save & note margin: If freight rate is $300,000, profit = $50,000

**Day 5: Create Contract**

1. Go to Chartering → Fixtures
2. Create Fixture from Estimation
3. Convert to Contract
4. Contract shows all terms (rate, duration, cargo details)
5. Mark as Approved (workflow: Draft → Approved → Active)

### Week 4: Deep Dive into Voyages & Finance

**Day 1: Create a Voyage**

1. Go to Operations → Voyages
2. Click "New Voyage"
3. Link to Contract created in Week 3
4. Fill voyage details:
   - Vessel, cargo, ports, dates
   - Expected revenue ($300,000 from contract rate)
   - Expense budget ($250,000 estimated)
5. Save

**Day 2: Record Port Operations**

1. Find voyage (e.g., V-2026-001)
2. Record Port Calls:
   - Arrival time, departure time
   - Port DA (costs): $15,000
   - Laytime: Calculate demurrage if delayed
3. Record Captain Report:
   - Fuel consumed: 120 tonnes
   - Incidents: None
   - Crew changes: 2 crew changed

**Day 3: Complete Voyage**

1. All port calls recorded
2. Click "Complete Voyage"
3. System calculates:
   - Total revenue: $300,000 (contract) + $5,000 (demurrage) = $305,000
   - Total costs: $250,000 (estimated) + $30,000 (actual port costs) = $280,000
   - **VOYAGE PROFIT: $25,000**

**Day 4: Create Invoice**

1. Go to Commercial → Invoices
2. Click "New Invoice"
3. Link to voyage (auto-populates amounts)
4. Customer: Client company
5. Amount: $305,000 (revenue)
6. Terms: Net 30 days
7. Workflow: Draft → Submit → Approve → Issue
8. Issued invoice visible to customer

**Day 5: Record Payment**

1. Go to Commercial → Payments
2. Record payment received: $305,000
3. Allocate to invoice (mark as PAID)
4. Generate Aging Report: Show paid vs outstanding invoices

---

## Testing Guide

### Running All Tests

```bash
cd backend

# Run entire test suite (takes ~4 minutes)
php artisan test

# Run with coverage report
php artisan test --coverage

# Run specific test file
php artisan test tests/Feature/Admin/RoleManagementTest.php

# Run tests matching pattern
php artisan test --filter=invoice
```

### Understanding Test Output

```
PASS  Tests\Unit\Services\Finance\AgingServiceTest
  ✓ buckets invoices by days overdue
  ✓ groups by customer and sorts by total descending
  ✓ fully paid invoices are excluded

FAIL  Tests\Feature\Operations\VoyageTest
  ⨯ voyage completion calculates profit correctly
  Expected: $25,000, Got: $24,900

Tests: 121 passed, 171 failed
```

### Writing Your Own Test

```php
// File: tests/Feature/MyFeatureTest.php

<?php
namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyFeatureTest extends TestCase
{
    use RefreshDatabase; // Start with clean database

    public function test_create_invoice(): void
    {
        // 1. Setup: Create test data
        $user = $this->userWithRole(UserRole::Finance);
        $company = Company::factory()->create();
        
        // 2. Act: Make API call
        $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $company->id,
            'amount' => 10000,
            'due_date' => '2026-11-04',
        ]);
        
        // 3. Assert: Check response
        $response->assertCreated(); // Status 201
        $response->assertJsonPath('data.amount', 10000);
        
        // 4. Assert: Check database
        $this->assertDatabaseHas('invoices', [
            'customer_id' => $company->id,
        ]);
    }
}
```

### Running Frontend Tests

```bash
cd frontend

# Run all tests
npm test

# Run with coverage
npm test -- --coverage

# Run tests matching pattern
npm test -- --testNamePattern="Dashboard"

# Watch mode (re-run on change)
npm test -- --watch
```

### Manual Testing (Browser)

```
1. Start frontend at http://localhost:5173
2. Login as admin@offshore.local / Admin@12345
3. Test each module:
   - Masters: Create vessel, company, port
   - Chartering: Create enquiry, offer, contract
   - Operations: Create voyage, port calls
   - Finance: Create invoice, record payment
4. Test permissions:
   - Login as different role (e.g., Finance user)
   - Try accessing admin pages (should get 403)
   - Try creating invoice (should work)
```

---

## Module Deep Dives

### Module 1: Masters (Reference Data)

**Purpose:** Define base entities that other modules reference

**Data managed:**
- Vessels (ships): Properties, registration, tracking
- Companies (people): Customers, charterers, agents
- Ports: Location data, anchorage, berth info
- Currencies: Exchange rates for multi-currency transactions
- Distances: Route planning, sea distances

**Key APIs:**
```
GET /api/v1/vessels              # List all vessels
POST /api/v1/vessels             # Create vessel
GET /api/v1/vessels/{id}         # Get vessel details
PUT /api/v1/vessels/{id}         # Update vessel
DELETE /api/v1/vessels/{id}      # Delete vessel

GET /api/v1/companies            # List companies
POST /api/v1/companies           # Create company
GET /api/v1/companies/lookup     # Search by name
```

**Database:**
```
vessels table:
  - id, code, name, imo_number
  - dwt (deadweight), gt (gross tonnage)
  - vessel_type (bulker, tanker, etc.)
  - flag (country of registration)
  - built_year, deleted_at

companies table:
  - id, code, legal_name, normalized_name
  - country, address, phone, email
  - role (charterer, agent, shipper, etc.)
```

### Module 2: Chartering (Quote → Offer → Contract)

**Purpose:** Manage the sales process from enquiry to signed contract

**Workflow:**
1. **Enquiry**: Client request → "I need a ship"
2. **Estimation**: Internal cost calculation → "It costs $X"
3. **Offer**: Quote to client → "We quote $Y price"
4. **Fixture**: Spot market booking → "Vessel locked in"
5. **Contract**: Legal agreement → "Signed charter party"

**Key APIs:**
```
POST /api/v1/enquiries           # Create client enquiry
GET /api/v1/enquiries/{id}/status # Check status (open/fixed/lost)
POST /api/v1/enquiries/{id}/status # Change status

POST /api/v1/estimations        # Create cost estimation
GET /api/v1/estimations/{id}/scenarios/{scenario}
POST /api/v1/estimations/{id}/approve

POST /api/v1/offers             # Create price quote
POST /api/v1/offers/{id}/send   # Send to client

POST /api/v1/contracts          # Create charter party
POST /api/v1/contracts/{id}/approve # Manager approval
```

### Module 3: Operations (Voyage Execution)

**Purpose:** Record what happens when a ship sails

**Events tracked:**
- Port calls (arrival, departure, costs)
- Laytime (when ship is on-hire vs off-hire)
- Bunkers (fuel consumption and purchases)
- Crew changes, captain reports
- Offshore activities (if applicable)
- AIS tracking (vessel GPS positions)

**Key APIs:**
```
POST /api/v1/voyages            # Create voyage from contract
POST /api/v1/voyages/{id}/complete # Finish voyage

POST /api/v1/voyages/{id}/port-calls  # Record port call
GET /api/v1/port-das            # View port disbursement accounts

POST /api/v1/laytime-calculations  # Calculate waiting time/demurrage
POST /api/v1/captain-reports       # Record captain report
```

### Module 4: Finance (Money Tracking)

**Purpose:** Manage invoicing, payments, and financial reporting

**Process:**
1. Create Invoice (bill customer)
2. Approve & Issue (authorize & send)
3. Customer pays
4. Allocate payment (apply to invoice)
5. Generate reports (aging, P&L, cash flow)

**Key APIs:**
```
POST /api/v1/invoices           # Create invoice
POST /api/v1/invoices/{id}/submit   # Workflow: submit
POST /api/v1/invoices/{id}/approve  # Workflow: approve
POST /api/v1/invoices/{id}/issue    # Workflow: issue

POST /api/v1/payments           # Record payment received
POST /api/v1/payments/{id}/allocate # Allocate to invoice

GET /api/v1/reports/aging       # Which invoices are overdue
GET /api/v1/reports/balancing   # Receivables vs revenue
```

---

## API Testing

### Method 1: Using cURL (Command Line)

```bash
# 1. Login and get token
curl -X POST http://localhost:8001/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@offshore.local",
    "password": "Admin@12345"
  }'

# Response includes "token": "abc123..."

# 2. List vessels using token
curl http://localhost:8001/api/v1/vessels \
  -H "Authorization: Bearer abc123..."

# 3. Create vessel
curl -X POST http://localhost:8001/api/v1/vessels \
  -H "Authorization: Bearer abc123..." \
  -H "Content-Type: application/json" \
  -d '{
    "code": "SHIP001",
    "name": "MV Test Vessel",
    "imo_number": "1234567",
    "dwt": 25000,
    "vessel_type": "bulk_carrier"
  }'
```

### Method 2: Using Postman

1. Download Postman (free at postman.com)
2. Create new request:
   - Method: POST
   - URL: `http://localhost:8001/api/v1/auth/login`
   - Body (raw JSON):
     ```json
     {
       "email": "admin@offshore.local",
       "password": "Admin@12345"
     }
     ```
   - Send
3. Copy `token` from response
4. Create new request:
   - Method: GET
   - URL: `http://localhost:8001/api/v1/vessels`
   - Headers: Add `Authorization: Bearer YOUR_TOKEN`
   - Send

### Method 3: Using the Frontend

1. Open Developer Tools (F12 in Chrome)
2. Go to Network tab
3. Refresh page
4. See all API calls being made
5. Click each request to see:
   - Request body
   - Response data
   - Status code (200, 404, 500, etc.)

### Common API Response Patterns

```json
// Success (200 OK)
{
  "data": {
    "id": 1,
    "name": "MV Test Ship",
    ...
  }
}

// List (with pagination)
{
  "data": [ {...}, {...} ],
  "meta": {
    "total": 150,
    "per_page": 15,
    "current_page": 1
  }
}

// Error (400 Bad Request)
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name field is required"]
  }
}

// Unauthorized (401)
{
  "message": "Unauthenticated."
}

// Forbidden (403)
{
  "message": "This action is unauthorized."
}

// Not Found (404)
{
  "message": "Not found."
}
```

---

## Quick Reference: Important Files

| File | Purpose | Lines |
|------|---------|-------|
| `backend/routes/api.php` | All API endpoints (160+ routes) | 243 |
| `backend/app/Models/Voyage.php` | Voyage database model | ~80 |
| `backend/app/Services/Finance/InvoiceService.php` | Invoice business logic | ~500 |
| `backend/tests/Feature/Admin/RoleManagementTest.php` | Admin test examples | ~80 |
| `frontend/src/pages/DashboardPage.tsx` | Dashboard component | ~150 |
| `frontend/src/api.ts` | HTTP client configuration | ~50 |
| `docs/04-DATABASE-SCHEMA.md` | All 84 tables defined | ~400 |
| `docs/07-BUSINESS-RULES.md` | Business logic rules | ~600 |

---

## Troubleshooting

**"Cannot connect to database"**
```bash
# Check MySQL is running
mysql -h 127.0.0.1 -u root -p

# Check .env settings
cat backend/.env | grep DB_

# Verify database exists
mysql -e "SHOW DATABASES;"
```

**"Port 8001 already in use"**
```bash
# Use different port
php artisan serve --port=8002

# Or kill process using 8001
lsof -i :8001
kill -9 <PID>
```

**"Tests failing with 'Database does not exist'"**
```bash
cd backend
php artisan migrate:refresh --env=testing
php artisan test
```

**"Frontend shows blank page"**
```bash
# Clear cache and restart
cd frontend
rm -rf node_modules/.vite
npm run dev
```

**"TypeScript errors in IDE"**
```bash
# Regenerate types
cd frontend
npm run type-check
```

---

## Next Steps

1. **Complete local setup** (2-3 hours)
   - Follow "How to Set Up Locally" section above

2. **Run tests** (30 minutes)
   - `cd backend && php artisan test`
   - Understand test patterns

3. **Explore codebase** (2-3 hours)
   - Read Model classes
   - Read Service classes
   - Understand database relationships

4. **Create test data in UI** (1-2 hours)
   - Create vessel, company
   - Create enquiry → offer → contract
   - Create voyage → invoice

5. **Make your first API call** (1 hour)
   - Use cURL or Postman
   - Login and list vessels
   - Create a new record

6. **Write a test** (1-2 hours)
   - Copy existing test as template
   - Add your own test case
   - Run: `php artisan test`

---

**Questions?** Check the troubleshooting section or read the docs in `/Applications/ServBay/www/offshore/docs/`
