import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';
import type { ReportCatalogueItem } from '../../types/finance';
import ReportsPage from './ReportsPage';

const catalogue: ReportCatalogueItem[] = [
  { slug: 'voyage-pnl', title: 'Voyage P&L', filters: ['from', 'to'], formats: ['json', 'csv', 'xlsx', 'pdf'] },
  { slug: 'fleet-status', title: 'Fleet status', filters: [], formats: ['json'] },
];

vi.mock('../../api/finance', () => ({
  reportsApi: {
    catalogue: vi.fn(() => Promise.resolve(catalogue)),
    run: vi.fn((slug: string) => Promise.resolve({ slug, title: slug, base_currency: 'USD', columns: [{ key: 'voyage', label: 'Voyage', align: 'left' }, { key: 'net_profit', label: 'Net profit', align: 'right' }],
      rows: [{ voyage: 'GMS1-26-001', net_profit: '1234567.50' }] })),
    download: vi.fn(),
    statisticMetrics: vi.fn(() => Promise.resolve([{ metric: 'billable-hours', label: 'Offshore billable hours', unit: 'hours', groups: ['vessel', 'month'] }])),
    statistic: vi.fn(() => Promise.resolve({ metric: 'billable-hours', label: 'Offshore billable hours', unit: 'hours', group_by: 'month', base_currency: null,
      rows: [{ label: '2026-10', total: '8.00', line_count: 1 }], total: '8.00' })),
  },
}));

function renderAt(path: string, permissions: string[]) {
  const auth = { user: { permissions, is_super_admin: false, timezone: 'UTC' }, can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}><SnackbarProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter initialEntries={[path]}><Routes><Route path="/reports/:slug?" element={<ReportsPage />} /></Routes></MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>
    </SnackbarProvider></ThemeProvider>,
  );
}

describe('ReportsPage', () => {
  it('shows a report with grouped numbers and an export button per format the API allows', async () => {
    renderAt('/reports/voyage-pnl', [P.ReportsView, P.ReportsExport]);
    expect(await screen.findByText('1,234,567.50')).toBeInTheDocument();
    for (const name of ['CSV', 'Excel', 'PDF']) expect(screen.getByRole('button', { name })).toBeInTheDocument();
  });

  it('offers no export buttons when the API lists only json', async () => {
    renderAt('/reports/fleet-status', [P.ReportsView]);
    await screen.findByText(/row\(s\)/);
    expect(screen.queryByRole('button', { name: 'CSV' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excel' })).not.toBeInTheDocument();
  });

  it('builds the statistics tab from the metric catalogue and labels hour metrics in hours', async () => {
    renderAt('/reports', [P.ReportsView, P.StatisticsView]);
    await userEvent.click(await screen.findByRole('tab', { name: 'Statistics' }));
    await waitFor(() => expect(screen.getByText(/Total 8.00 h/)).toBeInTheDocument());
    expect(screen.getAllByText(/8.00 h · 1 line\(s\)/).length).toBeGreaterThan(0);
  });
});
