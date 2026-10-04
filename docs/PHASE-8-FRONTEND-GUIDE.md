# Phase 8 Frontend Implementation Guide

**Target:** React developers implementing Bunkers, Port DA, and Laytime UI  
**Prerequisites:** Backend complete and tested (175 tests passing)  
**Current State:** Basic bunkers page exists; Port DA and Laytime need full implementation

---

## Quick Start

### 1. Run the Backend API

```bash
cd backend
php artisan serve --port=8001
```

The API will be available at `http://localhost:8001/api/v1`

### 2. Run Frontend Dev Server

```bash
cd frontend
npm run dev
```

Frontend available at `http://localhost:5173`

### 3. Login Credentials

- Email: `admin@offshore.local`
- Password: `Admin@12345`

---

## API Endpoints Reference

### Bunkers

```typescript
// List bunker stems
GET /api/v1/bunker-stems?vessel_id=1&status=ordered

// Create stem
POST /api/v1/bunker-stems
{
  vessel_id: number
  voyage_id?: number
  port_call_id?: number
  fuel_type_id: number
  ordered_on: string // "2026-10-15"
  ordered_mt: string // "50.000"
  price_per_mt: string // "650.5000"
  currency: string // "USD"
}

// Deliver stem
POST /api/v1/bunker-stems/{id}/deliver
{
  lock_version: number
  delivered_at: string // "2026-10-15T14:00" (port-local)
  delivered_mt: string // "49.500"
  bdn_number: string
}

// Get ROB Ledger for voyage
GET /api/v1/voyages/{voyageId}/rob-ledger
```

### Port DA

```typescript
// List DAs
GET /api/v1/port-das?voyage_id=1&da_type=final&status=draft

// Create DA
POST /api/v1/port-das
{
  port_call_id: number
  voyage_id: number
  port_id: number
  currency: string
  da_type: 'proforma' | 'final'
  agent_company_id?: number
  proforma_da_id?: number // link final to proforma
}

// Save DA items (replaces all)
POST /api/v1/port-das/{id}/items
{
  items: [
    {
      da_cost_category_id: number
      description?: string
      estimated_amount?: string // "1500.00"
      actual_amount?: string // "1650.00"
      remarks?: string
    }
  ]
}

// Workflow
POST /api/v1/port-das/{id}/submit
POST /api/v1/port-das/{id}/approve
POST /api/v1/port-das/{id}/reject
```

### Laytime

```typescript
// Create calculation
POST /api/v1/laytime-calculations
{
  port_call_id: number
  voyage_id: number
  calculation_type: 'load' | 'discharge' | 'reversible'
}

// Update calculation
PUT /api/v1/laytime-calculations/{id}
{
  lock_version: number
  
  // Allowed time (choose one method)
  fixed_hours?: string // "72.0000"
  // OR
  cargo_quantity?: string // "50000.000"
  rate_per_day?: string // "1000.0000"
  rate_unit?: string // "MT"
  
  // Times (port-local, backend converts to UTC)
  nor_tendered_at?: string // "2026-10-15T08:00"
  nor_accepted_at?: string // "2026-10-15T09:00"
  laytime_commenced_at?: string // "2026-10-15T10:00"
  laytime_completed_at?: string // "2026-10-18T14:00"
  
  // Rates
  demurrage_rate_per_day?: string // "24000.0000"
  despatch_rate_per_day?: string // "12000.0000"
  currency?: string // "USD"
  
  // Rules
  once_on_demurrage_rule?: 'always_on_demurrage' | 'exceptions_apply'
  terms_code?: string // "SHINC" | "SHEX" | etc
}

// Calculate result
POST /api/v1/laytime-calculations/{id}/calculate

// Add SOF event
POST /api/v1/laytime-calculations/{id}/sof-events
{
  event_at: string // "2026-10-15T08:00" (port-local)
  event_code: string // "NOR_TENDERED", "COMMENCED", etc
  description?: string
}

// Add exception
POST /api/v1/laytime-calculations/{id}/exceptions
{
  from_at: string // "2026-10-16T00:00" (port-local)
  to_at: string // "2026-10-17T00:00"
  exception_type: string // "weather", "holiday", etc
  pct_counted: string // "0" (excluded) to "100" (full)
  remarks?: string
}

// Workflow
POST /api/v1/laytime-calculations/{id}/submit
POST /api/v1/laytime-calculations/{id}/agree
POST /api/v1/laytime-calculations/{id}/dispute
{
  reason: string
}
```

