import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Alert, Box, Card, List, ListItemButton, ListItemText, MenuItem, Stack, Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs, TextField, Typography } from '@mui/material';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import { reportsApi } from '../../api/finance';
import { PageHeader } from '../../components/PageHeader';
import { EmptyState, ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { groupDigits } from '../../utils/decimal';

const NUMERIC = /^-?\d+(\.\d+)?$/;

export default function ReportsPage() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { can } = useAuth();
  const [tab, setTab] = useState<'reports' | 'statistics'>('reports');
  const catalogue = useQuery({ queryKey: ['reports', 'catalogue'], queryFn: reportsApi.catalogue });

  return (
    <>
      <PageHeader title="Reports & Statistics" subtitle="Base-currency reports with CSV export, and grouped revenue / expense statistics." breadcrumbs={[{ label: 'Reports' }]} />
      <Card>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="reports" label="Reports" />
          {can(P.StatisticsView) && <Tab value="statistics" label="Statistics" />}
        </Tabs>
        {tab === 'statistics' ? <StatisticsView /> : catalogue.isLoading ? <SectionLoader /> : catalogue.isError ? <ErrorState error={catalogue.error} onRetry={() => catalogue.refetch()} /> : (
          <Stack direction={{ xs: 'column', md: 'row' }}>
            <List sx={{ minWidth: 260, borderRight: { md: 1 }, borderColor: 'divider' }} aria-label="Report list">
              {catalogue.data?.map((r) => (
                <ListItemButton key={r.slug} selected={r.slug === slug} onClick={() => navigate(`/reports/${r.slug}`)}><ListItemText primary={r.title} /></ListItemButton>
              ))}
            </List>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              {slug && catalogue.data?.some((r) => r.slug === slug)
                ? <ReportView key={slug} slug={slug} filters={catalogue.data.find((r) => r.slug === slug)!.filters} formats={catalogue.data.find((r) => r.slug === slug)!.formats} />
                : <EmptyState title="Select a report" description={catalogue.data?.length ? 'Pick a report from the list.' : 'No reports are available for your role.'} />}
            </Box>
          </Stack>
        )}
      </Card>
    </>
  );
}

