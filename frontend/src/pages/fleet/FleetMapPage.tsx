import { useCallback, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, Grid, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddLocationAltOutlined from '@mui/icons-material/AddLocationAltOutlined';
import { aisApi } from '../../api/ais';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime } from '../../utils/format';
import { groupDigits } from '../../utils/decimal';
import type { FleetPosition } from '../../types/ais';
import { FleetMap } from './FleetMap';

const WINDOWS = [{ label: 'Last 24 hours', days: 1 }, { label: 'Last 7 days', days: 7 }, { label: 'Last 31 days', days: 31 }];

export default function FleetMapPage() {
  const { can } = useAuth();
  const [selected, setSelected] = useState<number | null>(null);
  const [days, setDays] = useState(1);
  const [dialog, setDialog] = useState(false);
  const status = useQuery({ queryKey: ['ais', 'status'], queryFn: aisApi.status });
  const enabled = status.data?.enabled === true;
  const fleet = useQuery({ queryKey: ['ais', 'fleet'], queryFn: aisApi.fleet, enabled, refetchInterval: 60_000 });
  const track = useQuery({
    queryKey: ['ais', 'track', selected, days],
    queryFn: () => aisApi.track(selected!, new Date(Date.now() - days * 86_400_000).toISOString(), new Date().toISOString()),
    enabled: enabled && selected !== null,
  });
  const onSelect = useCallback((id: number) => setSelected(id), []);

  const columns: Column<FleetPosition>[] = [
    { key: 'vessel', header: 'Vessel', render: (p) => <Box><Typography fontWeight={600} fontSize={14}>{p.vessel.name}</Typography><Typography variant="caption" color="text.secondary">{p.voyage?.voyage_number ?? 'No active voyage'}</Typography></Box> },
    { key: 'pos', header: 'Position', hideBelow: 'md', render: (p) => `${p.latitude}, ${p.longitude}` },
    { key: 'sog', header: 'SOG', align: 'right', render: (p) => (p.sog_kn ? `${groupDigits(p.sog_kn)} kn` : '—') },
    { key: 'dest', header: 'Destination', hideBelow: 'lg', render: (p) => p.destination ?? '—' },
    { key: 'seen', header: 'Observed (UTC)', render: (p) => formatDateTime(p.observed_at) },
    { key: 'state', header: 'Age', render: (p) => <StatusChip status={p.is_stale ? 'stale' : 'active'} label={p.is_stale ? 'Stale' : 'Fresh'} /> },
  ];

  return (
    <>
      <PageHeader title="Fleet Map" subtitle="Latest AIS positions. Advisory only: positions never change voyages, milestones or statuses." breadcrumbs={[{ label: 'Fleet' }, { label: 'Fleet Map' }]}
        actions={enabled && can(P.AisManualPosition) ? <Button variant="outlined" startIcon={<AddLocationAltOutlined />} onClick={() => setDialog(true)}>Record position</Button> : undefined} />
      {status.isLoading ? <SectionLoader /> : status.isError ? <ErrorState error={status.error} onRetry={() => status.refetch()} /> : !enabled ? (
        <Alert severity="info">AIS is switched off. An administrator can enable it under Settings → AIS. Until then positions come from captain reports.</Alert>
      ) : (
        <>
          {status.data && !status.data.health.ok && <Alert severity="warning" sx={{ mb: 2 }}>AIS provider problem: {status.data.health.last_error ?? status.data.health.message}</Alert>}
          <Card sx={{ p: 2, mb: 2 }}>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mb: 2 }} alignItems={{ md: 'center' }}>
              <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>
                Provider: {status.data?.provider} · stale after {status.data?.stale_hours} h · click a vessel to draw its track.
                {track.data && ` Track: ${groupDigits(track.data.distance_nm)} nm (great-circle estimate), ${track.data.point_count} point(s).`}
              </Typography>
              <TextField select size="small" label="Track window" value={days} onChange={(e) => setDays(Number(e.target.value))} sx={{ width: 170 }}>
                {WINDOWS.map((w) => <MenuItem key={w.days} value={w.days}>{w.label}</MenuItem>)}
              </TextField>
            </Stack>
            <FleetMap positions={fleet.data ?? []} track={selected !== null ? track.data ?? null : null} selectedVesselId={selected} onSelect={onSelect} />
          </Card>
          <Card>
            {fleet.isError ? <ErrorState error={fleet.error} onRetry={() => fleet.refetch()} /> : (
              <DataTable columns={columns} rows={fleet.data ?? []} rowKey={(p) => p.vessel.id} loading={fleet.isFetching} onRowClick={(p) => setSelected(p.vessel.id)}
                emptyTitle="No positions yet" emptyDescription="Record a position manually or connect a provider." />
            )}
          </Card>
        </>
      )}
      <PositionDialog open={dialog} onClose={() => setDialog(false)} />
    </>
  );
}

function PositionDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const fleetVessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => import('../../api/masters').then((m) => m.vesselsApi.list({ per_page: 100, status: 'active' })), enabled: open });
  const blank = { vessel_id: '' as number | '', latitude: '', longitude: '', observed_at: '', sog_kn: '', destination: '' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    // Entered in UTC; the API requires an explicit instant.
    mutationFn: () => aisApi.recordManual({ vessel_id: Number(f.vessel_id), latitude: f.latitude, longitude: f.longitude, observed_at: `${f.observed_at}:00Z`, sog_kn: f.sog_kn || undefined, destination: f.destination || undefined }),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['ais'] }); setF(blank); setError(null); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Record position</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}>
            <TextField select label="Vessel" required value={f.vessel_id} onChange={(e) => setF({ ...f, vessel_id: Number(e.target.value) })}>
              {(fleetVessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={v.id}>{v.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={6}><TextField label="Latitude (−90…90)" required value={f.latitude} onChange={(e) => setF({ ...f, latitude: e.target.value })} /></Grid>
          <Grid size={6}><TextField label="Longitude (−180…180)" required value={f.longitude} onChange={(e) => setF({ ...f, longitude: e.target.value })} /></Grid>
          <Grid size={6}><TextField type="datetime-local" label="Observed (UTC)" required value={f.observed_at} onChange={(e) => setF({ ...f, observed_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={6}><TextField label="Speed (kn)" value={f.sog_kn} onChange={(e) => setF({ ...f, sog_kn: e.target.value })} /></Grid>
          <Grid size={12}><TextField label="Destination" value={f.destination} onChange={(e) => setF({ ...f, destination: e.target.value })} /></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!f.vessel_id || !f.latitude || !f.longitude || !f.observed_at} onClick={() => save.mutate()}>Record</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
