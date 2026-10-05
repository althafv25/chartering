import { useNavigate } from 'react-router-dom';
import { Avatar, Box, Button, Card, CardContent, CardHeader, Chip, Divider, Grid, List, ListItem, ListItemIcon, ListItemText, Stack, Typography } from '@mui/material';
import ArrowForwardRounded from '@mui/icons-material/ArrowForwardRounded';
import CalendarTodayOutlined from '@mui/icons-material/CalendarTodayOutlined';
import CheckCircleOutline from '@mui/icons-material/CheckCircleOutline';
import DirectionsBoatOutlined from '@mui/icons-material/DirectionsBoatOutlined';
import NotificationsNoneOutlined from '@mui/icons-material/NotificationsNoneOutlined';
import PendingActionsOutlined from '@mui/icons-material/PendingActionsOutlined';
import SailingOutlined from '@mui/icons-material/SailingOutlined';
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined';
import TaskAltOutlined from '@mui/icons-material/TaskAltOutlined';
import PaymentsOutlined from '@mui/icons-material/PaymentsOutlined';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '../components/PageHeader';
import { StatCard } from '../components/StatCard';
import { ErrorState, SectionLoader } from '../components/Feedback';
import { useAuth } from '../auth/useAuth';
import { P } from '../constants/permissions';
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
  const { user, can } = useAuth();
  const navigate = useNavigate();
  const summary = useQuery({ queryKey: ['dashboard'], queryFn: dashboardApi.summary });
  const { data: notifications } = useQuery({ queryKey: ['notifications', 'dashboard'], queryFn: () => notificationsApi.list({ per_page: 5 }) });

  const s = summary.data;
  const pendingTotal = s ? Object.values(s.pending_approvals).reduce((a, b) => a + b, 0) : 0;

  return (
    <>
      <PageHeader
        title={`Welcome, ${user?.first_name || user?.name}`}
        subtitle="Fleet, chartering and commercial overview"
        actions={<Chip icon={<CalendarTodayOutlined sx={{ fontSize: 16 }} />} label="Live overview" color="secondary" variant="outlined" />}
      />
      <Card sx={{ mb: 3, overflow: 'hidden', position: 'relative' }}>
        <Box sx={{ position: 'absolute', inset: 0, background: 'linear-gradient(115deg, rgba(31,143,184,0.08), transparent 48%)', pointerEvents: 'none' }} />
        <CardContent sx={{ p: { xs: 2.5, md: 3 }, '&:last-child': { pb: { xs: 2.5, md: 3 } } }}>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} justifyContent="space-between" alignItems={{ md: 'center' }} position="relative">
            <Box>
              <Typography variant="overline" color="secondary.main" fontWeight={700} letterSpacing="0.12em">Operations cockpit</Typography>
              <Typography variant="h5" sx={{ mt: 0.25 }}>A clear view of what needs attention.</Typography>
              <Typography color="text.secondary" sx={{ mt: 0.5, maxWidth: 660 }}>
                Monitor fleet activity, commercial performance and approvals from one place.
              </Typography>
            </Box>
            {can(P.VesselsView) && (
              <Button variant="outlined" color="primary" endIcon={<ArrowForwardRounded />} onClick={() => navigate('/fleet/vessels')} sx={{ alignSelf: { xs: 'stretch', md: 'center' } }}>
                View fleet
              </Button>
            )}
          </Stack>
        </CardContent>
      </Card>
      {summary.isLoading ? <SectionLoader /> : summary.isError ? <ErrorState error={summary.error} onRetry={() => summary.refetch()} /> : s && (
        <Grid container spacing={2.5} sx={{ mb: 3 }}>
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

      <Grid container spacing={2.5}>
        <Grid size={{ xs: 12, md: 7 }}>
          <Card sx={{ height: '100%', overflow: 'hidden' }}>
            <CardHeader
              avatar={<Avatar sx={{ bgcolor: 'rgba(31,143,184,0.12)', color: 'secondary.main' }}><NotificationsNoneOutlined /></Avatar>}
              title="Recent notifications"
              subheader="Latest updates from your workspace"
              action={notifications?.data.length ? <Chip size="small" label={`${notifications.data.length} recent`} variant="outlined" color="secondary" /> : undefined}
            />
            <Divider />
            <CardContent sx={{ p: 1.5, '&:last-child': { pb: 1.5 } }}>
              {notifications?.data.length ? (
                <List disablePadding>
                  {notifications.data.map((n) => (
                    <ListItem key={n.id} sx={{ px: 1.5, py: 1.25, borderRadius: 2, '&:not(:last-child)': { borderBottom: 1, borderColor: 'divider' } }}>
                      <ListItemIcon sx={{ minWidth: 40, alignSelf: 'flex-start', mt: 0.25 }}>
                        <Avatar sx={{ width: 30, height: 30, bgcolor: n.read_at ? 'action.hover' : 'rgba(31,143,184,0.12)', color: n.read_at ? 'text.secondary' : 'secondary.main' }}>
                          <NotificationsNoneOutlined sx={{ fontSize: 17 }} />
                        </Avatar>
                      </ListItemIcon>
                      <ListItemText primary={n.title} secondary={<>{n.message}<br /><Typography component="span" variant="caption" color="text.disabled">{timeAgo(n.created_at)}</Typography></>} slotProps={{ primary: { fontWeight: n.read_at ? 500 : 700, fontSize: 14 }, secondary: { component: 'span', fontSize: 13 } }} />
                    </ListItem>
                  ))}
                </List>
              ) : (
                <Stack alignItems="center" spacing={1} sx={{ py: 5 }}>
                  <NotificationsNoneOutlined color="disabled" />
                  <Typography variant="body2" color="text.secondary">No notifications yet.</Typography>
                </Stack>
              )}
              <Box sx={{ display: 'flex', justifyContent: 'flex-end', px: 1, pt: 1 }}>
                <Button size="small" endIcon={<ArrowForwardRounded />} onClick={() => navigate('/notifications')}>View all</Button>
              </Box>
            </CardContent>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, md: 5 }}>
          <Card sx={{ height: '100%', overflow: 'hidden' }}>
            <CardHeader
              avatar={<Avatar sx={{ bgcolor: 'rgba(46,125,91,0.12)', color: 'success.main' }}><PendingActionsOutlined /></Avatar>}
              title="Waiting for your approval"
              subheader="Items that need your decision"
              action={<Chip size="small" label={pendingTotal} color={pendingTotal ? 'warning' : 'success'} variant="outlined" />}
            />
            <Divider />
            <CardContent sx={{ p: 1.5, '&:last-child': { pb: 1.5 } }}>
              {s && Object.keys(s.pending_approvals).length > 0 ? (
                <Stack spacing={1}>
                  {Object.entries(s.pending_approvals).map(([area, count]) => (
                    <Stack key={area} direction="row" alignItems="center" spacing={1.5} sx={{ p: 1.25, border: 1, borderColor: 'divider', borderRadius: 2, cursor: 'pointer', transition: 'all 160ms ease', '&:hover': { borderColor: 'secondary.light', bgcolor: 'action.hover', transform: 'translateX(2px)' } }} onClick={() => navigate(APPROVAL_LINKS[area]?.to ?? '/')}>
                      <Avatar sx={{ width: 30, height: 30, bgcolor: count > 0 ? 'rgba(199,119,0,0.12)' : 'action.hover', color: count > 0 ? 'warning.main' : 'text.secondary' }}><PendingActionsOutlined sx={{ fontSize: 17 }} /></Avatar>
                      <Typography variant="body2" fontWeight={600} sx={{ flex: 1 }}>{APPROVAL_LINKS[area]?.label ?? area}</Typography>
                      <Typography variant="body2" fontWeight={700} color={count > 0 ? 'warning.main' : 'text.secondary'}>{count}</Typography>
                      <ArrowForwardRounded sx={{ fontSize: 18, color: 'text.disabled' }} />
                    </Stack>
                  ))}
                </Stack>
              ) : (
                <Stack alignItems="center" spacing={1} sx={{ py: 5, textAlign: 'center' }}>
                  <CheckCircleOutline sx={{ color: 'success.main', fontSize: 34 }} />
                  <Typography variant="body2" color="text.secondary">Nothing to approve right now.</Typography>
                  <Typography variant="caption" color="text.disabled">Your approval queue is clear.</Typography>
                </Stack>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </>
  );
}