---

## TanStack Query Hooks Pattern

Based on existing codebase patterns, create hooks in `frontend/src/api/`:

### bunkers.ts (already exists, may need enhancement)

```typescript
import { api } from './client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

export const bunkersApi = {
  list: (params?: BunkerStemFilters) => 
    api.get<PaginatedResponse<BunkerStem>>('/bunker-stems', { params }),
  
  get: (id: number) => 
    api.get<ApiResponse<BunkerStem>>(`/bunker-stems/${id}`),
  
  create: (data: CreateBunkerStemRequest) => 
    api.post<ApiResponse<BunkerStem>>('/bunker-stems', data),
  
  deliver: (id: number, data: DeliverStemRequest) =>
    api.post<ApiResponse<BunkerStem>>(`/bunker-stems/${id}/deliver`, data),
  
  getRobLedger: (voyageId: number) =>
    api.get<ApiResponse<RobLedger>>(`/voyages/${voyageId}/rob-ledger`),
};

export const useBunkerStems = (params?: BunkerStemFilters) => {
  return useQuery({
    queryKey: ['bunker-stems', params],
    queryFn: () => bunkersApi.list(params),
  });
};

export const useDeliverStem = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...data }: { id: number } & DeliverStemRequest) =>
      bunkersApi.deliver(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bunker-stems'] });
    },
  });
};
```

### portDa.ts (needs creation)

```typescript
export const portDaApi = {
  list: (params?: PortDaFilters) =>
    api.get<PaginatedResponse<PortDa>>('/port-das', { params }),
  
  get: (id: number) =>
    api.get<ApiResponse<PortDa>>(`/port-das/${id}`),
  
  create: (data: CreatePortDaRequest) =>
    api.post<ApiResponse<PortDa>>('/port-das', data),
  
  update: (id: number, data: UpdatePortDaRequest) =>
    api.put<ApiResponse<PortDa>>(`/port-das/${id}`, data),
  
  saveItems: (id: number, items: PortDaItemInput[]) =>
    api.post<ApiResponse<PortDa>>(`/port-das/${id}/items`, { items }),
  
  submit: (id: number) =>
    api.post<ApiResponse<PortDa>>(`/port-das/${id}/submit`),
  
  approve: (id: number) =>
    api.post<ApiResponse<PortDa>>(`/port-das/${id}/approve`),
  
  reject: (id: number) =>
    api.post<ApiResponse<PortDa>>(`/port-das/${id}/reject`),
};
```

### laytime.ts (needs creation)

```typescript
export const laytimeApi = {
  list: (params?: LaytimeFilters) =>
    api.get<PaginatedResponse<LaytimeCalculation>>('/laytime-calculations', { params }),
  
  get: (id: number) =>
    api.get<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}`),
  
  create: (data: CreateLaytimeRequest) =>
    api.post<ApiResponse<LaytimeCalculation>>('/laytime-calculations', data),
  
  update: (id: number, data: UpdateLaytimeRequest) =>
    api.put<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}`, data),
  
  calculate: (id: number) =>
    api.post<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}/calculate`),
  
  addSofEvent: (id: number, event: SofEventInput) =>
    api.post<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}/sof-events`, event),
  
  addException: (id: number, exception: ExceptionInput) =>
    api.post<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}/exceptions`, exception),
  
  submit: (id: number) =>
    api.post<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}/submit`),
  
  agree: (id: number) =>
    api.post<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}/agree`),
  
  dispute: (id: number, reason: string) =>
    api.post<ApiResponse<LaytimeCalculation>>(`/laytime-calculations/${id}/dispute`, { reason }),
};
```

---

## TypeScript Types

Create in `frontend/src/types/`:

### bunkers.ts

```typescript
export interface BunkerStem {
  id: number;
  stem_number: string;
  vessel_id: number;
  voyage_id?: number;
  port_call_id?: number;
  fuel_type_id: number;
  ordered_on: string;
  ordered_mt: string;
  delivered_at?: string;
  delivered_mt?: string;
  price_per_mt: string;
  currency: string;
  total_amount?: string;
  status: 'ordered' | 'delivered' | 'invoiced' | 'cancelled';
  vessel?: { id: number; name: string };
  fuel_type?: { id: number; code: string; name: string };
  lock_version: number;
}

