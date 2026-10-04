import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';
import type { LaytimeCalculation } from '../../types/operations';
import LaytimeDetailPage from './LaytimeDetailPage';

const state: { calc: LaytimeCalculation } = { calc: {} as LaytimeCalculation };
const calc = (o: Partial<LaytimeCalculation>): LaytimeCalculation => ({
  id: 5, port_call_id: 1, voyage_id: 1, contract_id: null, calculation_type: 'load', fixed_hours: '72.0000', cargo_quantity: null, rate_per_day: null, rate_unit: null, terms_code: null,
  notice_time_hours: null, demurrage_rate_per_day: '24000.0000', despatch_rate_per_day: null, currency: 'USD', once_on_demurrage_rule: 'always_on_demurrage',
  allowed_hours: '72.0000', used_hours: '102.0000', difference_hours: '-30.0000', demurrage_amount: '30000.0000', despatch_amount: null, calculation_version: '1.0.1', trace: {
    used_hours_detail: [{ from: '2026-10-01T02:00:00+00:00', to: '2026-10-05T08:00:00+00:00', hours: '102.0000', pct_counted: '100', counted_hours: '102.0000', exception_types: [] }] },
  status: 'draft', remarks: null, lock_version: 0, is_editable: true, timezone: 'Asia/Dubai', nor_tendered_at_local: '2026-10-01T06:00', nor_accepted_at_local: null,
  laytime_commenced_at_local: '2026-10-01T06:00', laytime_completed_at_local: '2026-10-05T12:00', calculated_at: '2026-10-05T09:00:00Z', agreed_at: null,
  port_call: { id: 1, label: 'Jebel Ali (AEJEA)' }, voyage: { id: 1, voyage_number: 'GMS1-26-001', status: 'sailing' }, contract: null, sof_events: [], exceptions: [], ...o,
});

vi.mock('../../api/operations', () => ({ laytimeApi: { get: vi.fn(() => Promise.resolve(state.calc)), update: vi.fn(), calculate: vi.fn(), action: vi.fn(), dispute: vi.fn(), addSofEvent: vi.fn(), removeSofEvent: vi.fn(), addException: vi.fn(), removeException: vi.fn() } }));
vi.mock('../../api/masters', async (orig) => ({ ...(await orig<object>()), currenciesApi: { list: vi.fn(() => Promise.resolve([{ code: 'USD', name: 'US Dollar' }])) } }));

function renderPage(permissions: string[]) {
  const auth = { user: { permissions, is_super_admin: false, timezone: 'UTC' }, can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}><SnackbarProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter initialEntries={['/operations/laytime/5']}><Routes><Route path="/operations/laytime/:id" element={<LaytimeDetailPage />} /></Routes></MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>
    </SnackbarProvider></ThemeProvider>,
  );
}

describe('LaytimeDetailPage', () => {
  it('shows port-local times labelled with the port zone, the server result, and edit actions for a draft', async () => {
    state.calc = calc({});
    renderPage([P.LaytimeView, P.LaytimeUpdate]);
    expect(await screen.findByLabelText('Laytime commenced (Dubai)')).toHaveValue('2026-10-01T06:00');
    expect(screen.getByLabelText('Laytime completed (Dubai)')).toHaveValue('2026-10-05T12:00');
    expect(screen.getByText('Demurrage')).toBeInTheDocument();
    expect(screen.getByText('30,000.0000 USD')).toBeInTheDocument();
    expect(screen.getByText('Over allowed (h)')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Calculate' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Submit' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: 'Agree' })).not.toBeInTheDocument();
  });

  it('cannot be submitted before it has been calculated', async () => {
    state.calc = calc({ calculated_at: null, allowed_hours: null, used_hours: null, difference_hours: null, demurrage_amount: null });
    renderPage([P.LaytimeView, P.LaytimeUpdate]);
    expect(await screen.findByText(/Not calculated yet/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
  });

  it('is read-only once submitted and offers agree / dispute only to users who may agree', async () => {
    state.calc = calc({ status: 'submitted', is_editable: false });
    const { unmount } = renderPage([P.LaytimeView, P.LaytimeUpdate, P.LaytimeAgree]);
    expect(await screen.findByRole('button', { name: 'Agree' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Dispute' })).toBeInTheDocument();
    expect(screen.getByLabelText('Laytime commenced (Dubai)')).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Calculate' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save terms' })).not.toBeInTheDocument();
    unmount();
    renderPage([P.LaytimeView, P.LaytimeUpdate]);
    await screen.findByLabelText('Laytime commenced (Dubai)');
    expect(screen.queryByRole('button', { name: 'Agree' })).not.toBeInTheDocument();
  });
});
