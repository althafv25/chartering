import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Grid, IconButton, Stack, Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import PictureAsPdfOutlined from '@mui/icons-material/PictureAsPdfOutlined';
import { invoicesApi } from '../../api/finance';
import { ApiError, errorMessage } from '../../api/client';
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
import type { InvoiceLine } from '../../types/finance';

type Action = 'submit' | 'approve' | 'reject' | 'issue' | 'cancel' | 'credit-note';
const NEEDS_REASON: Action[] = ['reject', 'cancel', 'credit-note'];
const TITLES: Record<Action, string> = { submit: 'Submit for approval', approve: 'Approve invoice', reject: 'Return to draft', issue: 'Issue invoice', cancel: 'Cancel invoice', 'credit-note': 'Issue credit note' };

export default function InvoiceDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('lines');
  const [action, setAction] = useState<Action | null>(null);
  const [text, setText] = useState('');
  const [lineDialog, setLineDialog] = useState(false);

  const inv = useQuery({ queryKey: ['invoices', id], queryFn: () => invoicesApi.get(id) });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['invoices'] });

  const move = useMutation({
    mutationFn: (a: Action) => {
      if (a === 'submit') return invoicesApi.submit(id);
      if (a === 'approve') return invoicesApi.approve(id);
      if (a === 'reject') return invoicesApi.reject(id, text);
      if (a === 'issue') return invoicesApi.issue(id);
      if (a === 'cancel') return invoicesApi.cancel(id, text);
      return invoicesApi.creditNote(id, text);
    },
    onSuccess: (r) => {
      notify.success(r.message);
      setAction(null); setText(''); invalidate();
      if (action === 'credit-note') navigate(`/commercial/invoices/${r.data.id}`);
    },
    onError: (e) => notify.error(e),
  });
  const pdf = useMutation({ mutationFn: () => invoicesApi.generatePdf(id), onSuccess: (r) => { notify.success(r.message); invalidate(); }, onError: (e) => notify.error(e) });
  const removeLine = useMutation({
    mutationFn: (lines: Partial<InvoiceLine>[]) => invoicesApi.saveLines(id, lines),
    onSuccess: (r) => { notify.success(r.message); invalidate(); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'stale_record') inv.refetch(); notify.error(e); },
  });

  if (inv.isLoading) return <SectionLoader />;
  if (inv.isError || !inv.data) return <ErrorState error={inv.error} />;
  const i = inv.data;
  const lines = i.lines ?? [];

  const deleteLine = (lineId: number) => {
    const remaining = lines.filter((l) => l.id !== lineId).map((l) => ({
      voyage_revenue_id: l.voyage_revenue_id ?? undefined, description: l.description, quantity: l.quantity ?? undefined,
      unit: l.unit ?? undefined, rate: l.rate, amount: l.amount, tax_code_id: l.tax_code_id ?? undefined,
    }));
    removeLine.mutate(remaining);
  };

  return (
    <>
      <PageHeader title={i.invoice_number ?? `Draft invoice #${i.id}`} subtitle={i.customer?.legal_name ?? undefined}
        breadcrumbs={[{ label: 'Commercial' }, { label: 'Invoices', to: '/commercial/invoices' }, { label: i.invoice_number ?? `#${i.id}` }]}
        actions={<>
          {i.status === 'draft' && can(P.InvoicesSubmit) && <Button variant="contained" onClick={() => setAction('submit')}>Submit for approval</Button>}
          {i.status === 'submitted' && can(P.InvoicesApprove) && <Button color="error" onClick={() => setAction('reject')}>Return to draft</Button>}
          {i.status === 'submitted' && can(P.InvoicesApprove) && <Button variant="contained" color="success" onClick={() => setAction('approve')}>Approve</Button>}
          {['draft', 'approved'].includes(i.status) && can(P.InvoicesIssue) && <Button variant="contained" onClick={() => setAction('issue')}>Issue</Button>}
          {['draft', 'approved'].includes(i.status) && can(P.InvoicesCancel) && <Button color="error" onClick={() => setAction('cancel')}>Cancel</Button>}
          {['issued', 'partially_paid', 'paid', 'overdue'].includes(i.status) && can(P.InvoicesCreate) && <Button color="error" onClick={() => setAction('credit-note')}>Credit note</Button>}
          {['issued', 'partially_paid', 'paid', 'overdue'].includes(i.status) && can(P.InvoicesPrint) && <LoadingButton variant="outlined" startIcon={<PictureAsPdfOutlined />} loading={pdf.isPending} onClick={() => pdf.mutate()}>Generate PDF</LoadingButton>}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <StatusChip status={i.status} />
        {i.is_overdue && <Chip size="small" color="error" label="Overdue" />}
        {i.is_credit_note && <Chip size="small" color="secondary" label="Credit note" />}
        {i.credit_note_for && <Chip size="small" variant="outlined" label={`Credits ${i.credit_note_for.invoice_number}`} onClick={() => navigate(`/commercial/invoices/${i.credit_note_for!.id}`)} />}
        {i.contract && <Chip size="small" variant="outlined" label={`Contract ${i.contract.contract_number}`} onClick={() => navigate(`/contracts/${i.contract!.id}`)} />}
        {i.voyage && <Chip size="small" variant="outlined" label={`Voyage ${i.voyage.voyage_number}`} onClick={() => navigate(`/operations/voyages/${i.voyage!.id}`)} />}
      </Stack>

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="lines" label="Lines" /><Tab value="recap" label="Recap" /><Tab value="documents" label="Documents" />
        </Tabs>
        {tab === 'lines' && (
          <CardContent>
            {i.is_editable && can(P.InvoicesUpdate) && (
              <Stack direction="row" justifyContent="flex-end" sx={{ mb: 1.5 }}>
                <Button size="small" variant="outlined" startIcon={<AddIcon />} onClick={() => setLineDialog(true)}>Add line</Button>
              </Stack>
            )}
            <Table size="small">
              <TableHead><TableRow><TableCell>Description</TableCell><TableCell align="right">Qty</TableCell><TableCell align="right">Rate</TableCell><TableCell align="right">Amount</TableCell><TableCell align="right">Tax</TableCell><TableCell align="right">Total</TableCell>{i.is_editable && <TableCell />}</TableRow></TableHead>
              <TableBody>
                {lines.map((l) => (
                  <TableRow key={l.id}>
                    <TableCell>{l.description}</TableCell>
                    <TableCell align="right">{l.quantity ?? '—'}</TableCell>
                    <TableCell align="right">{l.rate}</TableCell>
                    <TableCell align="right">{money(l.amount)}</TableCell>
                    <TableCell align="right">{money(l.tax_amount)}</TableCell>
                    <TableCell align="right">{money(l.line_total)}</TableCell>
                    {i.is_editable && can(P.InvoicesUpdate) && <TableCell align="right"><Tooltip title="Remove"><IconButton size="small" color="error" onClick={() => deleteLine(l.id)}><DeleteOutline fontSize="small" /></IconButton></Tooltip></TableCell>}
                  </TableRow>
                ))}
                {lines.length === 0 && <TableRow><TableCell colSpan={7}><Typography color="text.secondary" sx={{ py: 2 }} align="center">No lines yet.</Typography></TableCell></TableRow>}
              </TableBody>
            </Table>
            <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}>
              <KeyValueGrid columns={4} items={[['Subtotal', money(i.subtotal, i.currency)], ['Tax', money(i.tax_amount, i.currency)], ['Total', money(i.total, i.currency)], ['Balance', money(i.balance, i.currency)]]} />
            </Stack>
          </CardContent>
        )}
        {tab === 'recap' && (
          <CardContent>
            <KeyValueGrid columns={4} items={[
              ['Issue date', formatDate(i.issue_date)], ['Due date', formatDate(i.due_date)], ['Currency', i.currency], ['FX rate', i.fx_rate],
              ['Base total', money(i.base_total, i.base_currency)], ['Amount paid', money(i.amount_paid, i.currency)],
              ['Submitted', i.submitted_at ? formatDateTime(i.submitted_at) : null], ['Approved', i.approved_at ? formatDateTime(i.approved_at) : null],
              ['Issued', i.issued_at ? formatDateTime(i.issued_at) : null], ['Cancelled reason', i.cancelled_reason],
            ]} />
            {i.remarks && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Remarks</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{i.remarks}</Typography></Box>}
          </CardContent>
        )}
        {tab === 'documents' && <DocumentsPanel parentType="invoices" parentId={id} canEdit={can(P.InvoicesUpdate)} />}
      </Card>

      <Dialog open={!!action} onClose={() => setAction(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{action && TITLES[action]}</DialogTitle>
        <DialogContent dividers>
          {action === 'credit-note' && <Typography variant="body2" sx={{ mb: 1.5 }}>Creates a new, immediately-issued credit note that reverses this invoice's lines and totals, and cancels this invoice.</Typography>}
          {action && NEEDS_REASON.includes(action) && <TextField label="Reason" required multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} />}
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setAction(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={move.isPending} disabled={!!action && NEEDS_REASON.includes(action) && text.trim().length < 3} onClick={() => action && move.mutate(action)}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>

      <AddLineDialog open={lineDialog} invoiceId={id} existing={lines} onClose={() => setLineDialog(false)} onSaved={invalidate} />
    </>
  );
}

function AddLineDialog({ open, invoiceId, existing, onClose, onSaved }: { open: boolean; invoiceId: number; existing: InvoiceLine[]; onClose: () => void; onSaved: () => void }) {
  const notify = useNotify();
  const blank = { description: '', quantity: '', rate: '', amount: '' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => {
      const existingRows = existing.map((l) => ({
        voyage_revenue_id: l.voyage_revenue_id ?? undefined, description: l.description, quantity: l.quantity ?? undefined,
        unit: l.unit ?? undefined, rate: l.rate, amount: l.amount, tax_code_id: l.tax_code_id ?? undefined,
      }));
      return invoicesApi.saveLines(invoiceId, [...existingRows, { description: f.description, quantity: f.quantity || undefined, rate: f.rate || '0', amount: f.amount || undefined }]);
    },
    onSuccess: (r) => { notify.success(r.message); setF(blank); onSaved(); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Add line</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}><TextField label="Description" required value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Grid>
          <Grid size={4}><TextField label="Quantity" value={f.quantity} onChange={(e) => setF({ ...f, quantity: e.target.value })} /></Grid>
          <Grid size={4}><TextField label="Rate" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} /></Grid>
          <Grid size={4}><TextField label="Amount (override)" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} helperText="Blank = qty × rate" /></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!f.description} onClick={() => save.mutate()}>Add</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
