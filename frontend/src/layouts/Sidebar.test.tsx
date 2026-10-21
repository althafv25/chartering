import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../theme/theme';
import { AuthContext, type AuthContextValue } from '../auth/context';
import { P } from '../constants/permissions';
import { Sidebar } from './Sidebar';

vi.mock('../api/ais', () => ({ aisApi: { status: vi.fn(() => Promise.resolve({ enabled: false })) } }));

function renderSidebar(props: Partial<Parameters<typeof Sidebar>[0]> = {}) {
  const auth = {
    user: { permissions: [P.DashboardView, P.VesselsView, P.CompaniesView], is_super_admin: false, timezone: 'UTC' },
    can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => [P.DashboardView, P.VesselsView, P.CompaniesView].includes(x as never)),
    isAuthenticated: true, isLoading: false,
  } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={auth}>
          <MemoryRouter><Sidebar {...props} /></MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>
    </ThemeProvider>,
  );
}

describe('Sidebar', () => {
  it('shows labels and a "Collapse sidebar" toggle in expanded mode', () => {
    renderSidebar({ onToggleCollapsed: vi.fn() });
    expect(screen.getByText('Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Masters')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Collapse sidebar' })).toBeInTheDocument();
  });

  it('hides labels and offers "Expand sidebar" when collapsed, keeping navigation reachable via icons', () => {
    renderSidebar({ collapsed: true, onToggleCollapsed: vi.fn() });
    expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();
    expect(screen.queryByText('Masters')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /dashboard/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Expand sidebar' })).toBeInTheDocument();
  });

  it('calls onToggleCollapsed when the toggle button is clicked', async () => {
    const onToggleCollapsed = vi.fn();
    const { default: userEvent } = await import('@testing-library/user-event');
    renderSidebar({ onToggleCollapsed });
    await userEvent.click(screen.getByRole('button', { name: 'Collapse sidebar' }));
    expect(onToggleCollapsed).toHaveBeenCalledTimes(1);
  });

  it('does not render a collapse toggle when onToggleCollapsed is not provided (mobile drawer)', () => {
    renderSidebar();
    expect(screen.queryByRole('button', { name: /collapse sidebar|expand sidebar/i })).not.toBeInTheDocument();
  });
});
