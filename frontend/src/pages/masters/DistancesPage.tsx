import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Card, CardContent, CardHeader, Grid, IconButton, Stack, TextField, Tooltip, Typography } from '@mui/material';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { distancesApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingButton } from '../../components/LoadingButton';
import { RoutePointAutocomplete } from '../../components/MasterPickers';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { decimalPattern } from '../../constants/masters';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime, humanize } from '../../utils/format';
import type { DistanceResult, RoutePoint, StoredDistance } from '../../types/masters';

export default function DistancesPage() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [from, setFrom] = useState<RoutePoint | null>(null);
  const [to, setTo] = useState<RoutePoint | null>(null);
  const [result, setResult] = useState<DistanceResult | null>(null);
  const [manual, setManual] = useState({ distance_nm: '', eca_distance_nm: '', notes: '' });
  const [page, setPage] = useState(1);

  const pair = from && to ? { from_type: from.type, from_id: from.id, to_type: to.type, to_id: to.id } : null;
  const list = useQuery({ queryKey: ['distances', page], queryFn: () => distancesApi.list({ page, per_page: 15 }), placeholderData: keepPreviousData });

  const calc = useMutation({
    mutationFn: () => distancesApi.calculate(pair!),
    onSuccess: setResult,
    onError: (e) => { setResult(null); notify.error(e); },
  });
  const save = useMutation({
    mutationFn: () => distancesApi.save({ ...pair!, distance_nm: manual.distance_nm, eca_distance_nm: manual.eca_distance_nm || '0', notes: manual.notes || undefined }),
    onSuccess: (r) => { notify.success(r.message); setManual({ distance_nm: '', eca_distance_nm: '', notes: '' }); qc.invalidateQueries({ queryKey: ['distances'] }); calc.mutate(); },
    onError: (e) => notify.error(e),
  });
  const remove = useMutation({
    mutationFn: (id: number) => distancesApi.remove(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['distances'] }),
    onError: (e) => notify.error(e),
  });

  const nmValid = decimalPattern(2).test(manual.distance_nm);
  const ecaValid = !manual.eca_distance_nm || decimalPattern(2).test(manual.eca_distance_nm);

  const columns: Column<StoredDistance>[] = [
    { key: 'route', header: 'Route', render: (d) => <Typography fontSize={14}><b>{d.from.label}</b> → <b>{d.to.label}</b>{d.route_key ? ` (${d.route_key})` : ''}</Typography> },
    { key: 'nm', header: 'Distance', align: 'right', render: (d) => `${d.distance_nm} NM` },
    { key: 'eca', header: 'ECA', align: 'right', hideBelow: 'md', render: (d) => `${d.eca_distance_nm} NM` },
    { key: 'provider', header: 'Source', render: (d) => <StatusChip status={d.provider} /> },
    { key: 'when', header: 'Updated', hideBelow: 'lg', render: (d) => `${formatDateTime(d.calculated_at)}${d.created_by ? ` · ${d.created_by}` : ''}` },
    { key: 'actions', header: '', align: 'right', render: (d) => can(P.DistancesOverride) && (
      <Tooltip title="Delete"><IconButton size="small" color="error" aria-label="Delete distance" onClick={() => remove.mutate(d.id)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>
    ) },
  ];

  return (
    <>
      <PageHeader title="Distances" subtitle="Sea distances between ports and offshore locations (provider-independent, cached)"
        breadcrumbs={[{ label: 'Masters' }, { label: 'Distances' }]} />
      <Grid container spacing={2} sx={{ mb: 2 }}>
        <Grid size={{ xs: 12, lg: 7 }}>
          <Card sx={{ height: '100%' }}>
            <CardHeader title="Distance lookup" slotProps={{ title: { variant: 'h6' } }} />
            <CardContent sx={{ pt: 0 }}>
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} alignItems="center">
                <Box sx={{ flex: 1, width: '100%' }}><RoutePointAutocomplete label="From" value={from} onChange={(v) => { setFrom(v); setResult(null); }} /></Box>
                <Tooltip title="Swap"><IconButton aria-label="Swap origin and destination" onClick={() => { setFrom(to); setTo(from); setResult(null); }}><SwapHorizIcon /></IconButton></Tooltip>
                <Box sx={{ flex: 1, width: '100%' }}><RoutePointAutocomplete label="To" value={to} onChange={(v) => { setTo(v); setResult(null); }} /></Box>
                <LoadingButton variant="contained" disabled={!pair} loading={calc.isPending} onClick={() => calc.mutate()}>Calculate</LoadingButton>
              </Stack>
              {result && (
                <Box sx={{ mt: 2 }}>
                  <Typography variant="h5">{result.distance_nm} NM</Typography>
                  <Typography variant="body2" color="text.secondary">
                    ECA {result.eca_distance_nm} NM · source {humanize(result.provider)}{result.reversed ? ' (stored in reverse direction)' : ''}
                  </Typography>
                  {result.is_estimate && <Alert severity="warning" sx={{ mt: 1.5 }}>{result.notes}</Alert>}
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>
        {can(P.DistancesOverride) && (
          <Grid size={{ xs: 12, lg: 5 }}>
            <Card sx={{ height: '100%' }}>
              <CardHeader title="Manual distance" subheader="Overrides provider values for this route" slotProps={{ title: { variant: 'h6' } }} />
              <CardContent sx={{ pt: 0 }}>
                <Grid container spacing={1.5}>
                  <Grid size={6}><TextField label="Distance (NM)" required inputMode="decimal" value={manual.distance_nm} error={!!manual.distance_nm && !nmValid}
                    onChange={(e) => setManual((m) => ({ ...m, distance_nm: e.target.value }))} /></Grid>
                  <Grid size={6}><TextField label="of which ECA (NM)" inputMode="decimal" value={manual.eca_distance_nm} error={!ecaValid}
                    onChange={(e) => setManual((m) => ({ ...m, eca_distance_nm: e.target.value }))} /></Grid>
                  <Grid size={12}><TextField label="Source / notes" value={manual.notes} onChange={(e) => setManual((m) => ({ ...m, notes: e.target.value }))} helperText="e.g. BA chart, master's report" /></Grid>
                  <Grid size={12}><LoadingButton variant="outlined" fullWidth disabled={!pair || !nmValid || !ecaValid} loading={save.isPending} onClick={() => save.mutate()}>Save for selected route</LoadingButton></Grid>
                </Grid>
              </CardContent>
            </Card>
          </Grid>
        )}
      </Grid>
      <Card>
        <CardHeader title="Stored distances" slotProps={{ title: { variant: 'h6' } }} />
        <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(d) => d.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
          emptyTitle="No stored distances" emptyDescription="Manual entries and provider results are cached here." />
      </Card>
    </>
  );
}
