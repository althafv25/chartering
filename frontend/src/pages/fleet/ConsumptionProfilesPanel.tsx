import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, CardHeader, Chip, DialogActions, DialogContent, FormControlLabel, Grid, IconButton, MenuItem, Stack, Switch, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { vesselsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { EmptyState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { ReferenceSelect } from '../../components/MasterPickers';
import { CONSUMPTION_MODES, decimalPattern } from '../../constants/masters';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, humanize } from '../../utils/format';
import type { ConsumptionProfile, ConsumptionRate } from '../../types/masters';

const modeLabel = (m: string) => CONSUMPTION_MODES.find((x) => x.value === m)?.label ?? humanize(m);

/**
 * Versioned consumption assumptions (MT/day). Estimations snapshot these
 * values, so editing a profile never changes an existing estimate.
 */
export function ConsumptionProfilesPanel({ vesselId, canEdit }: { vesselId: number; canEdit: boolean }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const profiles = useQuery({ queryKey: ['vessels', vesselId, 'profiles'], queryFn: () => vesselsApi.profiles(vesselId) });
  const [editing, setEditing] = useState<{ open: boolean; profile: ConsumptionProfile | null }>({ open: false, profile: null });
  const [deleting, setDeleting] = useState<ConsumptionProfile | null>(null);
  const remove = useMutation({
    mutationFn: (p: ConsumptionProfile) => vesselsApi.removeProfile(vesselId, p.id),
    onSuccess: (r) => { notify.success(r.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['vessels', vesselId, 'profiles'] }); },
    onError: (e) => notify.error(e),
  });

  if (profiles.isLoading) return <SectionLoader />;

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2 }}>
        <Typography variant="body2" color="text.secondary">Consumption in MT/day by operating mode and fuel. Estimations copy these values when created.</Typography>
        {canEdit && <Button startIcon={<AddIcon />} variant="outlined" onClick={() => setEditing({ open: true, profile: null })}>New profile</Button>}
      </Stack>
      {!profiles.data?.length ? <EmptyState title="No consumption profiles" description="Add design or charter-party consumption figures to enable voyage estimation." /> : (
        <Stack spacing={2}>
          {profiles.data.map((p) => (
            <Card key={p.id} variant="outlined">
              <CardHeader
                title={<Stack direction="row" spacing={1} alignItems="center"><span>{p.name}</span>{p.is_default && <Chip size="small" color="primary" label="Default" />}<Chip size="small" variant="outlined" label={humanize(p.source)} /></Stack>}
                subheader={`Effective ${formatDate(p.effective_from)} → ${p.effective_to ? formatDate(p.effective_to) : 'open'}${p.remarks ? ` · ${p.remarks}` : ''}`}
                slotProps={{ title: { variant: 'subtitle1', fontWeight: 600 } }}
                action={canEdit && <>
                  <Tooltip title="Edit"><IconButton aria-label={`Edit ${p.name}`} onClick={() => setEditing({ open: true, profile: p })}><EditOutlined fontSize="small" /></IconButton></Tooltip>
                  <Tooltip title="Delete"><IconButton color="error" aria-label={`Delete ${p.name}`} onClick={() => setDeleting(p)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>
                </>}
              />
              <CardContent sx={{ pt: 0 }}>
                <Table size="small">
                  <TableHead><TableRow><TableCell>Mode</TableCell><TableCell align="right">Speed (kn)</TableCell><TableCell>Fuel</TableCell><TableCell align="right">MT/day</TableCell></TableRow></TableHead>
                  <TableBody>
                    {p.rates.map((r) => (
                      <TableRow key={r.id}>
                        <TableCell>{modeLabel(r.mode)}</TableCell>
                        <TableCell align="right">{Number(r.speed_kn) ? r.speed_kn : '—'}</TableCell>
                        <TableCell>{r.fuel_type?.code ?? r.fuel_type_id}</TableCell>
                        <TableCell align="right">{r.consumption_mt_per_day}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          ))}
        </Stack>
      )}
      <ProfileDialog open={editing.open} vesselId={vesselId} profile={editing.profile} onClose={() => setEditing({ open: false, profile: null })} />
      <ConfirmDialog open={!!deleting} title="Delete profile" message={`Delete “${deleting?.name}”? Existing estimations keep their copied values.`} confirmLabel="Delete" danger
        loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting)} onClose={() => setDeleting(null)} />
    </Box>
  );
}

type RateRow = Omit<ConsumptionRate, 'fuel_type_id'> & { fuel_type_id: number | null };

