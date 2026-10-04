import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import type { Offer, OfferRevision } from '../../types/chartering';

vi.mock('../../api/chartering', () => ({ offersApi: { get: vi.fn(), activity: vi.fn(() => Promise.resolve({ data: [], meta: { total: 0 } })), diff: vi.fn() } }));
import { offersApi } from '../../api/chartering';
import OfferDetailPage from './OfferDetailPage';

const rev = (o: Partial<OfferRevision>): OfferRevision => ({
  id: 1, offer_id: 1, revision_no: 1, direction: 'outbound', status: 'draft', is_immutable: false, is_open: false, estimation_scenario_id: 3,
  scenario: { id: 3, code: 'A', name: 'Scenario A', is_selected: true, estimation_id: 9, estimation_number: 'EST-2026-00001', estimation_status: 'approved' },
  rate: '55.0000', rate_basis: 'per_mt', currency: 'USD', quantity: '2000.000', quantity_unit: 'mt', laycan_from: null, laycan_to: null, period_days: null,
  ports: [], commissions: { address_pct: '0', brokerage_pct: '1.25', other_pct: '0', broker_company_id: null }, terms: null, valid_until: null, remarks: null,
  sent_at: null, received_at: null, decided_at: null, decision_reason: null, fixture: null, created_at: '2026-10-01T10:00:00Z', ...o,
});

const offer = (revisions: OfferRevision[], status: Offer['status'] = 'open'): Offer => ({
  id: 1, offer_number: 'OFF-2026-00001', status, enquiry_id: 1, vessel_id: 1, vessel: { id: 1, code: 'GMS1', name: 'GMS Endeavour' },
  latest_revision: revisions[revisions.length - 1], revisions, created_at: '2026-10-01T10:00:00Z',
});

function renderPage(o: Offer, permissions: string[]) {
  vi.mocked(offersApi.get).mockResolvedValue(o);
  const auth = { user: { permissions, is_super_admin: false }, can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}>
      <SnackbarProvider>
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
          <AuthContext.Provider value={auth}>
            <MemoryRouter initialEntries={['/chartering/offers/1']}>
              <Routes><Route path="/chartering/offers/:id" element={<OfferDetailPage />} /></Routes>
            </MemoryRouter>
          </AuthContext.Provider>
        </QueryClientProvider>
      </SnackbarProvider>
    </ThemeProvider>,
  );
}

const ALL = ['chartering.offers.view', 'chartering.offers.update', 'chartering.offers.send', 'chartering.offers.accept', 'chartering.offers.reject', 'chartering.fixtures.create'];

describe('OfferDetailPage', () => {
  it('marks sent revisions immutable and offers no edit action on them', async () => {
    renderPage(offer([rev({ status: 'superseded', is_immutable: true }), rev({ id: 2, revision_no: 2, direction: 'inbound', status: 'received', is_immutable: true, is_open: true, rate: '52.0000' })]), ALL);
    expect(await screen.findByText('Revision 2')).toBeInTheDocument();
    expect(screen.getAllByText('Immutable')).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'Edit draft' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Accept' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Compare with rev 1/ })).toBeInTheDocument();
    expect(screen.getByText('Superseded')).toBeInTheDocument();
  });

  it('allows editing and sending only a draft, and hides actions without permission', async () => {
    renderPage(offer([rev({})]), ALL);
    expect(await screen.findByRole('button', { name: 'Edit draft' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Mark sent' })).toBeInTheDocument();
  });

  it('read-only users see history but no actions', async () => {
    renderPage(offer([rev({ status: 'sent', is_immutable: true, is_open: true })]), ['chartering.offers.view']);
    expect(await screen.findByText('Revision 1')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'New revision' })).not.toBeInTheDocument();
  });

  it('offers fixture creation only on the accepted revision of a closed offer', async () => {
    renderPage(offer([rev({ status: 'accepted', is_immutable: true })], 'accepted'), ALL);
    expect(await screen.findByRole('button', { name: 'Create fixture' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'New revision' })).not.toBeInTheDocument();
    expect(screen.getByText(/revision history is read-only/)).toBeInTheDocument();
  });
});
