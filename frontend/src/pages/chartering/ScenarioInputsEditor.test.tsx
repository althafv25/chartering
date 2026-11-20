import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ScenarioInputsEditor } from './ScenarioInputsEditor';
import { referenceApi } from '../../api/masters';
import type { ScenarioInputs } from '../../types/chartering';

vi.mock('../../api/masters', () => ({ referenceApi: { list: vi.fn().mockResolvedValue([]) } }));
vi.mock('../../components/MasterPickers', () => ({ CurrencySelect: () => null }));

const money = { amount: '0', currency: 'USD', fx_rate: '1', account: 'owner' as const };
const initial = {
  sea_margin_pct: '0', eca_fuel_type_id: null, legs: [], consumption: [], fuel_prices: [], revenue_items: [], cost_items: [],
  calls: [{ label: 'Jebel Ali', kind: 'port', working_days: '0', idle_days: '0', waiting_days: '0', dp_days: '0', standby_days: '0', port_cost: money, agency_cost: money }],
} as unknown as ScenarioInputs;

function Harness({ onChange, estimationType }: { onChange: (i: ScenarioInputs) => void; estimationType?: string }) {
  const [inputs, setInputs] = useState(initial);
  return (
    <QueryClientProvider client={new QueryClient()}>
      <ScenarioInputsEditor inputs={inputs} currency="USD" readOnly={false} patch={(fn) => {
        const next = structuredClone(inputs);
        fn(next);
        setInputs(next);
        onChange(next);
      }} estimationType={estimationType} />
    </QueryClientProvider>
  );
}

describe('ScenarioInputsEditor decimal fields', () => {
  it('lets the user replace the 0 in a duration field instead of appending to it', async () => {
    const user = userEvent.setup();
    let latest = initial;
    render(<Harness onChange={(i) => { latest = i; }} />);
    const working = screen.getByLabelText('Working') as HTMLInputElement;

    await user.click(working);
    await user.keyboard('3');
    expect(working.value).toBe('3');
    expect(latest.calls[0].working_days).toBe('3');
  });

  it('can be cleared completely, stores 0 and shows it again on blur', async () => {
    const user = userEvent.setup();
    let latest = initial;
    render(<Harness onChange={(i) => { latest = i; }} />);
    const idle = screen.getByLabelText('Idle') as HTMLInputElement;

    await user.click(idle);
    await user.keyboard('2.5');
    expect(idle.value).toBe('2.5');
    await user.clear(idle);
    expect(idle.value).toBe('');
    expect(latest.calls[0].idle_days).toBe('0');
    await user.tab();
    expect(idle.value).toBe('0');
  });
});

describe('ScenarioInputsEditor time charter defaults', () => {
  const categories = [{ id: 1, code: 'FREIGHT', name: 'Freight', is_commissionable: true }, { id: 2, code: 'HIRE', name: 'Hire', is_commissionable: true }];
  it('adds hire per day, and port calls on the charterer account, for a time charter', async () => {
    vi.mocked(referenceApi.list).mockImplementation((async (type: string) => (type === 'revenue-categories' ? categories : [])) as never);
    const user = userEvent.setup();
    let latest = initial;
    render(<Harness onChange={(i) => { latest = i; }} estimationType="time_charter" />);
    await screen.findByRole('button', { name: /add revenue/i });
    await user.click(screen.getByRole('button', { name: /add revenue/i }));
    expect(latest.revenue_items[0]).toMatchObject({ revenue_category_id: 2, basis: 'per_day' });
    await user.click(screen.getByRole('button', { name: /add call/i }));
    expect(latest.calls[1].port_cost?.account).toBe('charterer');
    expect(latest.calls[1].agency_cost?.account).toBe('charterer');
  });
  it('keeps the owner account and a lump sum for other estimation types', async () => {
    vi.mocked(referenceApi.list).mockImplementation((async (type: string) => (type === 'revenue-categories' ? categories : [])) as never);
    const user = userEvent.setup();
    let latest = initial;
    render(<Harness onChange={(i) => { latest = i; }} estimationType="voyage_charter" />);
    await screen.findByRole('button', { name: /add revenue/i });
    await user.click(screen.getByRole('button', { name: /add revenue/i }));
    expect(latest.revenue_items[0]).toMatchObject({ revenue_category_id: 1, basis: 'lump_sum' });
    await user.click(screen.getByRole('button', { name: /add call/i }));
    expect(latest.calls[1].port_cost?.account).toBe('owner');
  });
});