function ReportView({ slug, filters, formats }: { slug: string; filters: string[]; formats: string[] }) {
  const notify = useNotify();
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [asOf, setAsOf] = useState('');
  const [status, setStatus] = useState('');
  const params = { from: from || undefined, to: to || undefined, as_of: asOf || undefined, status: status.trim() || undefined };
  const q = useQuery({ queryKey: ['reports', slug, params], queryFn: () => reportsApi.run(slug, params) });
  const exportAs = useMutation({ mutationFn: (format: 'csv' | 'xlsx' | 'pdf') => reportsApi.download(slug, format, params), onError: (e) => notify.error(e) });

  return (
    <Box>
      <Stack direction="row" spacing={2} alignItems="center" sx={{ p: 2 }} flexWrap="wrap" useFlexGap>
        {filters.includes('from') && <TextField type="date" size="small" label="From" value={from} onChange={(e) => setFrom(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />}
        {filters.includes('to') && <TextField type="date" size="small" label="To" value={to} onChange={(e) => setTo(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />}
        {filters.includes('status') && <TextField size="small" label="Status" placeholder="e.g. completed" value={status} onChange={(e) => setStatus(e.target.value)} sx={{ width: 160 }} />}
        {filters.includes('as_of') && <TextField type="date" size="small" label="As of" value={asOf} onChange={(e) => setAsOf(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />}
        <Box sx={{ flex: 1 }} />
        {q.data && <Typography variant="body2" color="text.secondary">{q.data.rows.length} row(s) · amounts in {q.data.base_currency}</Typography>}
        {formats.includes('csv') && <LoadingButton variant="outlined" startIcon={<DownloadOutlined />} loading={exportAs.isPending} onClick={() => exportAs.mutate('csv')}>CSV</LoadingButton>}
        {formats.includes('xlsx') && <LoadingButton variant="outlined" startIcon={<DownloadOutlined />} loading={exportAs.isPending} onClick={() => exportAs.mutate('xlsx')}>Excel</LoadingButton>}
        {formats.includes('pdf') && <LoadingButton variant="outlined" startIcon={<DownloadOutlined />} loading={exportAs.isPending} onClick={() => exportAs.mutate('pdf')}>PDF</LoadingButton>}
      </Stack>
      {q.data?.truncated && <Alert severity="warning" sx={{ mx: 2, mb: 1 }}>Showing the first {q.data.row_limit.toLocaleString()} rows only. Narrow the filters (dates, status) to see the rest; file exports are refused above their row limit rather than cut.</Alert>}
      {q.isLoading ? <SectionLoader /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : !q.data || q.data.rows.length === 0 ? <EmptyState title="No data" description="No rows match the current filters." /> : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead><TableRow>{q.data.columns.map((c) => <TableCell key={c.key} align={c.align}>{c.label}</TableCell>)}</TableRow></TableHead>
            <TableBody>
              {q.data.rows.map((row, i) => (
                <TableRow key={i} hover>
                  {q.data.columns.map((c) => {
                    const v = row[c.key];
                    return <TableCell key={c.key} align={c.align}>{v === null || v === undefined || v === '' ? '—' : c.align === 'right' && NUMERIC.test(v) ? groupDigits(v) : v}</TableCell>;
                  })}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}
    </Box>
  );
}

function StatisticsView() {
  const metrics = useQuery({ queryKey: ['statistics', 'metrics'], queryFn: reportsApi.statisticMetrics });
  const [metricKey, setMetricKey] = useState('');
  const [groupBy, setGroupBy] = useState('month');
  const metric = metrics.data?.find((m) => m.metric === metricKey) ?? metrics.data?.[0];
  const effective = metric && metric.groups.includes(groupBy) ? groupBy : 'month';
  const q = useQuery({ queryKey: ['statistics', metric?.metric, effective], queryFn: () => reportsApi.statistic(metric!.metric, { group_by: effective }), enabled: !!metric });
  const max = Math.max(1, ...(q.data?.rows.map((r) => Math.abs(Number(r.total))) ?? [1]));
  const unitLabel = q.data?.unit === 'hours' ? 'h' : q.data?.base_currency ?? '';

  if (metrics.isLoading) return <SectionLoader />;
  if (metrics.isError) return <ErrorState error={metrics.error} onRetry={() => metrics.refetch()} />;
  if (!metric) return <EmptyState title="No statistics available" description="Your role has no statistics permissions." />;

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction="row" spacing={2} sx={{ mb: 2 }}>
        <TextField select size="small" label="Metric" value={metric.metric} onChange={(e) => setMetricKey(e.target.value)} sx={{ width: 240 }}>
          {metrics.data!.map((m) => <MenuItem key={m.metric} value={m.metric}>{m.label}</MenuItem>)}
        </TextField>
        <TextField select size="small" label="Group by" value={effective} onChange={(e) => setGroupBy(e.target.value)} sx={{ width: 170 }}>
          {metric.groups.map((g) => <MenuItem key={g} value={g}>{g.replace('_', ' ')}</MenuItem>)}
        </TextField>
        {q.data && <Typography variant="body2" color="text.secondary" sx={{ alignSelf: 'center' }}>{metric.metric === 'avg-voyage-profit' ? 'Average' : 'Total'} {groupDigits(q.data.total)} {unitLabel}</Typography>}
      </Stack>
      {q.isLoading ? <SectionLoader /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : !q.data || q.data.rows.length === 0 ? <EmptyState title="No data" description="Nothing has been recorded for this metric yet." /> : (
        <Stack spacing={1.25}>
          {q.data.rows.map((r) => (
            <Box key={r.label}>
              <Stack direction="row" justifyContent="space-between">
                <Typography fontSize={13} fontWeight={600}>{r.label}</Typography>
                <Typography fontSize={13} color="text.secondary">{groupDigits(r.total)} {unitLabel} · {r.line_count} {q.data.metric === 'avg-voyage-profit' ? 'voyage(s)' : 'line(s)'}</Typography>
              </Stack>
              <Box sx={{ height: 8, borderRadius: 1, bgcolor: q.data.metric === 'expenses' ? 'error.main' : 'success.main', width: `${Math.min(100, (Math.abs(Number(r.total)) / max) * 100)}%`, minWidth: 3 }} />
            </Box>
          ))}
        </Stack>
      )}
    </Box>
  );
}
