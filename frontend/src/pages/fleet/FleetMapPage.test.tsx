import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';
import type { AisStatus, FleetPosition } from '../../types/ais';
import FleetMapPage from './FleetMapPage';

const state: { status: AisStatus; fleet: FleetPosition[] } = {
  status: { enabled: false, provider: 'none', health: { ok: true, message: 'ok', last_error: null }, stale_hours: 6, vessels_with_position: 0, last_received_at: null },
  fleet: [],
};

vi.mock('../../api/ais', () => ({
  aisApi: { status: vi.fn(() => Promise.resolve(state.status)), fleet: vi.fn(() => Promise.resolve(state.fleet)), track: vi.fn(), recordManual: vi.fn() },
}));
vi.mock('./FleetMap', () => ({ FleetMap: ({ positions }: { positions: FleetPosition[] }) => <div data-testid="map">{positions.length} marker(s)</div> }));

function renderPage(permissions: string[]) {
  const auth = { user: { permissions, is_super_admin: false, timezone: 'UTC' }, can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true, isLoading: false } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}><SnackbarProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}><FleetMapPage /></AuthContext.Provider>
      </QueryClientProvider>
    </SnackbarProvider></ThemeProvider>,
  );
}

describe('FleetMapPage', () => {
  it('explains that AIS is off and shows no map or record button', async () => {
    state.status = { ...state.status, enabled: false };
    renderPage([P.AisView, P.AisManualPosition]);
    expect(await screen.findByText(/AIS is switched off/)).toBeInTheDocument();
    expect(screen.queryByTestId('map')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /record position/i })).not.toBeInTheDocument();
  });

  it('shows the map and table when enabled, and the record button only with the manual-position permission', async () => {
    state.status = { ...state.status, enabled: true };
    state.fleet = [{ vessel: { id: 1, code: 'GMS1', name: 'GMS Endeavour' }, latitude: '25.200000', longitude: '55.300000', sog_kn: '11.50', cog_deg: null, heading_deg: null, nav_status: null,
      destination: 'Ras Tanura', eta_reported: null, observed_at: '2026-10-20T10:00:00+00:00', provider: 'manual', is_stale: true, voyage: null }];
    const { unmount } = renderPage([P.AisView]);
    await waitFor(() => expect(screen.getByTestId('map')).toHaveTextContent('1 marker(s)'));
    expect(await screen.findByText('GMS Endeavour')).toBeInTheDocument();
    expect(screen.getByText('Stale')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /record position/i })).not.toBeInTheDocument();
    unmount();
    renderPage([P.AisView, P.AisManualPosition]);
    expect(await screen.findByRole('button', { name: /record position/i })).toBeInTheDocument();
  });
});
