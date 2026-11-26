import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import type { ReportResult } from '../../types/finance';

vi.mock('../../api/finance', () => ({ reportsApi: { run: vi.fn(), download: vi.fn() } }));

import { reportsApi } from '../../api/finance';
import { CustomerStatementPanel } from './CustomerStatementPanel';

const result = {
  slug: 'customer-statement', title: 'Customer statement of account', base_currency: 'USD', truncated: false, row_limit: 500,
  columns: [
    { key: 'date', label: 'Date', align: 'left' }, { key: 'type', label: 'Type', align: 'left' }, { key: 'reference', label: 'Reference', align: 'left' },
    { key: 'description', label: 'Description', align: 'left' }, { key: 'currency', label: 'Currency', align: 'left' },
    { key: 'original_amount', label: 'Original amount', align: 'right' }, { key: 'debit', label: 'Debit', align: 'right' },
    { key: 'credit', label: 'Credit', align: 'right' }, { key: 'balance', label: 'Balance', align: 'right' },
  ],
  rows: [
    { date: '2026-10-01', type: 'Opening balance', reference: '', description: 'Balance brought forward for Gulf Trading FZE', currency: '', original_amount: null, debit: null, credit: null, balance: '600.00' },
    { date: '2026-10-05', type: 'Invoice', reference: 'INV-B', description: 'Hire', currency: 'USD', original_amount: '500.00', debit: '500.00', credit: null, balance: '1100.00' },
    { date: '2026-10-31', type: 'Closing balance', reference: '', description: 'Period totals to 2026-10-31', currency: '', original_amount: null, debit: '500.00', credit: '0.00', balance: '1100.00' },
  ],
} as unknown as ReportResult;

const clients: QueryClient[] = [];

function renderPanel(permissions: string[]) {
  const auth = { can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)) } as AuthContextValue;
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  clients.push(client);
  render(
    <ThemeProvider theme={theme}>
      <SnackbarProvider>
        <QueryClientProvider client={client}>
          <AuthContext.Provider value={auth}>
            <CustomerStatementPanel companyId={7} />
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

describe('CustomerStatementPanel', () => {
  it('asks the report for this customer and shows the opening, lines and closing balance', async () => {
    vi.mocked(reportsApi.run).mockResolvedValue(result);
    renderPanel(['reports.export']);

    expect(await screen.findByText('Balance brought forward for Gulf Trading FZE')).toBeInTheDocument();
    expect(reportsApi.run).toHaveBeenCalledWith('customer-statement', { customer_company_id: '7', from: undefined, to: undefined });
    expect(screen.getByText('INV-B')).toBeInTheDocument();
    expect(screen.getByText('Closing balance 1,100.00 USD')).toBeInTheDocument();
  });

  it('shows the export buttons only to users who may export reports', async () => {
    vi.mocked(reportsApi.run).mockResolvedValue(result);
    renderPanel(['reports.view']);

    expect(await screen.findByText('INV-B')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excel' })).not.toBeInTheDocument();
  });

  it('offers Excel, CSV and PDF to users with the export permission', async () => {
    vi.mocked(reportsApi.run).mockResolvedValue(result);
    renderPanel(['reports.export']);

    expect(await screen.findByText('INV-B')).toBeInTheDocument();
    for (const name of ['Excel', 'CSV', 'PDF']) {
      expect(screen.getByRole('button', { name })).toBeEnabled();
    }
  });
});
