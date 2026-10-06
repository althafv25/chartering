import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import type { Estimation, Scenario, ScenarioInputs, ScenarioResult } from '../../types/chartering';

vi.mock('../../api/chartering', () => ({ estimationsApi: { get: vi.fn(), scenario: vi.fn(), select: vi.fn(), saveScenario: vi.fn() } }));
vi.mock('../../api/masters', () => ({ referenceApi: { list: vi.fn(() => Promise.resolve([])) } }));
import { estimationsApi } from '../../api/chartering';
import EstimationDetailPage from './EstimationDetailPage';

// MySQL JSON returns a different top-level key order from the editor's defaults.
const storedInputs = (seaMargin = '0'): ScenarioInputs => ({
  legs: [], calls: [], cost_items: [], consumption: [], fuel_prices: [], revenue_items: [],
  sea_margin_pct: seaMargin, eca_fuel_type_id: null,
});
const result: ScenarioResult = {
  calculation_version: '1', inputs_hash: 'calculated-inputs', calculated_at: '2026-10-05T10:00:00Z',
  sea_distance_nm: '0', eca_distance_nm: '0', sea_days: '0', eca_sea_days: '0', port_days: '0', total_days: '0',
  fuel_total_mt: '0', fuel_cost: '0', port_costs: '0', agency_costs: '0', canal_costs: '0', other_costs: '0',
  operational_costs: '0', tonnage_cost: '0', gross_revenue: '0', total_commission: '0', net_revenue: '0',
  voyage_costs: '0', total_costs: '0', profit: '0', profit_margin_pct: null, profit_per_day: null,
  tce_per_day: null, breakeven_rate: null, breakeven_basis: null, breakeven_item: null, warnings: [],
};
const scenario = (overrides: Partial<Scenario> = {}): Scenario => ({
  id: 2, estimation_id: 2, code: 'A', name: 'Scenario A', is_selected: false, calc_status: 'calculated', calc_issues: [],
  calculation_version: '1', inputs_hash: 'calculated-inputs', calculated_at: '2026-10-05T10:00:00Z',
  defaults_refreshed_at: null, cloned_from_id: null, consumption_profile_id: null, notes: null, lock_version: 0,
  inputs: storedInputs(), result, ...overrides,
});
const estimation = (s: Scenario): Estimation => ({
  id: 2, estimation_number: 'EST-2026-00001', estimation_type: 'voyage_charter', title: 'Selection regression',
  currency: 'USD', status: 'draft', is_editable: true, enquiry_id: 1, vessel_id: 1,
  submitted_at: null, decided_at: null, decision_comment: null, remarks: null, lock_version: 0,
  scenarios: [s], created_at: '2026-10-05T10:00:00Z',
});
const clients: QueryClient[] = [];

function renderPage(s = scenario()) {
  vi.mocked(estimationsApi.scenario).mockResolvedValue(s);
  vi.mocked(estimationsApi.get).mockResolvedValue(estimation(s));
  vi.mocked(estimationsApi.select).mockImplementation(async () => {
    const selected = { ...s, is_selected: true };
    vi.mocked(estimationsApi.get).mockResolvedValue(estimation(selected));
    return { success: true, message: 'Scenario selected.', data: selected };
  });
  const permissions = ['chartering.estimations.update', 'chartering.estimations.submit'];
  const auth = { can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)) } as AuthContextValue;
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  clients.push(client);
  render(
    <ThemeProvider theme={theme}>
      <SnackbarProvider>
        <QueryClientProvider client={client}>
          <AuthContext.Provider value={auth}>
            <MemoryRouter initialEntries={['/chartering/estimations/2']}>
              <Routes><Route path="/chartering/estimations/:id" element={<EstimationDetailPage />} /></Routes>
            </MemoryRouter>
          </AuthContext.Provider>
        </QueryClientProvider>
      </SnackbarProvider>
    </ThemeProvider>,
  );
}

afterEach(() => {
  cleanup();
  clients.splice(0).forEach((client) => client.clear());
  vi.clearAllMocks();
});

describe('Estimation scenario selection', () => {
  it('allows selecting an unchanged calculated scenario returned in database key order', async () => {
    const user = userEvent.setup();
    renderPage();
    const select = await screen.findByRole('button', { name: 'Select scenario' }, { timeout: 5000 });
    expect(select).toBeEnabled();
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save & calculate' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Submit for approval' })).toBeDisabled();

    await user.click(select);
    await waitFor(() => expect(estimationsApi.select).toHaveBeenCalledWith(2, 2));
    expect(await screen.findByText('Selected')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Submit for approval' })).toBeEnabled());
  });

  it('blocks selection for actual edits and enables it again after saving reordered inputs', async () => {
    const user = userEvent.setup();
    const s = scenario();
    vi.mocked(estimationsApi.saveScenario).mockResolvedValue({ success: true, message: 'Scenario saved.', data: {
      ...s, inputs: storedInputs('10'), lock_version: 1,
    } });
    renderPage(s);
    const select = await screen.findByRole('button', { name: 'Select scenario' }, { timeout: 5000 });
    const margin = screen.getByLabelText('Sea margin %');
    fireEvent.change(margin, { target: { value: '10' } });
    expect(select).toBeDisabled();
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Save & calculate' }));
    await waitFor(() => expect(estimationsApi.saveScenario).toHaveBeenCalledWith(2, 2, expect.objectContaining({
      inputs: expect.objectContaining({ sea_margin_pct: '10' }), lock_version: 0,
    })));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Select scenario' })).toBeEnabled());
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save & calculate' })).toBeDisabled();
  });

  it('does not treat missing optional legacy fields as user edits', async () => {
    renderPage(scenario({ inputs: { legs: [] } as unknown as ScenarioInputs }));
    expect(await screen.findByRole('button', { name: 'Select scenario' }, { timeout: 5000 })).toBeEnabled();
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Sea margin %')).toHaveValue('0');
  });

  it('keeps selection disabled while calculation is incomplete', async () => {
    renderPage(scenario({ calc_status: 'incomplete', result: null, calc_issues: ['Missing fuel price.'] }));
    expect(await screen.findByRole('button', { name: 'Select scenario' }, { timeout: 5000 })).toBeDisabled();
    expect(screen.getByText('Missing fuel price.')).toBeInTheDocument();
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument();
  });
});
