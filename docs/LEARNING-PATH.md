# Complete Learning Path - From Zero to Expert

**For:** Developers learning this system from scratch  
**Duration:** 4 weeks (part-time, 10-15 hours per week)  
**Goal:** Understand architecture, code, database, testing, and API

---

## Week 1: Foundations (Understanding What This System Is)

### Day 1: Business Context (1 hour)

**Goal:** Understand what the system does in real-world terms

**Reading:**
- [ ] Read: `docs/README.md` (5 min)
- [ ] Read: `docs/SYSTEM-OVERVIEW.md` § "What This System Does" (10 min)
- [ ] Read: `docs/SYSTEM-OVERVIEW.md` § "Core Business Flows" (20 min)

**Concepts to understand:**
- Chartering: Renting out ships
- Voyage: The journey from port A to port B
- Invoicing: Getting paid for the voyage
- Operations: Everything that happens during the voyage

**Self-check:**
- [ ] Can I explain what an "Enquiry" is?
- [ ] Can I explain what a "Fixture" is?
- [ ] Can I explain the voyage profit calculation?

---

### Day 2: Architecture Overview (1.5 hours)

**Goal:** Understand how the system is built

**Reading:**
- [ ] Read: `docs/03-ARCHITECTURE.md` (30 min)
- [ ] Read: `docs/SYSTEM-OVERVIEW.md` § "System Architecture" (20 min)

**Visualize:**
```
Browser (React/UI)
        ↓
    HTTP/JSON
        ↓
Backend API (Laravel)
        ↓
  Eloquent ORM
        ↓
Database (MySQL)
        ↓
84 tables (voyages, invoices, etc.)
```

**Hands-on:**
```bash
cd /Applications/ServBay/www/offshore/backend

# See all routes
php artisan route:list | head -30

# Count routes
php artisan route:list | wc -l
# Expected: ~160 API routes

# See database migrations
ls database/migrations/ | head -10
```

**Self-check:**
- [ ] Can I explain the 3-layer architecture?
- [ ] Can I name 5 API endpoints?
- [ ] Do I know where the API routes are defined?

---

### Day 3: Database Schema (1.5 hours)

**Goal:** Understand the database structure

**Reading:**
- [ ] Read: `docs/04-DATABASE-SCHEMA.md` (30 min)

**Explore the database:**
```bash
mysql -h 127.0.0.1 -u root -p offshore

# See all tables (should be 84)
SHOW TABLES;

# Look at important tables
DESCRIBE voyages;
DESCRIBE invoices;
DESCRIBE contracts;
DESCRIBE companies;
DESCRIBE vessels;

# See relationships
SELECT COLUMN_NAME, REFERENCED_TABLE_NAME 
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
WHERE TABLE_NAME = 'voyages' AND REFERENCED_TABLE_NAME IS NOT NULL;
```

**Key tables to know:**
- `voyages` - Each ship journey
- `invoices` - Bills sent to customers
- `contracts` - Charter party agreements
- `companies` - Customers, agents, shippers
- `vessels` - Ships in the fleet
- `port_calls` - When ship visits a port
- `captain_reports` - Reports from ship's crew

**Self-check:**
- [ ] Can I name 5 important tables?
- [ ] Can I query voyage data from the database?
- [ ] Can I understand table relationships?

---

### Day 4: Business Rules & Workflows (1.5 hours)

**Goal:** Understand how the business logic works

**Reading:**
- [ ] Read: `docs/07-BUSINESS-RULES.md` (45 min)
- [ ] Read: `docs/SYSTEM-OVERVIEW.md` § "Core Business Flows" (20 min)

**Key workflows:**
1. **Chartering:** Enquiry → Estimation → Offer → Fixture → Contract
2. **Voyage:** Voyage Created → Port Operations → Completion → Invoice
3. **Finance:** Create Invoice → Approve → Issue → Payment → Reporting

**Understand:**
- Why is approval workflow important?
- Who can approve what?
- What triggers invoice creation?
- How is voyage profit calculated?

