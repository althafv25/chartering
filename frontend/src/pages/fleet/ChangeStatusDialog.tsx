import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, Stack, TextField, ToggleButton, ToggleButtonGroup } from '@mui/material';
import { vesselsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { LoadingButton } from '../../components/LoadingButton';
import { RoutePointAutocomplete } from '../../components/MasterPickers';
import { useNotify } from '../../hooks/useNotify';
import { humanize } from '../../utils/format';
import type { RoutePoint, StatusTrack } from '../../types/masters';

/** Local datetime value "YYYY-MM-DDTHH:mm" for <input type="datetime-local">. */
const nowLocal = () => {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
};

export function ChangeStatusDialog({ open, vessel, track: initialTrack, onClose }: {
  open: boolean;
  vessel: { id: number; name: string } | null;
  track?: StatusTrack;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const notify = useNotify();
  const catalogue = useQuery({ queryKey: ['vessel-status', 'catalogue'], queryFn: vesselsApi.statusCatalogue, staleTime: Infinity });
  const [track, setTrack] = useState<StatusTrack>(initialTrack ?? 'commercial');
  const [f, setF] = useState({ status: '', effective_from: nowLocal(), location_text: '', reason: '', remarks: '' });
  const [point, setPoint] = useState<RoutePoint | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setTrack(initialTrack ?? 'commercial');
    setF({ status: '', effective_from: nowLocal(), location_text: '', reason: '', remarks: '' });
    setPoint(null);
    setError(null);
  }, [open, initialTrack]);

  const save = useMutation({
    mutationFn: () => vesselsApi.changeStatus(vessel!.id, {
      track,
      status: f.status,
      effective_from: new Date(f.effective_from).toISOString(), // browser local → UTC
      port_id: point?.type === 'port' ? point.id : null,
      offshore_location_id: point?.type === 'location' ? point.id : null,
      location_text: f.location_text || null,
      reason: f.reason || null,
      remarks: f.remarks || null,
    }),
    onSuccess: (r) => {
      notify.success(r.message);
      qc.invalidateQueries({ queryKey: ['vessel-status'] });
      qc.invalidateQueries({ queryKey: ['vessels'] });
      onClose();
    },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Update status — {vessel?.name}</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Stack spacing={2}>
          <ToggleButtonGroup exclusive size="small" value={track} onChange={(_, t) => t && (setTrack(t), setF((x) => ({ ...x, status: '' })))} aria-label="Status track">
            <ToggleButton value="commercial">Commercial</ToggleButton>
            <ToggleButton value="operational">Operational</ToggleButton>
          </ToggleButtonGroup>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField select label="New status" required value={f.status} onChange={(e) => setF((x) => ({ ...x, status: e.target.value }))}>
                {catalogue.data?.[track].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField type="datetime-local" label="Effective from" required value={f.effective_from}
                onChange={(e) => setF((x) => ({ ...x, effective_from: e.target.value }))} slotProps={{ inputLabel: { shrink: true } }} helperText="Your local time; stored in UTC" />
            </Grid>
            <Grid size={12}><RoutePointAutocomplete label="Port / offshore location" value={point} onChange={setPoint} /></Grid>
            {!point && <Grid size={12}><TextField label="Location (free text)" value={f.location_text} onChange={(e) => setF((x) => ({ ...x, location_text: e.target.value }))} helperText="e.g. Anchorage off Fujairah" /></Grid>}
            <Grid size={12}><TextField label="Reason" value={f.reason} onChange={(e) => setF((x) => ({ ...x, reason: e.target.value }))} /></Grid>
            <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={(e) => setF((x) => ({ ...x, remarks: e.target.value }))} /></Grid>
          </Grid>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!f.status || !f.effective_from} onClick={() => save.mutate()}>Save status</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
