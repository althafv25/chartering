import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';
import type { OffHire, OpsVoyage } from '../../types/operations';
import { OffHirePanel } from './OffHirePanel';
import { formatMetric } from './formatMetric';

const event = (o: Partial<OffHire>): OffHire => ({
  id: 1, voyage_id: 1, reason_code: 'breakdown', description: 'ME failure', hours: '18.0000', days: '0.7500', fuel_consumed: null, status: 'draft',
  decision_comment: null, lock_version: 0, from_at: '2026-10-16T02:00:00+00:00', to_at: '2026-10-16T20:00:00+00:00', from_at_local: '2026-10-16T06:00',
  to_at_local: '2026-10-17T00:00', timezone: 'Asia/Dubai', decided_by: null, decided_at: null, ...o,
});

const voyage = (o: Partial<OpsVoyage>): OpsVoyage => ({
  id: 1, voyage_number: 'GMS1-26-002', vessel_id: 1, fixture_id: 1, estimation_id: 1, estimation_scenario_id: 1, conversion_type: 'fixture', direct_reason: null,
  operation_type: 'voyage', currency: 'USD', status: 'sailing', created_at: '2026-10-02T00:00:00Z', remarks: null, lock_version: 0, status_reason: null,
  reopened_count: 0, commenced_at: '2026-10-14T04:00:00Z', completed_at: null, finalized_at: null, finalization_waivers: null, cancelled_at: null, status_changed_at: null,
  allowed_transitions: [], can_complete: true, is_open: true, off_hires: [event({})], ...o,
});

function renderPanel(v: OpsVoyage, permissions: string[]) {
  const auth = { user: { permissions, is_super_admin: false, timezone: 'Asia/Dubai' }, can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}>
      <SnackbarProvider>
        <QueryClientProvider client={new QueryClient()}>
          <AuthContext.Provider value={auth}><OffHirePanel voyage={v} /></AuthContext.Provider>
        </QueryClientProvider>
      </SnackbarProvider>
    </ThemeProvider>,
  );
}

describe('OffHirePanel', () => {
  it('shows server-calculated hours/days and decision buttons for an agree-permitted user', () => {
    renderPanel(voyage({}), [P.OffHireManage, P.OffHireAgree]);
    expect(screen.getByText('18')).toBeInTheDocument();
    expect(screen.getByText('0.75')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Agree' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /add off-hire/i })).toBeInTheDocument();
  });

  it('is read-only without permissions or when the voyage is closed', () => {
    const { unmount } = renderPanel(voyage({}), []);
    expect(screen.queryByRole('button', { name: 'Agree' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /add off-hire/i })).not.toBeInTheDocument();
    unmount();
    renderPanel(voyage({ is_open: false, status: 'finalized' }), [P.OffHireManage, P.OffHireAgree]);
    expect(screen.queryByRole('button', { name: /add off-hire/i })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Edit off-hire')).not.toBeInTheDocument();
  });

  it('hides agree and edit for an agreed event', () => {
    renderPanel(voyage({ off_hires: [event({ status: 'agreed' })] }), [P.OffHireManage, P.OffHireAgree]);
    expect(screen.queryByRole('button', { name: 'Agree' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Edit off-hire')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Dispute' })).toBeInTheDocument();
  });
});

describe('formatMetric', () => {
  it('formats values and signed variances without computing them', () => {
    expect(formatMetric({ unit: 'days' }, '5.08')).toBe('5.08 days');
    expect(formatMetric({ unit: 'money' }, '12500.00', true)).toBe('+12,500.00');
    expect(formatMetric({ unit: 'money' }, '-300.50', true)).toBe('-300.50');
    expect(formatMetric({ unit: 'nm' }, '0.00', true)).toBe('0.00 nm');
    expect(formatMetric({ unit: 'mt' }, null)).toBe('—');
  });
});