**Self-check:**
- [ ] Can I explain the chartering flow?
- [ ] Can I explain the voyage flow?
- [ ] Can I explain the invoicing flow?

---

### Day 5: Setup Your Local Environment (2 hours)

**Goal:** Get the system running on your machine

**Steps:**
```bash
# Navigate to project
cd /Applications/ServBay/www/offshore

# Follow QUICKSTART.md Steps 1-4
# This takes about 20 minutes

# Backend setup
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Database setup
mysql -h 127.0.0.1 -u root -p -e "
CREATE DATABASE IF NOT EXISTS offshore;
"

cd backend
php artisan migrate --seed

# Start servers
# Terminal 1: cd backend && php artisan serve --port=8001
# Terminal 2: cd frontend && npm run dev

# Login at http://localhost:5173
# admin@offshore.local / Admin@12345
```

**Test it works:**
- [ ] Backend server running at :8001
- [ ] Frontend running at :5173
- [ ] Can login to system
- [ ] Can see dashboard
- [ ] Can navigate to different pages

---

## Week 2: Code Exploration (Reading the Code)

### Day 1: Models & Database Structure (1.5 hours)

**Goal:** Understand how code represents database data

**Read these files:**
```bash
cd /Applications/ServBay/www/offshore/backend

cat app/Models/Voyage.php      # ~80 lines
cat app/Models/Invoice.php     # ~70 lines
cat app/Models/Contract.php    # ~80 lines
```

**Key concepts:**
- Models are PHP classes representing database tables
- Each column becomes a property
- Relationships (hasMany, belongsTo) represent foreign keys
- Timestamps (created_at, updated_at) auto-tracked

**Example: Understanding Voyage Model**
```php
class Voyage extends Model
{
    // Properties of this voyage
    protected $fillable = ['vessel_id', 'contract_id', 'status', ...];
    
    // This voyage belongs to one vessel
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
    
    // This voyage has many port calls
    public function portCalls(): HasMany
    {
        return $this->hasMany(PortCall::class);
    }
}
```

**Hands-on:**
```bash
# See all models
ls app/Models/ | head -20

# Models we care about most:
# Voyage, Invoice, Contract, Enquiry, Offer
# Estimation, Company, Vessel, PortCall
# Captain Report, Payment, CaptainReport

# Open each and look for:
# 1. protected $fillable = [...] - what properties?
# 2. public function xyz() - what relationships?
# 3. public function abc() - what methods?
```

**Self-check:**
- [ ] Can I explain what a Model is?
- [ ] Can I read a Model and understand its relationships?
- [ ] Do I know which table each Model represents?

---

### Day 2: Services & Business Logic (1.5 hours)

**Goal:** Understand where business calculations happen

**Read these files:**
```bash
cd /Applications/ServBay/www/offshore/backend

cat app/Services/Finance/InvoiceService.php     # Invoice creation logic
cat app/Services/Chartering/EstimationService.php # Cost calculation
cat app/Services/Operations/VoyageService.php   # Voyage management
```

**What is a Service?**
- Class that contains business logic
- Takes input, does calculations, returns output
- Example: `InvoiceService::create()` creates an invoice

**Example: How Invoices are Created**
```php
// app/Services/Finance/InvoiceService.php
public function create(array $data, User $actor): Invoice
{
    // 1. Check permission
    $this->authorize($actor, Permission::InvoicesCreate);
    
    // 2. Validate data
    // (check amounts, dates, customer exists)
    
    // 3. Create invoice in database
    $invoice = Invoice::create($data);
    
    // 4. Record in audit log
    activity()
        ->causedBy($actor)
        ->performedOn($invoice)
        ->log('created');
    
    return $invoice;
}
```

**Key services to understand:**
- `InvoiceService` - Create, approve, issue invoices
- `EstimationService` - Calculate voyage costs
- `VoyageService` - Create, complete voyages
- `PaymentService` - Record payments, allocate to invoices

**Hands-on:**
```bash
# Find all services
find app/Services -name "*.php" | head -10

# Look at a service file
cat app/Services/Finance/InvoiceService.php | head -50

# Understand: What methods does it have?
grep "public function" app/Services/Finance/InvoiceService.php
```

