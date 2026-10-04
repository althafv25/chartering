import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, IconButton, MenuItem, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { voyagesApi } from '../../api/operations';
import { errorMessage } from '../../api/client';
import { EmptyState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { OFF_HIRE_REASONS } from '../../constants/operations';
import { labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatLocal, zoneLabel } from '../../utils/format';
import { trimZeros } from '../../utils/decimal';
import type { OffHire, OpsVoyage } from '../../types/operations';

type Form = { from_at: string; to_at: string; reason_code: string; description: string };

export function OffHirePanel({ voyage }: { voyage: OpsVoyage }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can, user } = useAuth();
  const editable = voyage.is_open && can(P.OffHireManage);
  const canDecide = voyage.is_open && can(P.OffHireAgree);
  const [edit, setEdit] = useState<{ e: OffHire | null; f: Form } | null>(null);
  const [decision, setDecision] = useState<{ e: OffHire; kind: 'agree' | 'dispute' } | null>(null);
  const [comment, setComment] = useState('');
  const [error, setError] = useState<string | null>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: ['voyages', voyage.id] });

  const save = useMutation({
    mutationFn: () => {
      const { e, f } = edit!;
      return voyagesApi.saveOffHire(voyage.id, e?.id ?? null, { ...f, to_at: f.to_at || null, description: f.description || null, ...(e ? { lock_version: e.lock_version } : {}) });
    },
    onSuccess: (r) => { notify.success(r.message); setEdit(null); refresh(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const decide = useMutation({
    mutationFn: () => voyagesApi.decideOffHire(voyage.id, decision!.e.id, decision!.kind, comment || null),
    onSuccess: (r) => { notify.success(r.message); setDecision(null); setComment(''); refresh(); },
    onError: (e) => notify.error(e),
  });
  const remove = useMutation({ mutationFn: (e: OffHire) => voyagesApi.deleteOffHire(voyage.id, e.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });

  const list = voyage.off_hires ?? [];
  const f = edit?.f;
  const setF = (p: Partial<Form>) => setEdit((x) => x && { ...x, f: { ...x.f, ...p } });

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
        <Typography variant="body2" color="text.secondary">
          Hours are calculated by the server. No hire deduction is applied yet; the deduction rule is still awaiting business confirmation (BR-OP-02). Disputed periods are excluded from actuals.
        </Typography>
        {editable && <Button variant="outlined" startIcon={<AddIcon />} sx={{ flexShrink: 0, ml: 2 }}
          onClick={() => { setError(null); setEdit({ e: null, f: { from_at: '', to_at: '', reason_code: 'breakdown', description: '' } }); }}>Add off-hire</Button>}
      </Stack>
      {list.length === 0 ? <EmptyState title="No off-hire recorded" /> : (
        <Table size="small">
          <TableHead><TableRow><TableCell>Reason</TableCell><TableCell>From</TableCell><TableCell>To</TableCell><TableCell align="right">Hours</TableCell><TableCell align="right">Days</TableCell><TableCell>Status</TableCell><TableCell /></TableRow></TableHead>
          <TableBody>
            {list.map((e) => (
              <TableRow key={e.id}>
                <TableCell><Typography fontSize={14} fontWeight={600}>{labelOf(OFF_HIRE_REASONS, e.reason_code)}</Typography>{e.description && <Typography variant="body2" color="text.secondary">{e.description}</Typography>}</TableCell>
                <TableCell>{formatLocal(e.from_at_local)}</TableCell>
                <TableCell>{e.to_at_local ? formatLocal(e.to_at_local) : <Typography fontSize={14} color="warning.main">open</Typography>}</TableCell>
                <TableCell align="right" sx={{ fontVariantNumeric: 'tabular-nums' }}>{e.hours ? trimZeros(e.hours) : '—'}</TableCell>
                <TableCell align="right" sx={{ fontVariantNumeric: 'tabular-nums' }}>{e.days ? trimZeros(e.days) : '—'}</TableCell>
                <TableCell><StatusChip status={e.status} />{e.decision_comment && <Typography variant="caption" display="block" color="text.secondary">{e.decision_comment}</Typography>}</TableCell>
                <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                  {canDecide && e.status !== 'agreed' && e.to_at && <Button size="small" color="success" onClick={() => setDecision({ e, kind: 'agree' })}>Agree</Button>}
                  {canDecide && e.status !== 'disputed' && <Button size="small" color="error" onClick={() => setDecision({ e, kind: 'dispute' })}>Dispute</Button>}
                  {editable && e.status !== 'agreed' && <Tooltip title="Edit"><IconButton size="small" aria-label="Edit off-hire" onClick={() => { setError(null); setEdit({ e, f: { from_at: e.from_at_local, to_at: e.to_at_local ?? '', reason_code: e.reason_code, description: e.description ?? '' } }); }}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
                  {editable && e.status !== 'agreed' && <Tooltip title="Delete"><IconButton size="small" color="error" aria-label="Delete off-hire" onClick={() => remove.mutate(e)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog open={!!edit} onClose={() => setEdit(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{edit?.e ? 'Edit off-hire' : 'Add off-hire'}</DialogTitle>
        {f && (
          <DialogContent dividers>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Grid container spacing={2}>
              <Grid size={12}>
                <TextField select label="Reason" value={f.reason_code} onChange={(e) => setF({ reason_code: e.target.value })}>
                  {OFF_HIRE_REASONS.map((r) => <MenuItem key={r.value} value={r.value}>{r.label}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={6}><TextField type="datetime-local" label="From" required value={f.from_at} onChange={(e) => setF({ from_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
              <Grid size={6}><TextField type="datetime-local" label="To" value={f.to_at} onChange={(e) => setF({ to_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} helperText="Leave empty while ongoing" /></Grid>
              <Grid size={12}><Typography variant="caption" color="text.secondary">Times in your time zone ({zoneLabel(user?.timezone ?? 'UTC')}).</Typography></Grid>
              <Grid size={12}><TextField label="Description" multiline minRows={2} value={f.description} onChange={(e) => setF({ description: e.target.value })} /></Grid>
            </Grid>
          </DialogContent>
        )}
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setEdit(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} disabled={!f?.from_at} onClick={() => save.mutate()}>Save</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!decision} onClose={() => setDecision(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{decision?.kind === 'agree' ? 'Agree off-hire' : 'Dispute off-hire'}</DialogTitle>
        <DialogContent dividers>
          <TextField label={decision?.kind === 'dispute' ? 'Reason' : 'Comment (optional)'} required={decision?.kind === 'dispute'} multiline minRows={2} value={comment} onChange={(e) => setComment(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDecision(null)}>Cancel</Button>
          <LoadingButton variant="contained" color={decision?.kind === 'agree' ? 'success' : 'error'} loading={decide.isPending}
            disabled={decision?.kind === 'dispute' && comment.trim().length < 3} onClick={() => decide.mutate()}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
