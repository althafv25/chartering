import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';
import type { OffshoreActivity } from '../../types/offshore';

vi.mock('../../api/offshore', () => ({ offshoreActivitiesApi: { list: vi.fn(), action: vi.fn(), remove: vi.fn() }, offshoreProjectsApi: { list: vi.fn() } }));
import { offshoreActivitiesApi } from '../../api/offshore';
import { ActivitiesTable } from './ActivitiesTable';

const activity = (o: Partial<OffshoreActivity>): OffshoreActivity => ({
  id: 1, activity_number: 'OA-2026-00001', vessel_id: 1, voyage_id: null, contract_id: 1, offshore_project_id: null, client_company_id: 1, offshore_location_id: null,
  offshore_activity_type_id: 1, description: null, billable_hours: '30.0000', non_billable_hours: '0.0000', standby_hours: '0.0000', duration_hours: '30.0000',
  fuel_used: null, status: 'submitted', decision_comment: null, remarks: null, lock_version: 1, contract_version_no: 1, calculation_basis: 'hourly|standby_rate',
  warnings: [], start_at: '2026-10-10T02:00:00+00:00', end_at: '2026-10-11T08:00:00+00:00', start_at_local: '2026-10-10T06:00', end_at_local: '2026-10-11T12:00',
  timezone: 'Asia/Dubai', is_editable: false, rates_visible: true, currency: 'USD', revenue_amount: '18125.00', rate_snapshot: [], vessel: { id: 1, code: 'GMS1', name: 'GMS Endeavour' },
  voyage: null, contract: { id: 1, contract_number: 'CON-2026-00002', status: 'active' }, project: null, client: { id: 1, legal_name: 'Gulf Charterers' }, location: null,
  type: { id: 1, code: 'SUPPLY', name: 'Supply run', is_billable_default: true }, submitted_by: { id: 2, name: 'Ops' }, submitted_at: null, verified_by: null, verified_at: null,
  created_at: '2026-10-11T09:00:00Z', ...o,
});

function renderTable(rows: OffshoreActivity[], permissions: string[], revenue: { currency: string; amount: string }[] | null = null) {
  vi.mocked(offshoreActivitiesApi.list).mockResolvedValue({ success: true, message: 'OK', data: rows, meta: { current_page: 1, last_page: 1, per_page: 15, total: rows.length },
    summary: { count: rows.length, billable_hours: '30.0000', non_billable_hours: '0', standby_hours: '0', revenue } } as never);
  const auth = { user: { permissions, is_super_admin: false, timezone: 'Asia/Dubai' }, can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}><SnackbarProvider><QueryClientProvider client={new QueryClient()}>
      <AuthContext.Provider value={auth}><ActivitiesTable filters={{}} /></AuthContext.Provider>
    </QueryClientProvider></SnackbarProvider></ThemeProvider>,
  );
}

describe('ActivitiesTable', () => {
  it('shows server revenue and verify actions for a verifier', async () => {
    renderTable([activity({})], [P.OffshoreActivitiesView, P.OffshoreActivitiesVerify], [{ currency: 'USD', amount: '18125.00' }]);
    expect(await screen.findByText('OA-2026-00001')).toBeInTheDocument();
    expect(screen.getAllByText(/18,125\.00/).length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Verify' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /new activity/i })).not.toBeInTheDocument();
  });

  it('hides revenue when rates are not visible and flags unpriced activities', async () => {
    renderTable([activity({ rates_visible: false, revenue_amount: null, currency: null, rate_snapshot: null }),
      activity({ id: 2, activity_number: 'OA-2026-00002', revenue_amount: null, warnings: ['no_contract'], status: 'draft', is_editable: true })], [P.OffshoreActivitiesView]);
    await waitFor(() => expect(screen.getByText('OA-2026-00002')).toBeInTheDocument());
    expect(screen.getByText('not priced')).toBeInTheDocument();
    expect(screen.queryByText(/18,125/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Verify' })).not.toBeInTheDocument();
  });
});