**Self-check:**
- [ ] Can I explain what a Service is?
- [ ] Can I trace a business operation through code?
- [ ] Do I understand how authorization works?

---

### Day 3: Controllers & API Endpoints (1.5 hours)

**Goal:** Understand how API requests are handled

**Read these files:**
```bash
cd /Applications/ServBay/www/offshore/backend

cat app/Http/Controllers/Api/V1/Masters/VesselController.php    # 80-100 lines
cat app/Http/Controllers/Api/V1/Finance/InvoiceController.php   # 100-150 lines
```

**What is a Controller?**
- Class that handles HTTP requests
- Receives input from frontend
- Calls Service to do business logic
- Returns JSON response

**Example: How an API Call is Handled**
```php
// File: app/Http/Controllers/Api/V1/Finance/InvoiceController.php
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}
    
    // POST /api/v1/invoices
    public function store(SaveInvoiceRequest $request): JsonResponse
    {
        // 1. Request object validates input (checked via Request class)
        
        // 2. Call Service to create invoice
        $invoice = $this->invoices->create(
            $request->validated(),
            auth()->user()
        );
        
        // 3. Return formatted response
        return response()->json([
            'data' => new InvoiceResource($invoice),
        ], 201);
    }
}
```

**API Request Flow:**
```
Browser sends:  POST /api/v1/invoices
                with JSON data

↓

Laravel Router:
matches route, calls InvoiceController::store()

↓

Controller:
1. Validates input
2. Calls InvoiceService
3. Service creates invoice
4. Controller formats response

↓

Browser receives:
{
  "data": {
    "id": 123,
    "customer_id": 45,
    "amount": 50000,
    ...
  }
}
```

**Hands-on:**
```bash
# See all controllers
ls app/Http/Controllers/Api/V1/

# Look at a controller
cat app/Http/Controllers/Api/V1/Masters/VesselController.php

# Find controller methods
grep "public function" app/Http/Controllers/Api/V1/Masters/VesselController.php
# You'll see: index, store, show, update, destroy

# These match REST conventions:
# GET    /api/v1/vessels          → index()
# POST   /api/v1/vessels          → store()
# GET    /api/v1/vessels/{id}     → show()
# PUT    /api/v1/vessels/{id}     → update()
# DELETE /api/v1/vessels/{id}     → destroy()
```

**Self-check:**
- [ ] Can I explain the API request flow?
- [ ] Can I find and read a Controller?
- [ ] Do I understand REST conventions (GET, POST, PUT, DELETE)?

---

### Day 4: Permissions & Authorization (1.5 hours)

**Goal:** Understand how access control works

**Read these files:**
```bash
cd /Applications/ServBay/www/offshore/backend

cat app/Enums/Permission.php    # All 133 permissions defined
cat app/Policies/InvoicePolicy.php  # Authorization rules
cat database/seeders/RolesAndPermissionsSeeder.php  # Roles defined
```

**How Authorization Works:**
```
User has Role (e.g., "Finance")
    ↓
Role has Permissions (e.g., "invoices.create", "invoices.approve")
    ↓
When user does action, system checks:
"Does this user have permission to do this?"
    ↓
If yes → allow action
If no → return 403 Forbidden
```

**Permissions in code:**
```php
// In a Service:
private function authorize(User $actor, Permission $permission): void
{
    if (! $actor->can($permission->value)) {
        throw new AuthorizationException('Access denied');
    }
}

// Usage:
public function create(array $data, User $actor): Invoice
{
    $this->authorize($actor, Permission::InvoicesCreate);
    // ... rest of create logic
}
```

**Roles in the system:**
- **SuperAdmin** - Full access
- **Finance** - Create/approve invoices, process payments
- **Operations** - Create voyages, record port operations
- **Commercial** - View reports, create contracts
- (And 5 more...)

**Hands-on:**
```bash
# See all permissions
grep "case " app/Enums/Permission.php | head -20

# See role definitions
cat database/seeders/RolesAndPermissionsSeeder.php

# Understand: Finance role gets which permissions?
grep -A 10 "UserRole::Finance" database/seeders/RolesAndPermissionsSeeder.php
```

