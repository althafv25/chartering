import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, Dialog, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { invoicesApi } from '../../api/finance';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CompanyAutocomplete, CurrencySelect } from '../../components/MasterPickers';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { formatDate } from '../../utils/format';
import { money } from '../../utils/decimal';
import type { Invoice } from '../../types/finance';

const STATUSES = ['draft', 'submitted', 'approved', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'];
const TYPES = ['freight', 'hire', 'offshore_service', 'demurrage', 'other', 'credit_note'];

export default function InvoicesPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined, invoice_type: type || undefined };
  const list = useQuery({ queryKey: ['invoices', q], queryFn: () => invoicesApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Invoice>[] = [
    { key: 'no', header: 'Invoice', render: (i) => <Box><Typography fontWeight={600} fontSize={14}>{i.invoice_number ?? `Draft #${i.id}`}</Typography><Typography variant="body2" color="text.secondary">{i.customer?.legal_name ?? '—'}</Typography></Box> },
    { key: 'type', header: 'Type', hideBelow: 'md', render: (i) => i.invoice_type.replace(/_/g, ' ') },
    { key: 'due', header: 'Due', hideBelow: 'md', render: (i) => (i.is_overdue ? <Typography fontSize={14} color="error.main" fontWeight={600}>{formatDate(i.due_date)}</Typography> : formatDate(i.due_date)) },
    { key: 'total', header: 'Total', align: 'right', render: (i) => money(i.total, i.currency) },
    { key: 'balance', header: 'Balance', align: 'right', hideBelow: 'lg', render: (i) => money(i.balance, i.currency) },
    { key: 'status', header: 'Status', render: (i) => <StatusChip status={i.status} /> },
  ];

  return (
    <>
      <PageHeader title="Invoices" subtitle="Accounts receivable: draft to issue, allocation and credit notes." breadcrumbs={[{ label: 'Commercial' }, { label: 'Invoices' }]}
        actions={<Can permission={P.InvoicesCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>New invoice</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search invoice number…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{STATUSES.map((s) => <MenuItem key={s} value={s}>{s.replace(/_/g, ' ')}</MenuItem>)}
          </TextField>
          <TextField select label="Type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All</MenuItem>{TYPES.map((t) => <MenuItem key={t} value={t}>{t.replace(/_/g, ' ')}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(i) => i.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(i) => navigate(`/commercial/invoices/${i.id}`)} emptyTitle="No invoices" emptyDescription="Create one manually or attach confirmed revenue lines." />
        )}
      </Card>
      <NewInvoiceDialog open={dialog} onClose={() => setDialog(false)} onCreated={(i) => navigate(`/commercial/invoices/${i.id}`)} />
    </>
  );
}

function NewInvoiceDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (i: Invoice) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const blank = { invoice_type: 'freight', customer_company_id: null as number | null, issue_date: '', due_date: '', currency: 'USD' as string | null };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: () => invoicesApi.create(f),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['invoices'] }); setF(blank); onCreated(r.data); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New invoice</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 6 }}>
            <TextField select label="Type" value={f.invoice_type} onChange={(e) => setF({ ...f, invoice_type: e.target.value })}>
              {TYPES.filter((t) => t !== 'credit_note').map((t) => <MenuItem key={t} value={t}>{t.replace(/_/g, ' ')}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6 }}><CurrencySelect label="Currency" required value={f.currency} onChange={(v) => setF({ ...f, currency: v })} /></Grid>
          <Grid size={12}><CompanyAutocomplete label="Customer" required value={f.customer_company_id} onChange={(id) => setF({ ...f, customer_company_id: id })} /></Grid>
          <Grid size={6}><TextField type="date" label="Issue date" required value={f.issue_date} onChange={(e) => setF({ ...f, issue_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={6}><TextField type="date" label="Due date" required value={f.due_date} onChange={(e) => setF({ ...f, due_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
        </Grid>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>Lines are added on the invoice page while it is a draft.</Typography>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!f.customer_company_id || !f.issue_date || !f.due_date || !f.currency} onClick={() => create.mutate()}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
