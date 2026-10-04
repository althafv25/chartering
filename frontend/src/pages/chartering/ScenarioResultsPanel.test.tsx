import { render, screen } from '@testing-library/react';
import { ThemeProvider } from '@mui/material';
import { theme } from '../../theme/theme';
import { ScenarioResultsPanel, TraceTable } from './ScenarioResultsPanel';
import type { ScenarioResult } from '../../types/chartering';

const result: ScenarioResult = {
  calculation_version: '1.0.0', inputs_hash: 'abcdef1234567890', sea_distance_nm: '3600.00', eca_distance_nm: '0.00', sea_days: '12.500000',
  eca_sea_days: '0.000000', port_days: '4.000000', total_days: '16.500000', fuel_total_mt: '262.000', fuel_cost: '159600.00', port_costs: '60000.00',
  agency_costs: '0.00', canal_costs: '0.00', other_costs: '0.00', operational_costs: '0.00', tonnage_cost: '0.00', gross_revenue: '750000.00',
  total_commission: '37500.00', net_revenue: '712500.00', voyage_costs: '219600.00', total_costs: '219600.00', profit: '492900.00',
  profit_margin_pct: '65.7200', profit_per_day: '29872.73', tce_per_day: '29872.73', breakeven_rate: '4.6232', breakeven_basis: 'per_mt',
  breakeven_item: 'Freight', warnings: ['ECA distance present but no ECA fuel selected'], calculated_at: '2026-10-01T12:00:00Z',
  breakdown: { fuel: [{ fuel_type_id: 2, code: 'VLSFO', sea_mt: '250.000', port_mt: '0.000', dp_mt: '0.000', standby_mt: '0.000', total_mt: '250.000', cost: '150000.00', account: 'owner' }],
    legs: [], calls: [], revenue: [{ key: 'r1', description: 'Freight', basis: 'per_mt', quantity: '50000', rate: '15', currency: 'USD', amount: '750000.00', commission_pct: '5', commission: '37500.00', primary: true }],
    costs: [], charterer_account: { fuel: '0.00' } },
  trace: [{ ref: 'E2.leg1', label: 'Leg 1: base sea days', formula: '1200 NM / (12 kn × 24)', value: '4.166666666666' }],
};

const wrap = (ui: React.ReactNode) => render(<ThemeProvider theme={theme}>{ui}</ThemeProvider>);

describe('ScenarioResultsPanel', () => {
  it('displays backend values verbatim (grouped, never recalculated)', () => {
    wrap(<ScenarioResultsPanel result={result} currency="USD" type="voyage_charter" />);
    expect(screen.getAllByText('492,900.00 USD').length).toBeGreaterThan(0);
    expect(screen.getAllByText('29,872.73 USD').length).toBeGreaterThan(0);
    expect(screen.getByText('4.6232 USD')).toBeInTheDocument();
    expect(screen.getByText('ECA distance present but no ECA fuel selected')).toBeInTheDocument();
    expect(screen.getByText(/engine v1\.0\.0/)).toBeInTheDocument();
  });

  it('labels profit as relet margin for cargo relet', () => {
    wrap(<ScenarioResultsPanel result={result} currency="USD" type="cargo_relet" />);
    expect(screen.getAllByText('Net relet margin').length).toBeGreaterThan(0);
    expect(screen.getByText('Tonnage / head charter')).toBeInTheDocument();
  });

  it('renders the calculation trace with exact values', () => {
    wrap(<TraceTable steps={result.trace!} />);
    expect(screen.getByText('E2.leg1')).toBeInTheDocument();
    expect(screen.getByText('1200 NM / (12 kn × 24)')).toBeInTheDocument();
    expect(screen.getByText('4.166666666666')).toBeInTheDocument();
  });
});
