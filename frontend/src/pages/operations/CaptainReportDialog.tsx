import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Divider, Grid, IconButton, MenuItem, Stack, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { captainReportsApi, voyagesApi } from '../../api/operations';
import { vesselsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { ReferenceSelect } from '../../components/MasterPickers';
import { LoadingButton } from '../../components/LoadingButton';
import { REPORT_TYPES, SHIP_OFFSETS } from '../../constants/operations';
import { useNotify } from '../../hooks/useNotify';
import { isDecimal } from '../../utils/decimal';
import type { CaptainReport, FuelLine } from '../../types/operations';
import { fromIso, toOffsetIso } from './reportTime';

const NUM_FIELDS = [
  ['latitude', 'Latitude'], ['longitude', 'Longitude'], ['speed_kn', 'Speed (kn)'], ['course_deg', 'Course (°)'],
  ['distance_since_last_nm', 'Distance since last (nm)'], ['distance_to_go_nm', 'Distance to go (nm)'],
  ['main_engine_hours', 'ME hours'], ['aux_engine_hours', 'AE hours'], ['wind_force_bft', 'Wind (Bft)'], ['delay_hours', 'Delay (h)'],
] as const;
const TEXT_FIELDS = [['wind_direction', 'Wind direction'], ['sea_state', 'Sea state'], ['weather_text', 'Weather'], ['delay_reason', 'Delay reason']] as const;
type Key = (typeof NUM_FIELDS)[number][0] | (typeof TEXT_FIELDS)[number][0] | 'activity_text' | 'remarks';

interface Props {
  open: boolean;
  onClose: () => void;
  report: CaptainReport | null;
  /** Preset vessel/voyage when opened from a voyage. */
  vesselId?: number;
  voyageId?: number | null;
}

export function CaptainReportDialog({ open, onClose, report, vesselId, voyageId }: Props) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [offset, setOffset] = useState('+04:00');
  const [f, setF] = useState<Record<string, string>>({});
  const [lines, setLines] = useState<FuelLine[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setError(null);
    const base: Record<string, string> = {
      vessel_id: String(report?.vessel_id ?? vesselId ?? ''), voyage_id: String(report?.voyage_id ?? voyageId ?? ''), port_call_id: String(report?.port_call_id ?? ''),
      report_type: report?.report_type ?? 'noon', reported_at: report ? fromIso(report.reported_at, offset) : '',
    };
    for (const [k] of [...NUM_FIELDS, ...TEXT_FIELDS]) base[k] = report?.[k] != null ? String(report[k]) : '';
    base.activity_text = report?.activity_text ?? '';
    base.remarks = report?.remarks ?? '';
    setF(base);
    setLines(report?.fuel_lines?.map((l) => ({ ...l, rob_mt: l.rob_mt ?? '' })) ?? []);
  }, [open, report]); // eslint-disable-line react-hooks/exhaustive-deps

  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }), enabled: open && !report && !vesselId });
  const voyageIdNum = Number(f.voyage_id) || null;
  const voyage = useQuery({ queryKey: ['voyages', voyageIdNum], queryFn: () => voyagesApi.get(voyageIdNum!), enabled: open && !!voyageIdNum });
  const vesselVoyages = useQuery({ queryKey: ['voyages', { vessel: f.vessel_id, open: 1 }], queryFn: () => voyagesApi.list({ vessel_id: f.vessel_id, open: 1, per_page: 50 }),
    enabled: open && !report && !voyageId && !!f.vessel_id });

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { report_type: f.report_type, reported_at: toOffsetIso(f.reported_at, offset), port_call_id: Number(f.port_call_id) || null };
      for (const [k] of [...NUM_FIELDS, ...TEXT_FIELDS]) body[k] = f[k] === '' ? null : f[k];
      body.activity_text = f.activity_text || null;
      body.remarks = f.remarks || null;
      body.fuel_lines = lines.map((l) => ({ fuel_type_id: l.fuel_type_id, rob_mt: l.rob_mt || null, consumed_mt: l.consumed_mt || '0', received_mt: l.received_mt || '0' }));
      if (report) body.lock_version = report.lock_version;
      else { body.vessel_id = Number(f.vessel_id); body.voyage_id = voyageIdNum; }
      return captainReportsApi.save(report?.id ?? null, body);
    },
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['captain-reports'] }); qc.invalidateQueries({ queryKey: ['voyages'] }); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  const set = (k: Key | string) => (e: React.ChangeEvent<HTMLInputElement>) => setF((x) => ({ ...x, [k]: e.target.value }));
  const setLine = (i: number, p: Partial<FuelLine>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...p } : l)));
  const badNumber = (k: string) => f[k] !== '' && f[k] !== undefined && !/^-?\d+(\.\d+)?$/.test(f[k]);
  const linesValid = lines.every((l) => l.fuel_type_id && [l.rob_mt, l.consumed_mt, l.received_mt].every((v) => !v || isDecimal(v, 3)));
  const valid = f.vessel_id && f.reported_at && f.report_type && !NUM_FIELDS.some(([k]) => badNumber(k)) && linesValid;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>{report ? 'Edit captain report' : 'New captain report'}</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        {report?.status === 'rejected' && report.decision_comment && <Alert severity="warning" sx={{ mb: 2 }}>Rejected: {report.decision_comment}</Alert>}
        <Grid container spacing={2}>
          {!report && !vesselId && (
            <Grid size={{ xs: 12, md: 6 }}>
              <TextField select label="Vessel" required value={f.vessel_id ?? ''} onChange={(e) => setF((x) => ({ ...x, vessel_id: e.target.value, voyage_id: '', port_call_id: '' }))}>
                {(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name} ({v.code})</MenuItem>)}
              </TextField>
            </Grid>
          )}
          {!report && !voyageId && (
            <Grid size={{ xs: 12, md: 6 }}>
              <TextField select label="Voyage (optional)" value={f.voyage_id ?? ''} disabled={!f.vessel_id} onChange={(e) => setF((x) => ({ ...x, voyage_id: e.target.value, port_call_id: '' }))}>
                <MenuItem value="">—</MenuItem>
                {(vesselVoyages.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.voyage_number}</MenuItem>)}
              </TextField>
            </Grid>
          )}
          <Grid size={{ xs: 6, md: 3 }}>
            <TextField select label="Type" value={f.report_type ?? 'noon'} onChange={set('report_type')}>
              {REPORT_TYPES.map((t) => <MenuItem key={t.value} value={t.value}>{t.label}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 4 }}><TextField type="datetime-local" label="Ship's time" required value={f.reported_at ?? ''} onChange={set('reported_at')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select label="UTC offset" value={offset} onChange={(e) => { if (report) setF((x) => ({ ...x, reported_at: fromIso(report.reported_at, e.target.value) })); setOffset(e.target.value); }}>
              {SHIP_OFFSETS.map((o) => <MenuItem key={o} value={o}>{o}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 3 }}>
            <TextField select label="Port call" value={f.port_call_id ?? ''} disabled={!voyage.data} onChange={set('port_call_id')}>
              <MenuItem value="">—</MenuItem>
              {voyage.data?.port_calls?.filter((c) => c.status !== 'cancelled').map((c) => <MenuItem key={c.id} value={String(c.id)}>{c.sequence}. {c.label}</MenuItem>)}
            </TextField>
          </Grid>
          {NUM_FIELDS.map(([k, label]) => (
            <Grid key={k} size={{ xs: 6, md: 2.4 }}><TextField label={label} value={f[k] ?? ''} inputMode="decimal" error={badNumber(k)} onChange={set(k)} /></Grid>
          ))}
          {TEXT_FIELDS.map(([k, label]) => <Grid key={k} size={{ xs: 6, md: 3 }}><TextField label={label} value={f[k] ?? ''} onChange={set(k)} /></Grid>)}
          <Grid size={{ xs: 12, md: 6 }}><TextField label="Activity" multiline minRows={2} value={f.activity_text ?? ''} onChange={set('activity_text')} /></Grid>
          <Grid size={{ xs: 12, md: 6 }}><TextField label="Remarks" multiline minRows={2} value={f.remarks ?? ''} onChange={set('remarks')} /></Grid>
        </Grid>
        <Divider sx={{ my: 2 }} />
        <Typography variant="subtitle2" sx={{ mb: 1 }}>Fuel (mt)</Typography>
        <Stack spacing={1}>
          {lines.map((l, i) => (
            <Stack key={i} direction="row" spacing={1} alignItems="center">
              <Box sx={{ width: 180 }}><ReferenceSelect type="fuel-types" label="Fuel" size="small" required value={l.fuel_type_id || ''} onChange={(v) => setLine(i, { fuel_type_id: v ?? 0 })} /></Box>
              <TextField size="small" label="ROB" value={l.rob_mt ?? ''} error={!!l.rob_mt && !isDecimal(l.rob_mt, 3)} onChange={(e) => setLine(i, { rob_mt: e.target.value })} />
              <TextField size="small" label="Consumed" value={l.consumed_mt} error={!!l.consumed_mt && !isDecimal(l.consumed_mt, 3)} onChange={(e) => setLine(i, { consumed_mt: e.target.value })} />
              <TextField size="small" label="Received" value={l.received_mt} error={!!l.received_mt && !isDecimal(l.received_mt, 3)} onChange={(e) => setLine(i, { received_mt: e.target.value })} />
              <IconButton size="small" color="error" aria-label="Remove fuel line" onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}><DeleteOutline fontSize="small" /></IconButton>
            </Stack>
          ))}
          <Box><Button size="small" startIcon={<AddIcon />} onClick={() => setLines((ls) => [...ls, { fuel_type_id: 0, rob_mt: '', consumed_mt: '', received_mt: '' }])}>Add fuel</Button></Box>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>Save draft</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