export interface RobLedger {
  voyage_id: number;
  threshold_pct: string;
  fuels: RobFuelLedger[];
}

export interface RobFuelLedger {
  fuel_type_id: number;
  fuel_code: string;
  fuel_name: string;
  rows: RobLedgerRow[];
  totals: RobLedgerTotals;
  flags: {
    rob_discontinuity: number;
    consumption: number;
    received_mismatch: number;
  };
}
```

### portDa.ts

```typescript
export interface PortDa {
  id: number;
  da_number: string;
  port_call_id: number;
  voyage_id: number;
  da_type: 'proforma' | 'final';
  currency: string;
  total_amount: string;
  status: 'draft' | 'submitted' | 'approved' | 'settled';
  items?: PortDaItem[];
  port_call?: PortCallSummary;
  lock_version: number;
}

export interface PortDaItem {
  id: number;
  da_cost_category_id: number;
  description?: string;
  estimated_amount?: string;
  actual_amount?: string;
  variance_amount?: string; // server-calculated
  category?: { id: number; code: string; name: string };
}
```

### laytime.ts

```typescript
export interface LaytimeCalculation {
  id: number;
  port_call_id: number;
  voyage_id: number;
  calculation_type: 'load' | 'discharge' | 'reversible';
  fixed_hours?: string;
  cargo_quantity?: string;
  rate_per_day?: string;
  laytime_commenced_at?: string;
  laytime_completed_at?: string;
  allowed_hours?: string;
  used_hours?: string;
  difference_hours?: string;
  demurrage_amount?: string;
  despatch_amount?: string;
  status: 'draft' | 'submitted' | 'agreed' | 'disputed';
  sof_events?: SofEvent[];
  exceptions?: LaytimeException[];
  trace?: Record<string, any>;
  lock_version: number;
}

export interface SofEvent {
  id: number;
  event_at: string;
  event_code: string;
  description?: string;
}

export interface LaytimeException {
  id: number;
  from_at: string;
  to_at: string;
  exception_type: string;
  pct_counted: string;
  remarks?: string;
}
```

---

## Component Structure

### Bunkers Module

```
frontend/src/pages/operations/
├── bunkers/
│   ├── BunkersPage.tsx (already exists, enhance)
│   ├── BunkerStemsTable.tsx (already exists)
│   ├── BunkerStemDialog.tsx (create/edit)
│   ├── DeliverStemDialog.tsx
│   └── RobLedgerView.tsx (new - show in voyage detail)
```

### Port DA Module

```
frontend/src/pages/operations/
├── port-da/
│   ├── PortDaListPage.tsx
│   ├── PortDaDetailPage.tsx
│   ├── PortDaItemsGrid.tsx (editable data grid)
│   └── PortDaApprovalDialog.tsx
```

### Laytime Module

```
frontend/src/pages/operations/
├── laytime/
│   ├── LaytimeListPage.tsx
│   ├── LaytimeDetailPage.tsx
│   ├── LaytimeFormSection.tsx
│   ├── SofEventsTimeline.tsx
│   ├── ExceptionsTable.tsx
│   └── LaytimeResultCard.tsx
```

---

## Key UI Patterns

### 1. Decimal Input Handling

```typescript
// Use existing DecimalField component
import { DecimalField } from '../../components/DecimalField';

<DecimalField
  label="Delivered MT"
  name="delivered_mt"
  scale={3}
  required
