import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, Grid, List, ListItem, ListItemText, Stack, Typography } from '@mui/material';
import DirectionsBoatOutlined from '@mui/icons-material/DirectionsBoatOutlined';
import SailingOutlined from '@mui/icons-material/SailingOutlined';
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined';
import TaskAltOutlined from '@mui/icons-material/TaskAltOutlined';
import PaymentsOutlined from '@mui/icons-material/PaymentsOutlined';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { StatCard } from '../components/StatCard';
import { ErrorState, SectionLoader } from '../components/Feedback';
import { useAuth } from '../auth/useAuth';
import { notificationsApi } from '../api/endpoints';
import { dashboardApi } from '../api/finance';
import { timeAgo } from '../utils/format';
import { money } from '../utils/decimal';

const APPROVAL_LINKS: Record<string, { label: string; to: string }> = {
  estimations: { label: 'Estimations', to: '/chartering/estimations' },
  fixtures: { label: 'Fixtures', to: '/chartering/fixtures' },
  contracts: { label: 'Contracts', to: '/contracts' },
  invoices: { label: 'Invoices', to: '/commercial/invoices' },
  expenses: { label: 'Expenses', to: '/commercial/ledger' },
  payables: { label: 'Payables', to: '/commercial/payables' },
};

/** Live KPIs from GET /dashboard. Money figures are only returned (and shown) with commercial.financials.view. */
export default function DashboardPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const summary = useQuery({ queryKey: ['dashboard'], queryFn: dashboardApi.summary });
  const { data: notifications } = useQuery({ queryKey: ['notifications', 'dashboard'], queryFn: () => notificationsApi.list({ per_page: 5 }) });

  const s = summary.data;
  const pendingTotal = s ? Object.values(s.pending_approvals).reduce((a, b) => a + b, 0) : 0;

  return (
    <>
      <PageHeader title={`Welcome, ${user?.first_name || user?.name}`} subtitle="Fleet, chartering and commercial overview" />
      {summary.isLoading ? <SectionLoader /> : summary.isError ? <ErrorState error={summary.error} onRetry={() => summary.refetch()} /> : s && (
        <Grid container spacing={2} sx={{ mb: 3 }}>
          <Grid size={{ xs: 12, sm: 6, lg: 3 }}><StatCard label="Active Vessels" value={s.active_vessels} icon={<DirectionsBoatOutlined />} /></Grid>
          <Grid size={{ xs: 12, sm: 6, lg: 3 }}><StatCard label="Active Voyages" value={s.active_voyages} icon={<SailingOutlined />} tone="secondary" /></Grid>
          {s.outstanding_invoices && (
            <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
              <StatCard label="Outstanding Invoices" value={money(s.outstanding_invoices.base_total, s.base_currency)} icon={<ReceiptLongOutlined />} tone="warning"
                hint={`${s.outstanding_invoices.count} open · ${s.outstanding_invoices.overdue_count} overdue (${money(s.outstanding_invoices.overdue_base_total, s.base_currency)})`} />
            </Grid>
          )}
          {s.open_payables && (
            <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
              <StatCard label="Open Payables" value={money(s.open_payables.base_total, s.base_currency)} icon={<PaymentsOutlined />} tone="error"
                hint={`${s.open_payables.count} open · ${s.open_payables.overdue_count} overdue`} />
            </Grid>
          )}
          <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
            <StatCard label="Pending Approvals" value={pendingTotal} icon={<TaskAltOutlined />} tone="success"
              hint={Object.keys(s.pending_approvals).length === 0 ? 'No approval rights' : 'Items waiting for you'} />
          </Grid>
        </Grid>
      )}

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 7 }}>
          <Card>
            <CardHeader title="Recent notifications" slotProps={{ title: { variant: 'h6' } }} />
            <CardContent sx={{ pt: 0 }}>
              {notifications?.data.length ? (
                <List dense disablePadding>
                  {notifications.data.map((n) => (
                    <ListItem key={n.id} disableGutters divider>
                      <ListItemText primary={n.title} secondary={`${n.message} · ${timeAgo(n.created_at)}`} />
                    </ListItem>
                  ))}
                </List>
              ) : (
                <Typography variant="body2" color="text.secondary">No notifications yet.</Typography>
              )}
            </CardContent>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, md: 5 }}>
          <Card>
            <CardHeader title="Waiting for your approval" slotProps={{ title: { variant: 'h6' } }} />
            <CardContent sx={{ pt: 0 }}>
              {s && Object.keys(s.pending_approvals).length > 0 ? (
                <Stack spacing={1}>
                  {Object.entries(s.pending_approvals).map(([area, count]) => (
                    <Stack key={area} direction="row" justifyContent="space-between" sx={{ cursor: 'pointer' }} onClick={() => navigate(APPROVAL_LINKS[area]?.to ?? '/')}>
                      <Typography variant="body2">{APPROVAL_LINKS[area]?.label ?? area}</Typography>
                      <Typography variant="body2" fontWeight={600} color={count > 0 ? 'warning.main' : 'text.secondary'}>{count}</Typography>
                    </Stack>
                  ))}
                </Stack>
              ) : (
                <Typography variant="body2" color="text.secondary">Nothing to approve, or your role has no approval rights.</Typography>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </>
  );
}