function ProfileDialog({ open, vesselId, profile, onClose }: { open: boolean; vesselId: number; profile: ConsumptionProfile | null; onClose: () => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [head, setHead] = useState({ name: 'Design', source: 'design', effective_from: new Date().toISOString().slice(0, 10), effective_to: '', is_default: false, remarks: '' });
  const [rates, setRates] = useState<RateRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [lastOpen, setLastOpen] = useState(false);

  if (open !== lastOpen) {
    setLastOpen(open);
    if (open) {
      setError(null);
      setHead(profile
        ? { name: profile.name, source: profile.source, effective_from: profile.effective_from, effective_to: profile.effective_to ?? '', is_default: profile.is_default, remarks: profile.remarks ?? '' }
        : { name: 'Design', source: 'design', effective_from: new Date().toISOString().slice(0, 10), effective_to: '', is_default: false, remarks: '' });
      setRates(profile ? profile.rates.map((r) => ({ ...r })) : [
        { mode: 'sea_laden', speed_kn: '', fuel_type_id: null, consumption_mt_per_day: '' },
        { mode: 'port_idle', speed_kn: '0', fuel_type_id: null, consumption_mt_per_day: '' },
      ]);
    }
  }

  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...head, effective_to: head.effective_to || null, remarks: head.remarks || null,
        rates: rates.map((r) => ({ mode: r.mode, speed_kn: r.speed_kn || '0', fuel_type_id: r.fuel_type_id, consumption_mt_per_day: r.consumption_mt_per_day })),
      };
      return profile ? vesselsApi.updateProfile(vesselId, profile.id, body) : vesselsApi.createProfile(vesselId, body);
    },
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['vessels', vesselId, 'profiles'] }); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  const update = (i: number, patch: Partial<RateRow>) => setRates((r) => r.map((x, j) => (j === i ? { ...x, ...patch } : x)));
  const valid = head.name && head.effective_from && rates.length > 0 && rates.every((r) => {
    const speedOk = !CONSUMPTION_MODES.find((m) => m.value === r.mode)?.speed || (decimalPattern(2).test(r.speed_kn) && Number(r.speed_kn) > 0);
    return r.fuel_type_id && decimalPattern(3).test(r.consumption_mt_per_day) && speedOk;
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>{profile ? `Edit ${profile.name}` : 'New consumption profile'}</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2} sx={{ mb: 2 }}>
          <Grid size={{ xs: 12, sm: 4 }}><TextField label="Profile name" required value={head.name} onChange={(e) => setHead((h) => ({ ...h, name: e.target.value }))} /></Grid>
          <Grid size={{ xs: 12, sm: 4 }}>
            <TextField select label="Source" value={head.source} onChange={(e) => setHead((h) => ({ ...h, source: e.target.value }))}>
              <MenuItem value="design">Design / builder</MenuItem><MenuItem value="charter_party">Charter party warranty</MenuItem><MenuItem value="observed">Observed performance</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 4 }}><FormControlLabel control={<Switch checked={head.is_default} onChange={(e) => setHead((h) => ({ ...h, is_default: e.target.checked }))} />} label="Default for estimations" /></Grid>
          <Grid size={{ xs: 6, sm: 4 }}><TextField type="date" label="Effective from" required value={head.effective_from} onChange={(e) => setHead((h) => ({ ...h, effective_from: e.target.value }))} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 6, sm: 4 }}><TextField type="date" label="Effective to" value={head.effective_to} onChange={(e) => setHead((h) => ({ ...h, effective_to: e.target.value }))} slotProps={{ inputLabel: { shrink: true } }} helperText="Leave empty if open-ended" /></Grid>
          <Grid size={{ xs: 12, sm: 4 }}><TextField label="Remarks" value={head.remarks} onChange={(e) => setHead((h) => ({ ...h, remarks: e.target.value }))} /></Grid>
        </Grid>
        <Typography variant="subtitle2" gutterBottom>Consumption rates</Typography>
        <Stack spacing={1.25}>
          {rates.map((r, i) => {
            const needsSpeed = !!CONSUMPTION_MODES.find((m) => m.value === r.mode)?.speed;
            return (
              <Grid container spacing={1} key={i} alignItems="flex-start">
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField select label="Mode" value={r.mode} onChange={(e) => update(i, { mode: e.target.value })}>
                    {CONSUMPTION_MODES.map((m) => <MenuItem key={m.value} value={m.value}>{m.label}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid size={{ xs: 4, sm: 2 }}><TextField label="Speed (kn)" disabled={!needsSpeed} inputMode="decimal" value={needsSpeed ? r.speed_kn : ''} onChange={(e) => update(i, { speed_kn: e.target.value })} /></Grid>
                <Grid size={{ xs: 8, sm: 3 }}><ReferenceSelect type="fuel-types" label="Fuel" required value={r.fuel_type_id} onChange={(id) => update(i, { fuel_type_id: id })} /></Grid>
                <Grid size={{ xs: 10, sm: 2.5 }}><TextField label="MT/day" inputMode="decimal" value={r.consumption_mt_per_day} onChange={(e) => update(i, { consumption_mt_per_day: e.target.value })}
                  error={!!r.consumption_mt_per_day && !decimalPattern(3).test(r.consumption_mt_per_day)} /></Grid>
                <Grid size={{ xs: 2, sm: 0.5 }}><IconButton color="error" aria-label="Remove rate" onClick={() => setRates((x) => x.filter((_, j) => j !== i))}><DeleteOutline fontSize="small" /></IconButton></Grid>
              </Grid>
            );
          })}
        </Stack>
        <Button startIcon={<AddIcon />} sx={{ mt: 1.5 }} onClick={() => setRates((r) => [...r, { mode: 'standby', speed_kn: '0', fuel_type_id: r[r.length - 1]?.fuel_type_id ?? null, consumption_mt_per_day: '' }])}>Add rate</Button>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>Save profile</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
