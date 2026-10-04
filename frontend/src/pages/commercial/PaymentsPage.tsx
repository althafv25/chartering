import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, Dialog, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { paymentsApi } from '../../api/finance';
import { ApiError, errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CompanyAutocomplete, CurrencySelect } from '../../components/MasterPickers';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDate } from '../../utils/format';
import { money } from '../../utils/decimal';
import type { Payment } from '../../types/finance';

const METHODS = ['wire', 'check', 'credit_card', 'cash', 'other'];

export default function PaymentsPage() {
  const navigate = useNavigate();
  const [direction, setDirection] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const q = { page, per_page: 15, direction: direction || undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['payments', q], queryFn: () => paymentsApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Payment>[] = [
    { key: 'no', header: 'Payment', render: (p) => <Box><Typography fontWeight={600} fontSize={14}>{p.payment_number}</Typography><Typography variant="body2" color="text.secondary">{p.company?.legal_name ?? '—'}</Typography></Box> },
    { key: 'direction', header: 'Direction', render: (p) => (p.direction === 'received' ? 'Received' : 'Paid') },
    { key: 'date', header: 'Date', hideBelow: 'md', render: (p) => formatDate(p.payment_date) },
    { key: 'amount', header: 'Amount', align: 'right', render: (p) => money(p.amount, p.currency) },
    { key: 'unalloc', header: 'Unallocated', align: 'right', hideBelow: 'lg', render: (p) => money(p.unallocated_amount, p.currency) },
    { key: 'status', header: 'Status', render: (p) => <StatusChip status={p.status} /> },
  ];

  return (
    <>
      <PageHeader title="Payments" subtitle="Payments received from customers or paid to suppliers, and their allocations." breadcrumbs={[{ label: 'Commercial' }, { label: 'Payments' }]}
        actions={<Can permission={P.PaymentsCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>Record payment</Button></Can>} />
      <Card>
        <FilterBar search="" onSearch={() => {}} placeholder="">
          <TextField select label="Direction" value={direction} onChange={(e) => { setDirection(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 160 } }}>
            <MenuItem value="">All</MenuItem><MenuItem value="received">Received</MenuItem><MenuItem value="paid">Paid</MenuItem>
          </TextField>
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem><MenuItem value="recorded">Recorded</MenuItem><MenuItem value="reversed">Reversed</MenuItem>
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(p) => p.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(p) => navigate(`/commercial/payments/${p.id}`)} emptyTitle="No payments" emptyDescription="Record a payment to allocate against invoices or payables." />
        )}
      </Card>
      <NewPaymentDialog open={dialog} onClose={() => setDialog(false)} onCreated={(p) => navigate(`/commercial/payments/${p.id}`)} />
    </>
  );
}

function NewPaymentDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (p: Payment) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const blank = { direction: 'received', company_id: null as number | null, payment_date: '', amount: '', currency: 'USD' as string | null, bank_account_ref: '', bank_reference: '', method: '' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: (confirmDuplicate?: boolean) => paymentsApi.create({ ...f, method: f.method || undefined, bank_account_ref: f.bank_account_ref || undefined, bank_reference: f.bank_reference || undefined, confirm_duplicate: confirmDuplicate }),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['payments'] }); setF(blank); onCreated(r.data); },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'possible_duplicate_payment') {
        setError(`${errorMessage(e)} Click Create again to confirm.`);
        return;
      }
      setError(errorMessage(e));
    },
  });
  const isDuplicateError = error?.includes('Click Create again to confirm.');

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Record payment</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity={isDuplicateError ? 'warning' : 'error'} sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 6 }}>
            <TextField select label="Direction" value={f.direction} onChange={(e) => setF({ ...f, direction: e.target.value })}>
              <MenuItem value="received">Received (from customer)</MenuItem><MenuItem value="paid">Paid (to supplier)</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6 }}><CurrencySelect label="Currency" required value={f.currency} onChange={(v) => setF({ ...f, currency: v })} /></Grid>
          <Grid size={12}><CompanyAutocomplete label="Company" required value={f.company_id} onChange={(id) => setF({ ...f, company_id: id })} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><TextField type="date" label="Payment date" required value={f.payment_date} onChange={(e) => setF({ ...f, payment_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><TextField label="Amount" required value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}>
            <TextField select label="Method" value={f.method} onChange={(e) => setF({ ...f, method: e.target.value })}>
              <MenuItem value="">—</MenuItem>{METHODS.map((m) => <MenuItem key={m} value={m}>{m.replace(/_/g, ' ')}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6 }}><TextField label="Bank reference" value={f.bank_reference} onChange={(e) => setF({ ...f, bank_reference: e.target.value })} /></Grid>
          <Grid size={12}><TextField label="Bank account ref" value={f.bank_account_ref} onChange={(e) => setF({ ...f, bank_account_ref: e.target.value })} /></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!f.company_id || !f.payment_date || !f.amount || !f.currency}
          onClick={() => create.mutate(isDuplicateError || undefined)}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
