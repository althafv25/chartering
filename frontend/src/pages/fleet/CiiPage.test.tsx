import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';
import type { CiiFleetSummary, CiiVesselYear } from '../../types/cii';
import CiiPage from './CiiPage';

const summary: CiiFleetSummary = {
  reporting_year: 2026, vessel_count: 2, by_rating: { A: 1, B: 0, C: 0, D: 1, E: 0 }, fleet_average_ratio: '0.8120', at_risk_count: 1,
};

const year: CiiVesselYear = {
  id: 1, vessel_id: 10, reporting_year: 2026, formula_set_id: 1, capacity_metric: 'DWT', capacity_value: '4200.000',
  fuel_consumption: { HFO: '150.000' }, distance_sailed_nm: '12000.00', attained_cii: '5.123456', required_cii: '6.300000', rating: 'B',
  source: 'calculated', scope_status: 'in_scope', status: 'calculated', calculation_version: '1.0.0', calculation_trace: {}, remarks: null, lock_version: 1,
  vessel: { id: 10, code: 'PSV1', name: 'Test PSV', imo_number: null, dwt_mt: '4200.000', gt: '2800.00' },
  formula_set: { id: 1, code: 'test-set', name: 'Test set', status: 'draft' }, verified_by: null, verified_at: null,
  created_at: '2026-10-01T00:00:00Z', updated_at: '2026-10-01T00:00:00Z',
};

vi.mock('../../api/cii', () => ({
  ciiApi: {
    list: vi.fn(() => Promise.resolve({ success: true, message: '', data: [year], meta: { current_page: 1, per_page: 15, total: 1, last_page: 1, from: 1, to: 1 } })),
    fleetSummary: vi.fn(() => Promise.resolve({ success: true, message: '', data: summary })),
    formulaSets: vi.fn(() => Promise.resolve({ success: true, message: '', data: [] })),
    save: vi.fn(), calculate: vi.fn(), verify: vi.fn(), reopen: vi.fn(), saveFormulaSet: vi.fn(), verifyFormulaSet: vi.fn(), retireFormulaSet: vi.fn(),
  },
}));
vi.mock('../../api/masters', () => ({
  vesselsApi: { list: vi.fn(() => Promise.resolve({ success: true, message: '', data: [{ id: 10, code: 'PSV1', name: 'Test PSV' }], meta: {} })) },
  referenceApi: { list: vi.fn(() => Promise.resolve([])) },
}));

function renderPage(permissions: string[]) {
  const auth = {
    user: { permissions, is_super_admin: false, timezone: 'UTC' },
    can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false,
  } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}><SnackbarProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}><CiiPage /></AuthContext.Provider>
      </QueryClientProvider>
    </SnackbarProvider></ThemeProvider>,
  );
}

describe('CiiPage', () => {
  it('shows fleet summary KPIs and the vessel-year list, hiding "New record" without manage permission', async () => {
    renderPage([P.CiiView]);
    await waitFor(() => expect(screen.getByText('Test PSV')).toBeInTheDocument());
    expect(screen.getByText('2')).toBeInTheDocument(); // vessel_count stat
    expect(screen.getByText('Rating B')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /new record/i })).not.toBeInTheDocument();
  });

  it('shows "New record" with manage permission', async () => {
    renderPage([P.CiiView, P.CiiManage]);
    expect(await screen.findByRole('button', { name: /new record/i })).toBeInTheDocument();
  });
});