**Self-check:**
- [ ] Can I explain how permissions work?
- [ ] Can I name 3 permissions?
- [ ] Can I understand a Policy file?

---

### Day 5: Testing Code (1.5 hours)

**Goal:** Understand how tests verify the code works

**Read these files:**
```bash
cd /Applications/ServBay/www/offshore/backend

cat tests/Feature/Admin/RoleManagementTest.php      # ~80 lines
cat tests/Unit/Services/Finance/AgingServiceTest.php  # ~150 lines
```

**What is a Test?**
- Code that verifies other code works correctly
- Runs automatically
- Catches bugs before production

**Example Test Structure:**
```php
public function test_create_invoice(): void
{
    // 1. SETUP: Create test data
    $user = $this->userWithRole(UserRole::Finance);
    $company = Company::factory()->create();
    
    // 2. ACT: Do the thing
    $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
        'customer_id' => $company->id,
        'amount' => 10000,
        'due_date' => '2026-11-04',
    ]);
    
    // 3. ASSERT: Check it worked
    $response->assertCreated();  // Status 201
    $response->assertJsonPath('data.amount', 10000);
    $this->assertDatabaseHas('invoices', [
        'customer_id' => $company->id,
    ]);
}
```

**Test Types:**
- **Unit Tests** - Test one class in isolation (InvoiceService)
- **Feature Tests** - Test API endpoints (POST /invoices)
- **Integration Tests** - Test multiple parts working together

**Hands-on:**
```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/Admin/RoleManagementTest.php

# Run specific test
php artisan test --filter=test_create_invoice

# See which tests are failing
php artisan test 2>&1 | grep "FAILED" | head -10
```

**Self-check:**
- [ ] Can I read and understand a test?
- [ ] Can I run tests?
- [ ] Can I explain the difference between Unit and Feature tests?

---

## Week 3: Hands-On Development (Building & Testing)

### Day 1: Create Your First Test (2 hours)

**Goal:** Write a test from scratch

**Task:** Write a test that verifies voyage profit calculation

**Steps:**
```bash
cd /Applications/ServBay/www/offshore/backend

# 1. Create test file
cat > tests/Feature/MyVoyageTest.php <<'EOF'
<?php
namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Voyage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyVoyageTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_voyage_profit_calculation(): void
    {
        // Setup
        $user = $this->userWithRole(UserRole::Operations);
        $contract = Contract::factory()->create([
            'freight_rate' => 300000,
        ]);
        
        // Act
        $response = $this->actingAs($user)->postJson('/api/v1/voyages', [
            'contract_id' => $contract->id,
            'status' => 'draft',
        ]);
        
        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('voyages', [
            'contract_id' => $contract->id,
            'status' => 'draft',
        ]);
    }
}
EOF

# 2. Run the test
php artisan test tests/Feature/MyVoyageTest.php

# 3. Expected: PASS ✓
```

**Self-check:**
- [ ] Can I create a test file?
- [ ] Can I run my test?
- [ ] Does my test pass?

---

### Day 2: Modify Code & Test (2 hours)

**Goal:** Change code and verify tests catch issues

**Task:** Change invoice amount validation

**Steps:**
```bash
cd /Applications/ServBay/www/offshore/backend

# 1. Find the validation
grep -r "amount" app/Http/Requests/SaveInvoiceRequest.php

# 2. Current validation: "required|numeric|min:1|max:9999999"

# 3. Change it to reject amounts > 5,000,000
# Edit: app/Http/Requests/SaveInvoiceRequest.php
# Change: 'amount' => 'required|numeric|min:1|max:5000000'

# 4. Run tests
php artisan test tests/Feature/Finance/

# 5. Some tests should now FAIL if they try to create invoices > 5M
```

**Self-check:**
- [ ] Did I find the validation rule?
- [ ] Did I change it?
- [ ] Did tests fail as expected?
- [ ] Did I revert the change (git checkout)?

