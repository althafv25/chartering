import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Box, Button, Card, Grid, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { vesselsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { StatusChip } from '../../components/StatusChip';
import { StatCard } from '../../components/StatCard';
import { ErrorState } from '../../components/Feedback';
import { ReferenceSelect } from '../../components/MasterPickers';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { formatDateTime, humanize, timeAgo } from '../../utils/format';
import DirectionsBoatOutlined from '@mui/icons-material/DirectionsBoatOutlined';
import EventAvailableOutlined from '@mui/icons-material/EventAvailableOutlined';
import HandshakeOutlined from '@mui/icons-material/HandshakeOutlined';
import BuildOutlined from '@mui/icons-material/BuildOutlined';
import type { StatusBoardRow, StatusTrack, VesselStatusEntry } from '../../types/masters';
import { ChangeStatusDialog } from './ChangeStatusDialog';

function Cell({ entry }: { entry: VesselStatusEntry | null }) {
  if (!entry) return <Typography variant="body2" color="text.secondary">Not set</Typography>;
  return (
    <Box>
      <StatusChip status={entry.status} />
      <Typography variant="caption" color="text.secondary" display="block" title={formatDateTime(entry.effective_from)}>
        since {timeAgo(entry.effective_from)}{entry.location ? ` · ${entry.location}` : ''}
      </Typography>
    </Box>
  );
}

export default function VesselStatusBoardPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [typeId, setTypeId] = useState<number | null>(null);
  const [commercial, setCommercial] = useState('');
  const [operational, setOperational] = useState('');
  const [dialog, setDialog] = useState<{ vessel: { id: number; name: string }; track: StatusTrack } | null>(null);

  const catalogue = useQuery({ queryKey: ['vessel-status', 'catalogue'], queryFn: vesselsApi.statusCatalogue, staleTime: Infinity });
  const q = { vessel_type_id: typeId ?? undefined, commercial_status: commercial || undefined, operational_status: operational || undefined };
  const board = useQuery({ queryKey: ['vessel-status', 'board', q], queryFn: () => vesselsApi.statusBoard(q) });
  const unfiltered = useQuery({ queryKey: ['vessel-status', 'board', {}], queryFn: () => vesselsApi.statusBoard({}) });

  const kpi = useMemo(() => {
    const rows = unfiltered.data ?? [];
    const count = (pred: (r: StatusBoardRow) => boolean) => rows.filter(pred).length;
    return {
      active: rows.length,
      available: count((r) => ['available', 'open'].includes(r.commercial?.status ?? '')),
      onHire: count((r) => ['on_hire', 'under_charter'].includes(r.commercial?.status ?? '')),
      unavailable: count((r) => ['maintenance', 'dry_dock'].includes(r.operational?.status ?? '') || r.commercial?.status === 'off_hire'),
    };
  }, [unfiltered.data]);

  const editable = can(P.VesselStatusUpdate);
  const columns: Column<StatusBoardRow>[] = [
    { key: 'vessel', header: 'Vessel', render: (r) => <Box><Typography fontWeight={600} fontSize={14}>{r.vessel.name}</Typography><Typography variant="body2" color="text.secondary">{r.vessel.code} · {r.vessel.type}</Typography></Box> },
    { key: 'commercial', header: 'Commercial', render: (r) => <Cell entry={r.commercial} /> },
    { key: 'operational', header: 'Operational', render: (r) => <Cell entry={r.operational} /> },
    { key: 'actions', header: '', align: 'right', render: (r) => editable && (
      <Stack direction="row" spacing={0.5} justifyContent="flex-end" onClick={(e) => e.stopPropagation()}>
        <Button size="small" onClick={() => setDialog({ vessel: r.vessel, track: 'commercial' })}>Commercial</Button>
        <Button size="small" onClick={() => setDialog({ vessel: r.vessel, track: 'operational' })}>Operational</Button>
      </Stack>
    ) },
  ];

  return (
    <>
      <PageHeader title="Vessel status" subtitle="Commercial availability and operational state of the active fleet"
        breadcrumbs={[{ label: 'Fleet' }, { label: 'Vessel status' }]} />
      <Grid container spacing={2} sx={{ mb: 2 }}>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label="Active vessels" value={kpi.active} icon={<DirectionsBoatOutlined />} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label="Available / open" value={kpi.available} icon={<EventAvailableOutlined />} tone="success" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label="On hire / under charter" value={kpi.onHire} icon={<HandshakeOutlined />} tone="secondary" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><StatCard label="Off hire / maintenance" value={kpi.unavailable} icon={<BuildOutlined />} tone="warning" /></Grid>
      </Grid>
      <Card>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ p: 2 }}>
          <ReferenceSelect type="vessel-types" label="Vessel type" value={typeId} allowEmpty onChange={setTypeId} sx={{ maxWidth: { md: 240 } }} />
          <TextField select label="Commercial status" value={commercial} onChange={(e) => setCommercial(e.target.value)} sx={{ maxWidth: { md: 220 } }}>
            <MenuItem value="">All</MenuItem>{catalogue.data?.commercial.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select label="Operational status" value={operational} onChange={(e) => setOperational(e.target.value)} sx={{ maxWidth: { md: 220 } }}>
            <MenuItem value="">All</MenuItem>{catalogue.data?.operational.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
        </Stack>
        {board.isError ? <ErrorState error={board.error} onRetry={() => board.refetch()} /> : (
          <DataTable columns={columns} rows={board.data ?? []} rowKey={(r) => r.vessel.id} loading={board.isFetching}
            onRowClick={(r) => navigate(`/fleet/vessels/${r.vessel.id}`)} emptyTitle="No vessels match" />
        )}
      </Card>
      <ChangeStatusDialog open={!!dialog} vessel={dialog?.vessel ?? null} track={dialog?.track} onClose={() => setDialog(null)} />
    </>
  );
}
