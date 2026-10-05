import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Alert, Box, Button, DialogActions, DialogContent, Grid, IconButton, MenuItem, Stack, Table, TableBody, TableCell, TableHead, TableRow,
  TextField, Tooltip, Typography,
} from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import BlockOutlined from '@mui/icons-material/BlockOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { voyagesApi } from '../../api/operations';
import { errorMessage } from '../../api/client';
import { CompanyAutocomplete, RoutePointAutocomplete } from '../../components/MasterPickers';
import { EmptyState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { PORT_CALL_PURPOSES } from '../../constants/operations';
import { labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatLocal, zoneLabel } from '../../utils/format';
import type { OpsVoyage, PortCall } from '../../types/operations';
import type { RoutePoint } from '../../types/masters';

const TIMES = [['eta', 'ETA'], ['etb', 'ETB'], ['etd', 'ETD'], ['ata', 'ATA'], ['atb', 'ATB'], ['atd', 'ATD']] as const;
type TimeKey = (typeof TIMES)[number][0];
type Form = { point: RoutePoint | null; purpose: string; agent_company_id: number | null; berth: string; remarks: string; nominated: boolean } & Record<TimeKey, string>;

export function PortCallsPanel({ voyage }: { voyage: OpsVoyage }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const editable = voyage.is_open && can(P.PortCallsManage);
  const [edit, setEdit] = useState<{ call: PortCall | null; form: Form } | null>(null);
  const [cancel, setCancel] = useState<PortCall | null>(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: ['voyages', voyage.id] });

  const open = (call: PortCall | null) => {
    setError(null);
    setEdit({ call, form: {
      point: call ? { type: call.port_id ? 'port' : 'location', id: (call.port_id ?? call.offshore_location_id)!, label: call.label } as RoutePoint : null,
      purpose: call?.purpose ?? (voyage.operation_type === 'offshore' ? 'offshore_ops' : 'load'), agent_company_id: call?.agent_company_id ?? null,
      berth: call?.berth ?? '', remarks: call?.remarks ?? '', nominated: call?.status === 'nominated',
      eta: call?.eta_local ?? '', etb: call?.etb_local ?? '', etd: call?.etd_local ?? '', ata: call?.ata_local ?? '', atb: call?.atb_local ?? '', atd: call?.atd_local ?? '',
    } });
  };

  const save = useMutation({
    mutationFn: () => {
      const { call, form: f } = edit!;
      const body: Record<string, unknown> = {
        port_id: f.point?.type === 'port' ? f.point.id : null, offshore_location_id: f.point?.type === 'location' ? f.point.id : null,
        purpose: f.purpose, agent_company_id: f.agent_company_id, berth: f.berth || null, remarks: f.remarks || null, status: f.nominated ? 'nominated' : 'planned',
        ...Object.fromEntries(TIMES.map(([k]) => [k, f[k] || null])),
      };
      if (call) body.lock_version = call.lock_version;
      return voyagesApi.savePortCall(voyage.id, call?.id ?? null, body);
    },
    onSuccess: (r) => { notify.success(r.message); setEdit(null); refresh(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const doCancel = useMutation({
    mutationFn: () => voyagesApi.cancelPortCall(voyage.id, cancel!.id, reason),
    onSuccess: (r) => { notify.success(r.message); setCancel(null); setReason(''); refresh(); },
    onError: (e) => notify.error(e),
  });
  const remove = useMutation({
    mutationFn: (c: PortCall) => voyagesApi.deletePortCall(voyage.id, c.id),
    onSuccess: (r) => { notify.success(r.message); refresh(); },
    onError: (e) => notify.error(e),
  });

  const calls = voyage.port_calls ?? [];
  const f = edit?.form;
  const setF = (patch: Partial<Form>) => setEdit((x) => x && { ...x, form: { ...x.form, ...patch } });

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
        <Typography variant="body2" color="text.secondary">Times are entered and shown in each port's local time; the server stores UTC.</Typography>
        {editable && <Button variant="outlined" startIcon={<AddIcon />} onClick={() => open(null)}>Add call</Button>}
      </Stack>
      {calls.length === 0 ? <EmptyState title="No port calls" description="Add the itinerary: load/discharge ports or offshore locations." /> : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>#</TableCell><TableCell>Port / location</TableCell><TableCell>Purpose</TableCell>
                <TableCell>ETA / ETD</TableCell><TableCell>ATA / ATD</TableCell><TableCell>Agent</TableCell><TableCell>Status</TableCell><TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {calls.map((c) => (
                <TableRow key={c.id} sx={{ opacity: c.status === 'cancelled' ? 0.55 : 1 }}>
                  <TableCell>{c.sequence}</TableCell>
                  <TableCell><Typography fontSize={14} fontWeight={600}>{c.label}</Typography><Typography variant="caption" color="text.secondary">{zoneLabel(c.timezone)} time{c.berth ? ` · berth ${c.berth}` : ''}</Typography></TableCell>
                  <TableCell>{labelOf(PORT_CALL_PURPOSES, c.purpose)}</TableCell>
                  <TableCell><Typography fontSize={13}>{formatLocal(c.eta_local)}</Typography><Typography fontSize={13} color="text.secondary">{formatLocal(c.etd_local)}</Typography></TableCell>
                  <TableCell><Typography fontSize={13}>{formatLocal(c.ata_local)}</Typography><Typography fontSize={13} color="text.secondary">{formatLocal(c.atd_local)}</Typography></TableCell>
                  <TableCell>{c.agent?.legal_name ?? '—'}</TableCell>
                  <TableCell><StatusChip status={c.status} /></TableCell>
                  <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                    {editable && c.status !== 'cancelled' && <Tooltip title="Edit"><IconButton size="small" aria-label={`Edit call ${c.sequence}`} onClick={() => open(c)}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
                    {editable && !c.ata && c.status !== 'cancelled' && <Tooltip title="Cancel call"><IconButton size="small" aria-label={`Cancel call ${c.sequence}`} onClick={() => setCancel(c)}><BlockOutlined fontSize="small" /></IconButton></Tooltip>}
                    {editable && !c.ata && <Tooltip title="Delete"><IconButton size="small" color="error" aria-label={`Delete call ${c.sequence}`} onClick={() => remove.mutate(c)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}

      <Dialog open={!!edit} onClose={() => setEdit(null)} maxWidth="md" fullWidth>
        <DialogTitle>{edit?.call ? `Port call ${edit.call.sequence}` : 'Add port call'}</DialogTitle>
        {f && (
          <DialogContent dividers>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, md: 6 }}><RoutePointAutocomplete label="Port or offshore location *" value={f.point} onChange={(point) => setF({ point })} /></Grid>
              <Grid size={{ xs: 6, md: 3 }}>
                <TextField select label="Purpose" value={f.purpose} onChange={(e) => setF({ purpose: e.target.value })}>
                  {PORT_CALL_PURPOSES.map((p) => <MenuItem key={p.value} value={p.value}>{p.label}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={{ xs: 6, md: 3 }}><TextField label="Berth" value={f.berth} onChange={(e) => setF({ berth: e.target.value })} /></Grid>
              <Grid size={{ xs: 12, md: 6 }}><CompanyAutocomplete label="Agent" role="agent" value={f.agent_company_id} onChange={(id) => setF({ agent_company_id: id })} /></Grid>
              <Grid size={{ xs: 12, md: 6 }}>
                <TextField select label="Planning status" value={f.nominated ? 'nominated' : 'planned'} onChange={(e) => setF({ nominated: e.target.value === 'nominated' })}
                  helperText="Arrived / berthed / sailed follow the actual times">
                  <MenuItem value="planned">Planned</MenuItem><MenuItem value="nominated">Nominated</MenuItem>
                </TextField>
              </Grid>
              {TIMES.map(([k, label]) => (
                <Grid key={k} size={{ xs: 6, md: 4 }}>
                  <TextField type="datetime-local" label={`${label} (local)`} value={f[k]} onChange={(e) => setF({ [k]: e.target.value } as Partial<Form>)}
                    slotProps={{ inputLabel: { shrink: true } }} />
                </Grid>
              ))}
              <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={(e) => setF({ remarks: e.target.value })} /></Grid>
            </Grid>
          </DialogContent>
        )}
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setEdit(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} disabled={!f?.point} onClick={() => save.mutate()}>Save</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!cancel} onClose={() => setCancel(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Cancel call at {cancel?.label}</DialogTitle>
        <DialogContent dividers><TextField label="Reason" required value={reason} onChange={(e) => setReason(e.target.value)} /></DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setCancel(null)}>Close</Button>
          <LoadingButton variant="contained" color="error" loading={doCancel.isPending} disabled={reason.trim().length < 3} onClick={() => doCancel.mutate()}>Cancel call</LoadingButton>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
