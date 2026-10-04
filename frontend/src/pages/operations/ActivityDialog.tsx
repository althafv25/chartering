import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Divider, Grid, IconButton, MenuItem, Stack, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { offshoreActivitiesApi, offshoreProjectsApi } from '../../api/offshore';
import { voyagesApi } from '../../api/operations';
import { contractsApi } from '../../api/contracts';
import { locationsApi, vesselsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { ReferenceSelect } from '../../components/MasterPickers';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { useNotify } from '../../hooks/useNotify';
import { isDecimal } from '../../utils/decimal';
import { zoneLabel } from '../../utils/format';
import type { OffshoreActivity } from '../../types/offshore';

interface Props {
  open: boolean;
  onClose: () => void;
  activity: OffshoreActivity | null;
  /** Presets when opened from a voyage or project. */
  preset?: { vessel_id?: number; voyage_id?: number; offshore_project_id?: number };
}

type Form = {
  vessel_id: string; voyage_id: string; offshore_project_id: string; contract_id: string; offshore_location_id: string; offshore_activity_type_id: number | '';
  start_at: string; end_at: string; billable_hours: string; non_billable_hours: string; standby_hours: string; description: string; remarks: string;
};
const HOURS = [['billable_hours', 'Billable h'], ['non_billable_hours', 'Non-billable h'], ['standby_hours', 'Standby h']] as const;

export function ActivityDialog({ open, onClose, activity, preset }: Props) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { user } = useAuth();
  const [f, setF] = useState<Form | null>(null);
  const [fuel, setFuel] = useState<{ fuel_type_id: number; mt: string }[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setError(null);
    const a = activity;
    setF({
      vessel_id: String(a?.vessel_id ?? preset?.vessel_id ?? ''), voyage_id: String(a?.voyage_id ?? preset?.voyage_id ?? ''),
      offshore_project_id: String(a?.offshore_project_id ?? preset?.offshore_project_id ?? ''), contract_id: String(a?.contract_id ?? ''),
      offshore_location_id: String(a?.offshore_location_id ?? ''), offshore_activity_type_id: a?.offshore_activity_type_id ?? '',
      start_at: a?.start_at_local ?? '', end_at: a?.end_at_local ?? '',
      billable_hours: a?.billable_hours ?? '', non_billable_hours: a?.non_billable_hours ?? '', standby_hours: a?.standby_hours ?? '',
      description: a?.description ?? '', remarks: a?.remarks ?? '',
    });
    setFuel(a?.fuel_used ?? []);
  }, [open, activity]); // eslint-disable-line react-hooks/exhaustive-deps -- preset is read once when the dialog opens

  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }), enabled: open });
  const vessel = f?.vessel_id;
  const voyages = useQuery({ queryKey: ['voyages', { vessel, open: 1 }], queryFn: () => voyagesApi.list({ vessel_id: vessel, open: 1, per_page: 50 }), enabled: open && !!vessel });
  const contracts = useQuery({ queryKey: ['contracts', { vessel }], queryFn: () => contractsApi.list({ vessel_id: vessel, per_page: 50 }), enabled: open && !!vessel });
  const projects = useQuery({ queryKey: ['offshore-projects', 'open'], queryFn: () => offshoreProjectsApi.list({ per_page: 100 }), enabled: open });
  const locations = useQuery({ queryKey: ['offshore-locations', 'all'], queryFn: () => locationsApi.list({ per_page: 100 }), enabled: open });

  const save = useMutation({
    mutationFn: () => {
      const num = (v: string) => (v === '' ? null : Number(v));
      const body: Record<string, unknown> = {
        vessel_id: num(f!.vessel_id), voyage_id: num(f!.voyage_id), offshore_project_id: num(f!.offshore_project_id), contract_id: num(f!.contract_id),
        offshore_location_id: num(f!.offshore_location_id), offshore_activity_type_id: f!.offshore_activity_type_id, start_at: f!.start_at, end_at: f!.end_at,
        description: f!.description || null, remarks: f!.remarks || null, fuel_used: fuel.length ? fuel : null,
      };
      // Leave the split empty on a new activity to take the default from the activity type.
      if (activity || HOURS.some(([k]) => f![k] !== '')) for (const [k] of HOURS) body[k] = f![k] || '0';
      if (activity) body.lock_version = activity.lock_version;
      return offshoreActivitiesApi.save(activity?.id ?? null, body);
    },
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['offshore-activities'] }); qc.invalidateQueries({ queryKey: ['offshore-projects'] }); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  if (!f) return null;
  const set = (k: keyof Form) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });
  const loc = locations.data?.data.find((l) => String(l.id) === f.offshore_location_id);
  const tz = loc?.timezone ?? user?.timezone ?? 'UTC';
  const hoursOk = HOURS.every(([k]) => f[k] === '' || isDecimal(f[k], 4)) && fuel.every((l) => l.fuel_type_id && isDecimal(l.mt, 3));
  const valid = f.vessel_id && f.offshore_activity_type_id && f.start_at && f.end_at && hoursOk;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>{activity ? `Edit ${activity.activity_number}` : 'New offshore activity'}</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        {activity?.decision_comment && activity.status === 'draft' && <Alert severity="warning" sx={{ mb: 2 }}>Returned: {activity.decision_comment}</Alert>}
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField select label="Vessel" required value={f.vessel_id} disabled={!!preset?.vessel_id || !!activity}
              onChange={(e) => setF({ ...f, vessel_id: e.target.value, voyage_id: '', contract_id: '' })}>
              {(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <ReferenceSelect type="offshore-activity-types" label="Activity type" required value={f.offshore_activity_type_id}
              onChange={(v) => setF({ ...f, offshore_activity_type_id: v ?? '' })} />
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField select label="Voyage (optional)" value={f.voyage_id} disabled={!f.vessel_id || !!preset?.voyage_id} onChange={set('voyage_id')}>
              <MenuItem value="">—</MenuItem>
              {(voyages.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.voyage_number}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField select label="Project (optional)" value={f.offshore_project_id} disabled={!!preset?.offshore_project_id} onChange={set('offshore_project_id')}>
              <MenuItem value="">—</MenuItem>
              {projects.data?.data.filter((p) => p.status !== 'cancelled').map((p) => <MenuItem key={p.id} value={String(p.id)}>{p.code} — {p.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField select label="Contract" value={f.contract_id} disabled={!f.vessel_id} onChange={set('contract_id')}
              helperText="Leave empty to use the voyage or project contract">
              <MenuItem value="">—</MenuItem>
              {contracts.data?.data.filter((c) => ['approved', 'active', 'completed', 'expired'].includes(c.status))
                .map((c) => <MenuItem key={c.id} value={String(c.id)}>{c.contract_number} ({c.status})</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField select label="Location (optional)" value={f.offshore_location_id} onChange={set('offshore_location_id')}>
              <MenuItem value="">—</MenuItem>
              {(locations.data?.data ?? []).map((l) => <MenuItem key={l.id} value={String(l.id)}>{l.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 4 }}><TextField type="datetime-local" label="Start" required value={f.start_at} onChange={set('start_at')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 6, md: 4 }}><TextField type="datetime-local" label="End" required value={f.end_at} onChange={set('end_at')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 12, md: 4 }} sx={{ display: 'flex', alignItems: 'center' }}>
            <Typography variant="caption" color="text.secondary">Times in {loc ? `${loc.name} local time` : 'your time zone'} ({zoneLabel(tz)}).</Typography>
          </Grid>
          {HOURS.map(([k, label]) => (
            <Grid key={k} size={{ xs: 4 }}>
              <TextField label={label} value={f[k]} inputMode="decimal" error={f[k] !== '' && !isDecimal(f[k], 4)} onChange={set(k)} />
            </Grid>
          ))}
          <Grid size={12}>
            <Typography variant="caption" color="text.secondary">
              The three hour figures must not exceed the activity duration (OA-01). {activity ? '' : 'Leave all empty to book the whole duration as billable or non-billable according to the activity type.'}
            </Typography>
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}><TextField label="Description" multiline minRows={2} value={f.description} onChange={set('description')} /></Grid>
          <Grid size={{ xs: 12, md: 6 }}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={set('remarks')} /></Grid>
        </Grid>
        <Divider sx={{ my: 2 }} />
        <Typography variant="subtitle2">Fuel used (mt)</Typography>
        <Typography variant="caption" color="text.secondary" display="block" sx={{ mb: 1 }}>Recorded for reporting only. Fuel recharge to the client is not calculated (BR-OA-04 awaiting confirmation).</Typography>
        <Stack spacing={1}>
          {fuel.map((l, i) => (
            <Stack key={i} direction="row" spacing={1} alignItems="center">
              <Box sx={{ width: 200 }}><ReferenceSelect type="fuel-types" label="Fuel" size="small" required value={l.fuel_type_id || ''}
                onChange={(v) => setFuel((ls) => ls.map((x, j) => (j === i ? { ...x, fuel_type_id: v ?? 0 } : x)))} /></Box>
              <TextField size="small" label="mt" value={l.mt} error={!!l.mt && !isDecimal(l.mt, 3)} sx={{ width: 140 }}
                onChange={(e) => setFuel((ls) => ls.map((x, j) => (j === i ? { ...x, mt: e.target.value } : x)))} />
              <IconButton size="small" color="error" aria-label="Remove fuel line" onClick={() => setFuel((ls) => ls.filter((_, j) => j !== i))}><DeleteOutline fontSize="small" /></IconButton>
            </Stack>
          ))}
          <Box><Button size="small" startIcon={<AddIcon />} onClick={() => setFuel((ls) => [...ls, { fuel_type_id: 0, mt: '' }])}>Add fuel</Button></Box>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>Save draft</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
