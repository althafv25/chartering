import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import type { VoyageFinancials } from '../../types/finance';
import { FinancialsPanel } from './FinancialsPanel';

const financials: VoyageFinancials = {
  voyage_id: 1, base_currency: 'USD',
  rows: [
    { metric: 'gross_revenue', label: 'Revenue', estimate: '1200.00', actual: '1000.00', variance: '-200.00', variance_pct: '-16.67' },
    { metric: 'commission', label: 'Commission', estimate: null, actual: '100.00', variance: null, variance_pct: null },
    { metric: 'net_profit', label: 'Net profit', estimate: '500.00', actual: '600.00', variance: '100.00', variance_pct: '20.00' },
  ],
  gross_profit: '700.00', margin_pct: '60.00', profit_per_day: null, total_days: null,
  revenue_by_category: [{ category: 'Freight', amount: '1000.00' }], expense_by_category: [],
  estimate_available: false, notes: ['Overheads are not allocated.'],
};

vi.mock('../../api/finance', () => ({ voyageFinancialsApi: { get: vi.fn(() => Promise.resolve(financials)) } }));

describe('FinancialsPanel', () => {
  it('shows estimate, actual and variance from the server without recalculating', async () => {
    render(
      <ThemeProvider theme={theme}>
        <QueryClientProvider client={new QueryClient()}><FinancialsPanel voyageId={1} /></QueryClientProvider>
      </ThemeProvider>,
    );
    expect(await screen.findByText('Net profit')).toBeInTheDocument();
    expect(screen.getByText('1,200.00')).toBeInTheDocument();
    expect(screen.getByText('-200.00')).toBeInTheDocument();
    expect(screen.getByText('-16.67 %')).toBeInTheDocument();
    expect(screen.getByText('Freight')).toBeInTheDocument();
    expect(screen.getByText(/No initial snapshot estimate is available/)).toBeInTheDocument();
  });
});