---

### Day 3: Create Test Data & Run Queries (2 hours)

**Goal:** Use artisan tinker to explore the database

**Steps:**
```bash
php artisan tinker

# 1. See all voyages
$voyages = \App\Models\Voyage::all();
$voyages->count();  # How many?

# 2. See a specific voyage
$v = \App\Models\Voyage::first();
$v->id, $v->vessel_id, $v->contract_id  # View properties

# 3. See relationships
$v->vessel->name  # Get vessel name for this voyage
$v->contract->freight_rate  # Get contract rate

# 4. Get invoices for a voyage
$invoices = $v->invoices;  # All invoices for voyage

# 5. Calculate totals
$v->invoices->sum('amount');  # Total invoice amount

# 6. Create test data
$company = \App\Models\Company::factory()->create([
    'legal_name' => 'My Test Company',
]);
$company->id  # See the ID

# 7. Exit
exit
```

**Self-check:**
- [ ] Can I access tinker?
- [ ] Can I query the database?
- [ ] Can I traverse relationships?
- [ ] Can I create test data?

---

### Day 4: API Testing with cURL (2 hours)

**Goal:** Test API endpoints from command line

**Steps:**
```bash
# 1. Login and get token
TOKEN=$(curl -s -X POST http://localhost:8001/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@offshore.local",
    "password": "Admin@12345"
  }' | jq -r '.token')

echo $TOKEN  # Should see a long token string

# 2. List vessels
curl -s http://localhost:8001/api/v1/vessels \
  -H "Authorization: Bearer $TOKEN" | jq .

# 3. Create vessel
curl -X POST http://localhost:8001/api/v1/vessels \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "SHIP-NEW-123",
    "name": "My New Vessel",
    "imo_number": "9876543",
    "dwt": 30000,
    "vessel_type": "bulk_carrier"
  }' | jq .

# 4. Get specific vessel
VESSEL_ID=1  # From previous response
curl http://localhost:8001/api/v1/vessels/$VESSEL_ID \
  -H "Authorization: Bearer $TOKEN" | jq .
```

**Self-check:**
- [ ] Can I login via API?
- [ ] Can I get a token?
- [ ] Can I list vessels?
- [ ] Can I create a vessel?

---

### Day 5: Frontend Components (2 hours)

**Goal:** Understand React components

**Read:**
```bash
cd /Applications/ServBay/www/offshore/frontend

cat src/pages/DashboardPage.tsx        # 150 lines
cat src/components/DataGrid.tsx        # 200 lines
```

**Key concepts:**
- Components are React functions returning JSX
- Props are inputs to components
- Hooks like `useState`, `useEffect` manage state
- API calls made with `fetch` or axios

**Example Component:**
```tsx
// Show a list of vessels
function VesselList() {
    const [vessels, setVessels] = useState([]);
    
    useEffect(() => {
        // On mount, fetch vessels from API
        fetch('/api/v1/vessels')
            .then(r => r.json())
            .then(d => setVessels(d.data));
    }, []);
    
    return (
        <table>
            <thead>
                <tr><th>Name</th><th>IMO</th></tr>
            </thead>
            <tbody>
                {vessels.map(v => (
                    <tr key={v.id}>
                        <td>{v.name}</td>
                        <td>{v.imo_number}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
```

**Hands-on:**
```bash
# 1. Open a page in browser DevTools (F12)
# 2. Go to Network tab
# 3. Refresh page
# 4. See API calls being made:
#    - GET /api/v1/dashboard
#    - GET /api/v1/voyages
#    - etc.
# 5. Click each request to see:
#    - Request body
#    - Response data
#    - Status code
```

**Self-check:**
- [ ] Can I read a React component?
- [ ] Can I understand JSX?
- [ ] Can I see API calls in browser DevTools?

---

## Week 4: Advanced Topics & Mastery

### Day 1: Database Migrations (1.5 hours)

**Goal:** Understand how database schema versions are managed

**Read:**
```bash
cd /Applications/ServBay/www/offshore/backend

ls database/migrations/ | head -10
cat database/migrations/2025_01_01_000000_create_users_table.php  # Example
```

