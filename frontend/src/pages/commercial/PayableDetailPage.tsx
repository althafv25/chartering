import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, Grid, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import { payablesApi } from '../../api/finance';
import { ApiError } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { StatusChip } from '../../components/StatusChip';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatDateTime } from '../../utils/format';
import { money } from '../../utils/decimal';

export default function PayableDetailPage() {
  const id = Number(useParams().id);
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState('');
  const [edit, setEdit] = useState<{ supplier_invoice_ref: string; subtotal: string; tax: string; remarks: string } | null>(null);

  const pay = useQuery({ queryKey: ['payables', id], queryFn: () => payablesApi.get(id) });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['payables'] });

  const approve = useMutation({ mutationFn: () => payablesApi.approve(id), onSuccess: (r) => { notify.success(r.message); invalidate(); }, onError: (e) => notify.error(e) });
  const reapprove = useMutation({ mutationFn: () => payablesApi.reapprove(id), onSuccess: (r) => { notify.success(r.message); invalidate(); }, onError: (e) => notify.error(e) });
  const cancel = useMutation({
    mutationFn: () => payablesApi.cancel(id, reason),
    onSuccess: (r) => { notify.success(r.message); setCancelling(false); setReason(''); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const save = useMutation({
    mutationFn: () => payablesApi.update(id, { ...edit, lock_version: pay.data!.lock_version }),
    onSuccess: (r) => { notify.success(r.message); setEdit(null); invalidate(); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'stale_record') pay.refetch(); notify.error(e); },
  });

  if (pay.isLoading) return <SectionLoader />;
  if (pay.isError || !pay.data) return <ErrorState error={pay.error} />;
  const p = pay.data;
  const expenses = p.voyage_expenses ?? [];

  return (
    <>
      <PageHeader title={p.payable_number} subtitle={p.supplier?.legal_name ?? undefined}
        breadcrumbs={[{ label: 'Commercial' }, { label: 'Payables', to: '/commercial/payables' }, { label: p.payable_number }]}
        actions={<>
          {p.is_editable && can(P.PayablesCreate) && <Button onClick={() => setEdit({ supplier_invoice_ref: p.supplier_invoice_ref, subtotal: p.subtotal, tax: p.tax, remarks: p.remarks ?? '' })}>Edit</Button>}
          {p.status === 'draft' && can(P.PayablesApprove) && <Button variant="contained" color="success" onClick={() => approve.mutate()}>Approve</Button>}
          {p.status === 'approved' && Number(p.amount_paid) === 0 && can(P.PayablesApprove) && <Button variant="outlined" onClick={() => reapprove.mutate()}>Re-approve</Button>}
          {['draft', 'approved'].includes(p.status) && can(P.PayablesCreate) && <Button color="error" onClick={() => setCancelling(true)}>Cancel</Button>}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <StatusChip status={p.status} />
        {p.is_overdue && <Chip size="small" color="error" label="Overdue" />}
        {p.voyage && <Chip size="small" variant="outlined" label={`Voyage ${p.voyage.voyage_number}`} />}
      </Stack>

      <Card>
        <CardContent>
          <KeyValueGrid columns={4} items={[
            ['Supplier invoice ref', p.supplier_invoice_ref], ['Issue date', formatDate(p.issue_date)], ['Due date', formatDate(p.due_date)], ['Currency', p.currency],
            ['Subtotal', money(p.subtotal, p.currency)], ['Tax', money(p.tax, p.currency)], ['Total', money(p.total, p.currency)], ['Balance', money(p.balance, p.currency)],
            ['Base total', money(p.base_total, p.base_currency)], ['Amount paid', money(p.amount_paid, p.currency)],
            ['Approved', p.approved_at ? formatDateTime(p.approved_at) : null], ['FX rate', p.fx_rate],
          ]} />
          {p.remarks && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Remarks</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{p.remarks}</Typography></Box>}

          {expenses.length > 0 && (
            <>
              <Typography variant="subtitle2" sx={{ mt: 3, mb: 1 }}>Linked voyage expense</Typography>
              <Table size="small">
                <TableHead><TableRow><TableCell>Status</TableCell><TableCell align="right">Amount</TableCell><TableCell align="right">Base amount</TableCell></TableRow></TableHead>
                <TableBody>
                  {expenses.map((e) => <TableRow key={e.id}><TableCell><StatusChip status={e.status} /></TableCell><TableCell align="right">{money(e.amount, p.currency)}</TableCell><TableCell align="right">{money(e.base_amount, p.base_currency)}</TableCell></TableRow>)}
                </TableBody>
              </Table>
            </>
          )}
        </CardContent>
      </Card>

      <Card sx={{ mt: 2 }}>
        <CardContent sx={{ pb: 0 }}><Typography variant="subtitle2">Documents</Typography></CardContent>
        <DocumentsPanel parentType="payables" parentId={id} canEdit={can(P.PayablesCreate) && p.status !== 'cancelled'} />
      </Card>

      <Dialog open={cancelling} onClose={() => setCancelling(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Cancel payable</DialogTitle>
        <DialogContent dividers><TextField label="Reason" required multiline minRows={2} value={reason} onChange={(e) => setReason(e.target.value)} /></DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setCancelling(false)}>Close</Button>
          <LoadingButton variant="contained" color="error" loading={cancel.isPending} disabled={reason.trim().length < 3} onClick={() => cancel.mutate()}>Cancel payable</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!edit} onClose={() => setEdit(null)} maxWidth="sm" fullWidth>
        <DialogTitle>Edit payable</DialogTitle>
        <DialogContent dividers>
          <Grid container spacing={2}>
            <Grid size={12}><TextField label="Supplier invoice ref" value={edit?.supplier_invoice_ref ?? ''} onChange={(e) => setEdit((x) => x && { ...x, supplier_invoice_ref: e.target.value })} /></Grid>
            <Grid size={6}><TextField label="Subtotal" value={edit?.subtotal ?? ''} onChange={(e) => setEdit((x) => x && { ...x, subtotal: e.target.value })} /></Grid>
            <Grid size={6}><TextField label="Tax" value={edit?.tax ?? ''} onChange={(e) => setEdit((x) => x && { ...x, tax: e.target.value })} /></Grid>
            <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={edit?.remarks ?? ''} onChange={(e) => setEdit((x) => x && { ...x, remarks: e.target.value })} /></Grid>
          </Grid>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setEdit(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>Save</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
