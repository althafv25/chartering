import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, Grid, MenuItem, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import { payablesApi } from '../../api/finance';
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
import type { Payable } from '../../types/finance';

const STATUSES = ['draft', 'approved', 'partially_paid', 'paid', 'cancelled'];

export default function PayablesPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['payables', q], queryFn: () => payablesApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Payable>[] = [
    { key: 'no', header: 'Payable', render: (p) => <Box><Typography fontWeight={600} fontSize={14}>{p.payable_number}</Typography><Typography variant="body2" color="text.secondary">{p.supplier?.legal_name ?? '—'} · {p.supplier_invoice_ref}</Typography></Box> },
    { key: 'due', header: 'Due', hideBelow: 'md', render: (p) => (p.is_overdue ? <Typography fontSize={14} color="error.main" fontWeight={600}>{formatDate(p.due_date)}</Typography> : formatDate(p.due_date)) },
    { key: 'total', header: 'Total', align: 'right', render: (p) => money(p.total, p.currency) },
    { key: 'balance', header: 'Balance', align: 'right', hideBelow: 'lg', render: (p) => money(p.balance, p.currency) },
    { key: 'status', header: 'Status', render: (p) => <StatusChip status={p.status} /> },
  ];

  return (
    <>
      <PageHeader title="Payables" subtitle="Supplier invoices received, approved into voyage expenses, and paid." breadcrumbs={[{ label: 'Commercial' }, { label: 'Payables' }]}
        actions={<Can permission={P.PayablesCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>New payable</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search payable number, supplier ref…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{STATUSES.map((s) => <MenuItem key={s} value={s}>{s.replace(/_/g, ' ')}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(p) => p.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(p) => navigate(`/commercial/payables/${p.id}`)} emptyTitle="No payables" emptyDescription="Create one from a supplier invoice." />
        )}
      </Card>
      <NewPayableDialog open={dialog} onClose={() => setDialog(false)} onCreated={(p) => navigate(`/commercial/payables/${p.id}`)} />
    </>
  );
}

function NewPayableDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (p: Payable) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const blank = { supplier_company_id: null as number | null, supplier_invoice_ref: '', issue_date: '', due_date: '', currency: 'USD' as string | null, subtotal: '', tax: '0' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: () => payablesApi.create(f),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['payables'] }); setF(blank); onCreated(r.data); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New payable</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}><CompanyAutocomplete label="Supplier" required value={f.supplier_company_id} onChange={(id) => setF({ ...f, supplier_company_id: id })} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><TextField label="Supplier invoice ref" required value={f.supplier_invoice_ref} onChange={(e) => setF({ ...f, supplier_invoice_ref: e.target.value })} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><CurrencySelect label="Currency" required value={f.currency} onChange={(v) => setF({ ...f, currency: v })} /></Grid>
          <Grid size={6}><TextField type="date" label="Issue date" required value={f.issue_date} onChange={(e) => setF({ ...f, issue_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={6}><TextField type="date" label="Due date" required value={f.due_date} onChange={(e) => setF({ ...f, due_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={6}><TextField label="Subtotal" required value={f.subtotal} onChange={(e) => setF({ ...f, subtotal: e.target.value })} /></Grid>
          <Grid size={6}><TextField label="Tax" value={f.tax} onChange={(e) => setF({ ...f, tax: e.target.value })} /></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!f.supplier_company_id || !f.supplier_invoice_ref || !f.issue_date || !f.due_date || !f.subtotal || !f.currency} onClick={() => create.mutate()}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
