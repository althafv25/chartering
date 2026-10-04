import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';
import type { PortDa } from '../../types/operations';
import PortDaDetailPage from './PortDaDetailPage';

const state: { da: PortDa } = { da: {} as PortDa };
const da = (o: Partial<PortDa>): PortDa => ({
  id: 7, da_number: 'DA-2026-00007', port_call_id: 1, voyage_id: 1, port_id: 1, agent_company_id: null, da_type: 'final', proforma_da_id: null, currency: 'USD', fx_rate: '1', fx_method: null,
  total_amount: '1200.50', base_amount: '1200.50', base_currency: 'USD', status: 'draft', remarks: null, lock_version: 0, is_editable: true, submitted_at: null, approved_at: null,
  created_at: '2026-10-15T00:00:00Z', port: { id: 1, name: 'Jebel Ali', unlocode: 'AEJEA' }, port_call: { id: 1, label: 'Jebel Ali (AEJEA)' }, voyage: { id: 1, voyage_number: 'GMS1-26-001', status: 'sailing' },
  agent: null, proforma: null,
  items: [{ id: 1, port_da_id: 7, da_cost_category_id: 3, description: 'Agency fee', estimated_amount: '1000.00', actual_amount: '1200.50', variance_amount: '200.50', remarks: null, sequence: 1, category: { id: 3, code: 'AGENCY', name: 'Agency' } }],
  ...o,
});

vi.mock('../../api/operations', () => ({ portDasApi: { get: vi.fn(() => Promise.resolve(state.da)), saveItems: vi.fn(), action: vi.fn() } }));
vi.mock('../../api/masters', async (orig) => ({ ...(await orig<object>()), referenceApi: { list: vi.fn(() => Promise.resolve([{ id: 3, name: 'Agency' }])) } }));

function renderPage(permissions: string[]) {
  const auth = { user: { permissions, is_super_admin: false, timezone: 'UTC' }, can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}><SnackbarProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter initialEntries={['/operations/port-da/7']}><Routes><Route path="/operations/port-da/:id" element={<PortDaDetailPage />} /></Routes></MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>
    </SnackbarProvider></ThemeProvider>,
  );
}

describe('PortDaDetailPage', () => {
  it('lets an editor change items; saving is enabled only after a valid change and submitting only when nothing is unsaved', async () => {
    state.da = da({});
    renderPage([P.PortDaView, P.PortDaUpdate]);
    const submit = await screen.findByRole('button', { name: 'Submit for approval' });
    const save = screen.getByRole('button', { name: 'Save items' });
    expect(submit).toBeEnabled();
    expect(save).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();

    const actual = screen.getByDisplayValue('1200.50');
    await userEvent.clear(actual);
    await userEvent.type(actual, '12.345');            // three decimals is not a valid amount
    expect(save).toBeDisabled();
    await userEvent.clear(actual);
    await userEvent.type(actual, '1300');
    expect(save).toBeEnabled();
    expect(submit).toBeDisabled();                       // unsaved edits must be saved before submitting
  });

  it('shows read-only items with server variance and approval buttons for an approver on a submitted DA', async () => {
    state.da = da({ status: 'submitted', is_editable: false });
    renderPage([P.PortDaView, P.PortDaUpdate, P.PortDaApprove]);
    expect(await screen.findByRole('button', { name: 'Approve' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Return to draft' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add item' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit for approval' })).not.toBeInTheDocument();
    expect(screen.getByText('200.50')).toBeInTheDocument();
  });

  it('gives a viewer no action buttons at all', async () => {
    state.da = da({});
    renderPage([P.PortDaView]);
    expect(await screen.findByText('Agency fee')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /submit|approve|save items|add item/i })).not.toBeInTheDocument();
  });
});