/>
```

### 2. Date-Time Input (Port Local)

```typescript
// Backend expects port-local, converts to UTC
<TextField
  type="datetime-local"
  label="Commenced At"
  name="laytime_commenced_at"
  helperText="Port local time"
/>
```

### 3. Lock Version Handling

```typescript
const { data: portDa } = usePortDa(id);
const updateMutation = useUpdatePortDa();

const handleSubmit = (values) => {
  updateMutation.mutate({
    id: portDa.id,
    lock_version: portDa.lock_version,
    ...values,
  });
};
```

### 4. Status Badges

```typescript
const statusColor = {
  draft: 'default',
  submitted: 'info',
  approved: 'success',
  agreed: 'success',
  disputed: 'error',
};

<Chip label={status} color={statusColor[status]} size="small" />
```

---

## Voyage Workspace Integration

Add tabs to existing `VoyageDetailPage.tsx`:

```typescript
const tabs = [
  // ... existing tabs
  { label: 'Bunkers', value: 'bunkers', permission: 'operations.bunkers.view' },
  { label: 'Port DA', value: 'port-da', permission: 'operations.port-da.view' },
  { label: 'Laytime', value: 'laytime', permission: 'operations.laytime.view' },
];

// In tab panel
{value === 'bunkers' && <RobLedgerView voyageId={voyage.id} />}
{value === 'port-da' && <PortDaList voyageId={voyage.id} />}
{value === 'laytime' && <LaytimeList voyageId={voyage.id} />}
```

---

## Permission Checks

```typescript
import { usePermission } from '../../hooks/usePermission';

const canApprove = usePermission('operations.port-da.approve');
const canAgree = usePermission('operations.laytime.agree');

{canApprove && (
  <Button onClick={handleApprove}>Approve</Button>
)}
```

---

## Testing Strategy

1. **Unit Tests**: Decimal formatting, date conversions
2. **Integration Tests**: API hook responses
3. **E2E Tests**: Full workflow (create → submit → approve)

Example with Vitest:

```typescript
describe('PortDaService', () => {
  it('calculates variance correctly', () => {
    const item = {
      estimated_amount: '1500.00',
      actual_amount: '1650.00',
    };
    expect(calculateVariance(item)).toBe('150.00');
  });
});
```

---

## Common Pitfalls & Solutions

### ❌ Sending decimals as numbers
```typescript
// Wrong
{ ordered_mt: 50.5 }

// Correct
{ ordered_mt: '50.500' }
```

### ❌ Forgetting lock_version
```typescript
// Wrong
api.put(`/port-das/${id}`, { currency: 'USD' })

// Correct
api.put(`/port-das/${id}`, { 
  lock_version: portDa.lock_version,
  currency: 'USD' 
})
```

### ❌ Sending UTC times for port-local inputs
```typescript
// Wrong - backend expects port-local
delivered_at: moment.utc().format()

// Correct - port-local, backend converts
delivered_at: '2026-10-15T14:00' // as entered by user
```

---

## Resources

- **API Base**: `http://localhost:8001/api/v1`
- **Backend Tests**: `backend/tests/Feature/Operations/` (reference implementations)
- **Existing Patterns**: Check `OffshoreActivitiesPage.tsx` for similar workflow
- **MUI Components**: Use DataGrid for tables, Dialog for modals
- **Form Validation**: react-hook-form + zod (existing pattern)

---

## Quick Checklist

**Before Starting:**
- [ ] Backend API running (`php artisan serve`)
- [ ] Frontend dev server running (`npm run dev`)
- [ ] Logged in as admin user
- [ ] Reference existing voyage/activity pages

**Per Module:**
- [ ] Create TypeScript types
- [ ] Create API client functions
- [ ] Create TanStack Query hooks
- [ ] Build list page with filters
- [ ] Build detail/form page
- [ ] Implement workflow actions
- [ ] Add permission checks
- [ ] Write tests
- [ ] Integrate into voyage tabs

---

**Ready to Start!** 🚀

Backend is solid, all APIs tested and documented. Focus on user experience and follow existing MUI/TanStack Query patterns.