**What are Migrations?**
- Version control for database schema
- Each migration is a file with `up()` and `down()` methods
- `up()` - What to do when applying this migration
- `down()` - How to undo this migration

**Example:**
```php
// File: database/migrations/2025_01_10_000000_create_voyages_table.php
public function up(): void
{
    Schema::create('voyages', function (Blueprint $table) {
        $table->id();
        $table->foreignId('contract_id')->constrained();
        $table->foreignId('vessel_id')->constrained();
        $table->string('status')->default('draft');
        $table->decimal('estimated_revenue', 12, 2);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('voyages');
}
```

**Hands-on:**
```bash
# See all migrations
php artisan migrate:status

# Run a single migration
php artisan migrate --step=1

# Rollback last migration
php artisan migrate:rollback --step=1

# Reset and re-run all
php artisan migrate:refresh --seed
```

**Self-check:**
- [ ] Can I find a migration file?
- [ ] Can I understand `up()` and `down()`?
- [ ] Can I run migrations?

---

### Day 2: Policies & Permissions (2 hours)

**Goal:** Deep dive into authorization

**Read:**
```bash
cd /Applications/ServBay/www/offshore/backend

cat app/Policies/VoyagePolicy.php
cat app/Policies/InvoicePolicy.php
```

**How Policies Work:**
```php
// File: app/Policies/VoyagePolicy.php
public function view(User $user, Voyage $voyage): bool
{
    // Allow if user has permission
    return $user->can('voyages.view');
}

public function update(User $user, Voyage $voyage): bool
{
    // Allow if voyage is in draft status AND user has permission
    return $voyage->status === 'draft' && 
           $user->can('voyages.update');
}

public function approve(User $user, Voyage $voyage): bool
{
    // Only finance role can approve
    return $user->hasRole(UserRole::Finance);
}
```

**Using Policies:**
```php
// In a Controller:
$this->authorize('update', $voyage);  // Check policy
$voyage->update($data);  // Proceed if allowed
```

**Hands-on:**
```bash
# 1. Create a test policy
# 2. Test it passes/fails

# Pseudo-code test:
$user = User::factory()->create();
$user->givePermissionTo('voyages.view');

$this->assertTrue($user->can('voyages.view'));
$this->assertFalse($user->can('voyages.update'));
```

**Self-check:**
- [ ] Can I read a Policy?
- [ ] Can I understand authorization logic?
- [ ] Can I write a Policy test?

---

### Day 3: Performance & Optimization (2 hours)

**Goal:** Understand performance considerations

**Key topics:**
1. **N+1 Query Problem**
   ```php
   // BAD: 101 queries (1 for voyages + 100 for vessel for each voyage)
   $voyages = Voyage::all();
   foreach ($voyages as $v) {
       echo $v->vessel->name;  // Query for each voyage!
   }
   
   // GOOD: 1 query with eager loading
   $voyages = Voyage::with('vessel')->get();
   foreach ($voyages as $v) {
       echo $v->vessel->name;  // No extra queries
   }
   ```

2. **Lazy Loading**
   ```php
   // Relationship loaded only when accessed
   $voyage = Voyage::first();
   $name = $voyage->vessel->name;  // Query happens here
   ```

3. **Caching**
   ```php
   // Cache dashboard data for 5 minutes
   $dashboard = Cache::remember('dashboard', 300, function() {
       return $this->calculateDashboard();
   });
   ```

**Read:**
- [ ] `docs/SYSTEM-OVERVIEW.md` § "Performance Benchmarks"
- [ ] Look for `->with()` patterns in controllers

**Self-check:**
- [ ] Can I explain N+1 queries?
- [ ] Can I use eager loading?
- [ ] Can I identify performance issues?

---

### Day 4: Writing Your First Feature (2.5 hours)

**Goal:** Build a complete feature end-to-end

**Task:** Add a new field to Invoice (e.g., "internal_notes")

