import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, Grid, IconButton, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { invoicesApi, payablesApi, paymentsApi } from '../../api/finance';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { StatusChip } from '../../components/StatusChip';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { formatDate } from '../../utils/format';
import { money } from '../../utils/decimal';
import type { PaymentAllocation } from '../../types/finance';

export default function PaymentDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [allocateOpen, setAllocateOpen] = useState(false);
  const [reverseOpen, setReverseOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [unallocating, setUnallocating] = useState<PaymentAllocation | null>(null);

  const pay = useQuery({ queryKey: ['payments', id], queryFn: () => paymentsApi.get(id) });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['payments'] });

  const reverse = useMutation({
    mutationFn: () => paymentsApi.reverse(id, reason),
    onSuccess: (r) => { notify.success(r.message); setReverseOpen(false); setReason(''); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const unallocate = useMutation({
    mutationFn: (a: PaymentAllocation) => paymentsApi.unallocate(id, a.id),
    onSuccess: (r) => { notify.success(r.message); setUnallocating(null); invalidate(); },
    onError: (e) => notify.error(e),
  });

  if (pay.isLoading) return <SectionLoader />;
  if (pay.isError || !pay.data) return <ErrorState error={pay.error} />;
  const p = pay.data;
  const allocations = p.allocations ?? [];

  return (
    <>
      <PageHeader title={p.payment_number} subtitle={p.company?.legal_name ?? undefined}
        breadcrumbs={[{ label: 'Commercial' }, { label: 'Payments', to: '/commercial/payments' }, { label: p.payment_number }]}
        actions={<>
          {!p.is_reversed && Number(p.unallocated_amount) > 0 && can(P.PaymentsAllocate) && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setAllocateOpen(true)}>Allocate</Button>}
          {!p.is_reversed && can(P.PaymentsReverse) && <Button color="error" onClick={() => setReverseOpen(true)}>Reverse</Button>}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <StatusChip status={p.status} />
        <Chip size="small" variant="outlined" label={p.direction === 'received' ? 'Received' : 'Paid'} />
      </Stack>

      <Card>
        <CardContent>
          <KeyValueGrid columns={4} items={[
            ['Date', formatDate(p.payment_date)], ['Amount', money(p.amount, p.currency)], ['Allocated', money(p.allocated_amount, p.currency)], ['Unallocated', money(p.unallocated_amount, p.currency)],
            ['Base amount', money(p.base_amount, p.base_currency)], ['Method', p.method?.replace(/_/g, ' ') ?? '—'], ['Bank reference', p.bank_reference], ['Bank account', p.bank_account_ref],
          ]} />
          {p.remarks && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Remarks</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{p.remarks}</Typography></Box>}

          <Typography variant="subtitle2" sx={{ mt: 3, mb: 1 }}>Allocations</Typography>
          <Table size="small">
            <TableHead><TableRow><TableCell>Target</TableCell><TableCell align="right">Allocated</TableCell><TableCell align="right">Target ccy</TableCell><TableCell align="right">FX diff (base)</TableCell>{!p.is_reversed && <TableCell />}</TableRow></TableHead>
            <TableBody>
              {allocations.map((a) => (
                <TableRow key={a.id}>
                  <TableCell>
                    {a.invoice && <Typography fontSize={14} color="primary" sx={{ cursor: 'pointer' }} onClick={() => navigate(`/commercial/invoices/${a.invoice!.id}`)}>Invoice {a.invoice.invoice_number ?? `#${a.invoice.id}`}</Typography>}
                    {a.payable && <Typography fontSize={14} color="primary" sx={{ cursor: 'pointer' }} onClick={() => navigate(`/commercial/payables/${a.payable!.id}`)}>Payable {a.payable.payable_number}</Typography>}
                  </TableCell>
                  <TableCell align="right">{money(a.allocated_amount, p.currency)}</TableCell>
                  <TableCell align="right">{money(a.invoice_ccy_amount, a.invoice?.currency ?? a.payable?.currency)}</TableCell>
                  <TableCell align="right">{money(a.fx_difference_base, p.base_currency)}</TableCell>
                  {!p.is_reversed && can(P.PaymentsAllocate) && <TableCell align="right"><Tooltip title="Remove allocation"><IconButton size="small" color="error" onClick={() => setUnallocating(a)}><DeleteOutline fontSize="small" /></IconButton></Tooltip></TableCell>}
                </TableRow>
              ))}
              {allocations.length === 0 && <TableRow><TableCell colSpan={5}><Typography color="text.secondary" align="center" sx={{ py: 2 }}>No allocations yet.</Typography></TableCell></TableRow>}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Card sx={{ mt: 2 }}>
        <CardContent sx={{ pb: 0 }}><Typography variant="subtitle2">Documents (bank advice, remittance)</Typography></CardContent>
        <DocumentsPanel parentType="payments" parentId={id} canEdit={can(P.PaymentsCreate) && !p.is_reversed} />
      </Card>

      <AllocateDialog open={allocateOpen} payment={p} onClose={() => setAllocateOpen(false)} onDone={invalidate} />

      <Dialog open={reverseOpen} onClose={() => setReverseOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Reverse payment</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" sx={{ mb: 1.5 }}>All allocations will be undone and the payment marked reversed.</Typography>
          <TextField label="Reason" required multiline minRows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setReverseOpen(false)}>Cancel</Button>
          <LoadingButton variant="contained" color="error" loading={reverse.isPending} disabled={reason.trim().length < 3} onClick={() => reverse.mutate()}>Reverse</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!unallocating} onClose={() => setUnallocating(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Remove allocation</DialogTitle>
        <DialogContent dividers><Typography variant="body2">This restores the unallocated balance and reverts the target's paid amount.</Typography></DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setUnallocating(null)}>Cancel</Button>
          <LoadingButton variant="contained" color="error" loading={unallocate.isPending} onClick={() => unallocating && unallocate.mutate(unallocating)}>Remove</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}

function AllocateDialog({ open, payment, onClose, onDone }: { open: boolean; payment: { id: number; currency: string; unallocated_amount: string; direction: 'received' | 'paid'; company_id: number }; onClose: () => void; onDone: () => void }) {
  const notify = useNotify();
  const [search, setSearch] = useState('');
  const [amount, setAmount] = useState('');
  const [target, setTarget] = useState<{ kind: 'invoice' | 'payable'; id: number; label: string; balance: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const debounced = useDebounce(search);

  const invoices = useQuery({
    queryKey: ['invoices', 'allocate-lookup', payment.company_id, debounced],
    queryFn: () => invoicesApi.list({ page: 1, per_page: 10, customer_company_id: payment.company_id, search: debounced || undefined }),
    enabled: open && payment.direction === 'received',
  });
  const payables = useQuery({
    queryKey: ['payables', 'allocate-lookup', payment.company_id, debounced],
    queryFn: () => payablesApi.list({ page: 1, per_page: 10, supplier_company_id: payment.company_id, search: debounced || undefined }),
    enabled: open && payment.direction === 'paid',
  });

  const openCandidates = payment.direction === 'received'
    ? (invoices.data?.data ?? []).filter((i) => ['issued', 'partially_paid', 'overdue'].includes(i.status))
    : (payables.data?.data ?? []).filter((p) => ['approved', 'partially_paid'].includes(p.status));

  const allocate = useMutation({
    mutationFn: () => paymentsApi.allocate(payment.id, [{ [target!.kind === 'invoice' ? 'invoice_id' : 'payable_id']: target!.id, amount } as { invoice_id?: number; payable_id?: number; amount: string }]),
    onSuccess: (r) => { notify.success(r.message); setTarget(null); setAmount(''); setSearch(''); onDone(); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  const close = () => { setTarget(null); setAmount(''); setSearch(''); setError(null); onClose(); };

  return (
    <Dialog open={open} onClose={close} maxWidth="sm" fullWidth>
      <DialogTitle>Allocate payment</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>Unallocated: {money(payment.unallocated_amount, payment.currency)}</Typography>
        {!target ? (
          <>
            <TextField fullWidth label={`Search ${payment.direction === 'received' ? 'invoices' : 'payables'}…`} value={search} onChange={(e) => setSearch(e.target.value)} sx={{ mb: 1.5 }} />
            <Table size="small">
              <TableHead><TableRow><TableCell>Number</TableCell><TableCell align="right">Balance</TableCell><TableCell /></TableRow></TableHead>
              <TableBody>
                {openCandidates.map((c) => {
                  const label = 'invoice_number' in c ? (c.invoice_number ?? `#${c.id}`) : c.payable_number;
                  const kind: 'invoice' | 'payable' = 'invoice_number' in c ? 'invoice' : 'payable';
                  return (
                    <TableRow key={c.id}>
                      <TableCell>{label}</TableCell>
                      <TableCell align="right">{money(c.balance, c.currency)}</TableCell>
                      <TableCell align="right"><Button size="small" onClick={() => { setTarget({ kind, id: c.id, label: String(label), balance: c.balance }); setAmount(c.balance); }}>Select</Button></TableCell>
                    </TableRow>
                  );
                })}
                {openCandidates.length === 0 && <TableRow><TableCell colSpan={3}><Typography color="text.secondary" align="center" sx={{ py: 2 }}>No open {payment.direction === 'received' ? 'invoices' : 'payables'} found for this company.</Typography></TableCell></TableRow>}
              </TableBody>
            </Table>
          </>
        ) : (
          <Grid container spacing={2}>
            <Grid size={12}><Typography fontWeight={600}>{target.label}</Typography><Typography variant="body2" color="text.secondary">Balance: {money(target.balance)}</Typography></Grid>
            <Grid size={12}><TextField label="Amount to allocate" required value={amount} onChange={(e) => setAmount(e.target.value)} /></Grid>
            <Grid size={12}><Button size="small" onClick={() => setTarget(null)}>Choose a different target</Button></Grid>
          </Grid>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={close}>Cancel</Button>
        <LoadingButton variant="contained" loading={allocate.isPending} disabled={!target || !amount} onClick={() => allocate.mutate()}>Allocate</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
