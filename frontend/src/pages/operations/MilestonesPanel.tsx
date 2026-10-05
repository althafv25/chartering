import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Chip, DialogActions, DialogContent, Grid, IconButton, MenuItem, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import VerifiedOutlined from '@mui/icons-material/VerifiedOutlined';
import { voyagesApi } from '../../api/operations';
import { referenceApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { EmptyState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatLocal, zoneLabel } from '../../utils/format';
import type { Milestone, OpsVoyage } from '../../types/operations';
import type { ReferenceItem } from '../../types/masters';

type Form = { milestone_type_id: number | ''; port_call_id: number | ''; planned_at: string; actual_at: string; remarks: string };

export function MilestonesPanel({ voyage }: { voyage: OpsVoyage }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can, user } = useAuth();
  const editable = voyage.is_open && can(P.MilestonesManage);
  const scope = voyage.operation_type === 'offshore' ? 'offshore' : 'voyage';
  const types = useQuery({ queryKey: ['reference', 'milestone-types', 'active'], queryFn: () => referenceApi.list<ReferenceItem & { applies_to?: string }>('milestone-types', true), staleTime: 300_000 });
  const options = (types.data ?? []).filter((t) => !t.applies_to || t.applies_to === 'both' || t.applies_to === scope);
  const [edit, setEdit] = useState<{ m: Milestone | null; f: Form } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: ['voyages', voyage.id] });

  const save = useMutation({
    mutationFn: () => voyagesApi.saveMilestone(voyage.id, edit!.m?.id ?? null, {
      milestone_type_id: edit!.f.milestone_type_id, port_call_id: edit!.f.port_call_id || null,
      planned_at: edit!.f.planned_at || null, actual_at: edit!.f.actual_at || null, remarks: edit!.f.remarks || null,
    }),
    onSuccess: (r) => { notify.success(r.message); setEdit(null); refresh(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const verify = useMutation({ mutationFn: (m: Milestone) => voyagesApi.verifyMilestone(voyage.id, m.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });
  const remove = useMutation({ mutationFn: (m: Milestone) => voyagesApi.deleteMilestone(voyage.id, m.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });

  const open = (m: Milestone | null) => {
    setError(null);
    setEdit({ m, f: { milestone_type_id: m?.milestone_type_id ?? '', port_call_id: m?.port_call_id ?? '', planned_at: m?.planned_at_local ?? '', actual_at: m?.actual_at_local ?? '', remarks: m?.remarks ?? '' } });
  };
  const f = edit?.f;
  const setF = (p: Partial<Form>) => setEdit((x) => x && { ...x, f: { ...x.f, ...p } });
  const call = (voyage.port_calls ?? []).find((c) => c.id === f?.port_call_id);
  const tzHint = call ? `${zoneLabel(call.timezone)} local time` : `your time zone (${zoneLabel(user?.timezone ?? 'UTC')})`;
  const list = voyage.milestones ?? [];

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
        <Typography variant="body2" color="text.secondary">Recording a milestone does not change the voyage or vessel status automatically.</Typography>
        {editable && <Button variant="outlined" startIcon={<AddIcon />} onClick={() => open(null)}>Add milestone</Button>}
      </Stack>
      {list.length === 0 ? <EmptyState title="No milestones" /> : (
        <Table size="small">
          <TableHead><TableRow><TableCell>Milestone</TableCell><TableCell>At</TableCell><TableCell>Planned</TableCell><TableCell>Actual</TableCell><TableCell>Verified</TableCell><TableCell /></TableRow></TableHead>
          <TableBody>
            {list.map((m) => (
              <TableRow key={m.id}>
                <TableCell><Typography fontSize={14} fontWeight={600}>{m.type?.name}</Typography>{m.type?.is_laytime_relevant && <Chip size="small" variant="outlined" label="laytime" sx={{ mt: 0.5 }} />}</TableCell>
                <TableCell>{m.port_call?.label ?? '—'}<Typography variant="caption" display="block" color="text.secondary">{zoneLabel(m.timezone)} time</Typography></TableCell>
                <TableCell>{formatLocal(m.planned_at_local)}</TableCell>
                <TableCell>{formatLocal(m.actual_at_local)}</TableCell>
                <TableCell>{m.verified_by ? m.verified_by.name : '—'}</TableCell>
                <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                  {editable && m.actual_at && !m.verified_at && <Tooltip title="Verify"><IconButton size="small" aria-label="Verify milestone" onClick={() => verify.mutate(m)}><VerifiedOutlined fontSize="small" /></IconButton></Tooltip>}
                  {editable && <Tooltip title="Edit"><IconButton size="small" aria-label="Edit milestone" onClick={() => open(m)}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
                  {editable && <Tooltip title="Delete"><IconButton size="small" color="error" aria-label="Delete milestone" onClick={() => remove.mutate(m)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog open={!!edit} onClose={() => setEdit(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{edit?.m ? 'Edit milestone' : 'Add milestone'}</DialogTitle>
        {f && (
          <DialogContent dividers>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Grid container spacing={2}>
              <Grid size={12}>
                <TextField select label="Milestone" required value={f.milestone_type_id} onChange={(e) => setF({ milestone_type_id: Number(e.target.value) })}>
                  {options.map((t) => <MenuItem key={t.id} value={t.id}>{t.name}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={12}>
                <TextField select label="Port call (optional)" value={f.port_call_id} onChange={(e) => setF({ port_call_id: e.target.value === '' ? '' : Number(e.target.value) })}>
                  <MenuItem value="">—</MenuItem>
                  {(voyage.port_calls ?? []).filter((c) => c.status !== 'cancelled').map((c) => <MenuItem key={c.id} value={c.id}>{c.sequence}. {c.label}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={6}><TextField type="datetime-local" label="Planned" value={f.planned_at} onChange={(e) => setF({ planned_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
              <Grid size={6}><TextField type="datetime-local" label="Actual" value={f.actual_at} onChange={(e) => setF({ actual_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
              <Grid size={12}><Typography variant="caption" color="text.secondary">Times in {tzHint}.</Typography></Grid>
              <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={(e) => setF({ remarks: e.target.value })} /></Grid>
            </Grid>
          </DialogContent>
        )}
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setEdit(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} disabled={!f?.milestone_type_id || (!f.planned_at && !f.actual_at)} onClick={() => save.mutate()}>Save</LoadingButton>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
