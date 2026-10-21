import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ConfirmedRevenuePicker, VoyageSelect } from './FinanceLinkPickers';

vi.mock('../api/operations', () => ({
  voyagesApi: { list: vi.fn().mockResolvedValue({ data: [
    { id: 7, voyage_number: 'EXT01-26-001', vessel: { id: 1, code: 'EXT01', name: 'MV Example Trader' }, contract: { id: 3, contract_number: 'CON-2026-00001', status: 'active' }, contract_id: 3, currency: 'USD' },
  ], meta: {} }) },
}));
vi.mock('../api/finance', () => ({
  voyageRevenuesApi: { list: vi.fn().mockResolvedValue({ data: [
    { id: 11, description: 'Freight', amount: '110000.00', currency: 'USD', category: { id: 1, code: 'FREIGHT', name: 'Freight' } },
    { id: 12, description: 'Demurrage', amount: '4000.00', currency: 'USD', category: { id: 2, code: 'DEMURRAGE', name: 'Demurrage' } },
    { id: 13, description: 'Hire in EUR', amount: '500.00', currency: 'EUR', category: null },
  ], meta: {} }) },
}));

const wrap = (ui: React.ReactElement) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>);

describe('VoyageSelect', () => {
  it('lists open voyages and reports the chosen voyage (with its contract) to the form', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    wrap(<VoyageSelect value={null} onChange={onChange} />);
    await user.click(await screen.findByRole('combobox', { name: /voyage/i }));
    await user.click(await screen.findByRole('option', { name: /EXT01-26-001 · MV Example Trader · CON-2026-00001/ }));
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ id: 7, contract_id: 3, currency: 'USD' }));
  });

  it('offers an explicit "not linked" choice unless required', async () => {
    const user = userEvent.setup();
    wrap(<VoyageSelect value={7} onChange={() => {}} />);
    await waitFor(() => expect(screen.getByRole('combobox')).toHaveTextContent('EXT01-26-001'));
    await user.click(screen.getByRole('combobox'));
    expect(await screen.findByRole('option', { name: /not linked to a voyage/i })).toBeInTheDocument();
  });
});

describe('ConfirmedRevenuePicker', () => {
  it('lists confirmed revenue, disables other currencies and reports ticked ids', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    wrap(<ConfirmedRevenuePicker voyageId={7} currency="USD" selected={[]} onChange={onChange} />);
    const freight = await screen.findByRole('checkbox', { name: /Freight · 110,000.00 USD/ });
    expect(screen.getByRole('checkbox', { name: /Hire in EUR/ })).toBeDisabled();
    await user.click(freight);
    expect(onChange).toHaveBeenCalledWith([11]);
  });

  it('shows how many lines are selected and renders nothing without a voyage', async () => {
    const { container } = wrap(<ConfirmedRevenuePicker voyageId={null} currency="USD" selected={[]} onChange={() => {}} />);
    expect(container).toBeEmptyDOMElement();
    wrap(<ConfirmedRevenuePicker voyageId={7} currency="USD" selected={[11, 12]} onChange={() => {}} />);
    expect(await screen.findByText(/2 selected/)).toBeInTheDocument();
    const [group] = screen.getAllByText(/Confirmed revenue to bill/);
    expect(within(group.parentElement as HTMLElement).getAllByRole('checkbox', { checked: true })).toHaveLength(2);
  });
});