**Steps:**
1. **Database:** Create migration to add column
   ```bash
   php artisan make:migration add_internal_notes_to_invoices
   # Edit migration to add: $table->text('internal_notes')->nullable();
   php artisan migrate
   ```

2. **Backend:** Update Model & API
   ```php
   // app/Models/Invoice.php
   protected $fillable = [..., 'internal_notes'];  // Add field
   
   // app/Http/Requests/SaveInvoiceRequest.php
   'internal_notes' => 'nullable|string',  // Add validation
   
   // app/Http/Resources/InvoiceResource.php
   'internal_notes' => $this->internal_notes,  // Add to response
   ```

3. **Frontend:** Add input field
   ```tsx
   // In invoice form component
   <TextField
       label="Internal Notes"
       value={formData.internal_notes}
       onChange={(e) => setFormData({
           ...formData,
           internal_notes: e.target.value
       })}
   />
   ```

4. **Test:** Write test to verify
   ```php
   public function test_create_invoice_with_notes(): void
   {
       $response = $this->postJson('/api/v1/invoices', [
           ...,
           'internal_notes' => 'Payment may be delayed',
       ]);
       $response->assertCreated();
       $response->assertJsonPath('data.internal_notes', 'Payment may be delayed');
   }
   ```

5. **Test it works:**
   ```bash
   php artisan test tests/Feature/Finance/InvoiceTest.php
   ```

**Self-check:**
- [ ] Did I create the migration?
- [ ] Did I run the migration?
- [ ] Did I update the Model?
- [ ] Did I add validation?
- [ ] Did I update the Resource?
- [ ] Did I add frontend input?
- [ ] Did my test pass?

---

### Day 5: Mastery & Next Steps (2 hours)

**Goal:** Consolidate learning and identify next areas

**Review:**
- [ ] Can I explain the full architecture?
- [ ] Can I read and understand any Model?
- [ ] Can I read and understand any Service?
- [ ] Can I read and understand any Controller?
- [ ] Can I write a test?
- [ ] Can I create a feature?
- [ ] Can I debug an issue?

**Next Areas to Explore:**
1. **Event-Driven Architecture** - How systems communicate
2. **API Versioning** - /api/v1, /api/v2 strategies
3. **Docker & Deployment** - How to deploy to production
4. **Load Testing** - Performance under stress
5. **Monitoring** - Error tracking, performance monitoring

**Resources:**
- [ ] Read: `docs/12-DEPLOYMENT.md`
- [ ] Read: `docs/DEPLOYMENT-RUNBOOK.md`
- [ ] Explore: `tests/` directory - Read more tests
- [ ] Explore: `app/Services/` - Read more services
- [ ] Explore: `frontend/src/` - Read more components

---

## Summary: Learning Checklist

By end of Week 4, you should be able to:

- [ ] Explain what the system does in business terms
- [ ] Draw the architecture (frontend → backend → database)
- [ ] List all major modules and their purpose
- [ ] Explain a business flow (e.g., chartering to invoicing)
- [ ] Read and understand any database table
- [ ] Read and understand any Model
- [ ] Read and understand any Service
- [ ] Read and understand any Controller
- [ ] Run the test suite
- [ ] Write a test
- [ ] Query the database via tinker
- [ ] Make API calls with cURL
- [ ] Use browser DevTools to see API calls
- [ ] Create a feature (migration + backend + frontend)
- [ ] Deploy locally and test changes

---

## Recommended Reading Order

If you're short on time, read in this order:

1. **QUICKSTART.md** (30 min) - Get running
2. **SYSTEM-OVERVIEW.md** (1.5 hours) - Understand the system
3. **04-DATABASE-SCHEMA.md** (45 min) - Know the data
4. **07-BUSINESS-RULES.md** (1 hour) - Know the logic
5. **Code** - Read Models, Services, Controllers
6. **Tests** - Run and understand tests
7. **Build** - Write your own test and feature

---

**Total Time Investment: 4-6 weeks part-time (10-15 hours/week)**

After this, you'll be ready to:
- Fix bugs
- Add features
- Write tests
- Optimize performance
- Help onboard new developers

**Good luck! 🚀**
