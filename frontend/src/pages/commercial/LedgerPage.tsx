import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, Grid, MenuItem, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import { voyageExpensesApi, voyageRevenuesApi } from '../../api/finance';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { Can } from '../../auth/guards';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { ReferenceSelect, CurrencySelect } from '../../components/MasterPickers';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { money } from '../../utils/decimal';
import type { VoyageExpense, VoyageRevenue } from '../../types/finance';

type Kind = 'revenue' | 'expense';

export default function LedgerPage() {
  const [kind, setKind] = useState<Kind>('revenue');
  return (
    <>
      <PageHeader title="Revenue & Expenses" subtitle="Voyage revenue and expense lines that feed invoices, payables and voyage P&L." breadcrumbs={[{ label: 'Commercial' }, { label: 'Revenue & Expenses' }]} />
      <Card>
        <Tabs value={kind} onChange={(_, v) => setKind(v)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="revenue" label="Revenue" /><Tab value="expense" label="Expenses" />
        </Tabs>
        {kind === 'revenue' ? <RevenueTable /> : <ExpenseTable />}
      </Card>
    </>
  );
}

function RevenueTable() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const [cancelling, setCancelling] = useState<VoyageRevenue | null>(null);
  const [reason, setReason] = useState('');
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['voyage-revenues', q], queryFn: () => voyageRevenuesApi.list(q), placeholderData: keepPreviousData });
  const refresh = () => qc.invalidateQueries({ queryKey: ['voyage-revenues'] });

  const confirm = useMutation({ mutationFn: (r: VoyageRevenue) => voyageRevenuesApi.confirm(r.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });
  const cancel = useMutation({
    mutationFn: () => voyageRevenuesApi.cancel(cancelling!.id, reason),
    onSuccess: (r) => { notify.success(r.message); setCancelling(null); setReason(''); refresh(); },
    onError: (e) => notify.error(e),
  });
  const remove = useMutation({ mutationFn: (r: VoyageRevenue) => voyageRevenuesApi.remove(r.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });

  const columns: Column<VoyageRevenue>[] = [
    { key: 'desc', header: 'Description', render: (r) => <Box><Typography fontWeight={600} fontSize={14}>{r.description}</Typography><Typography variant="body2" color="text.secondary">{r.category?.name ?? '—'}{r.voyage ? ` · ${r.voyage.voyage_number}` : ''}</Typography></Box> },
    { key: 'amount', header: 'Amount', align: 'right', render: (r) => money(r.amount, r.currency) },
    { key: 'net', header: 'Net', align: 'right', hideBelow: 'md', render: (r) => money(r.net_amount, r.currency) },
    { key: 'base', header: 'Base', align: 'right', hideBelow: 'lg', render: (r) => money(r.base_amount, r.base_currency) },
    { key: 'status', header: 'Status', render: (r) => <StatusChip status={r.status} /> },
    { key: 'actions', header: '', align: 'right', render: (r) => (
      <Stack direction="row" spacing={0.5} justifyContent="flex-end" onClick={(e) => e.stopPropagation()}>
        {can(P.RevenuesUpdate) && r.status === 'draft' && <Button size="small" onClick={() => confirm.mutate(r)}>Confirm</Button>}
        {can(P.RevenuesUpdate) && ['draft', 'confirmed'].includes(r.status) && <Button size="small" color="error" onClick={() => setCancelling(r)}>Cancel</Button>}
        {can(P.RevenuesUpdate) && r.status === 'draft' && <Button size="small" color="error" onClick={() => remove.mutate(r)}>Delete</Button>}
      </Stack>
    ) },
  ];

  return (
    <>
      <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search description…">
        <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
          <MenuItem value="">All</MenuItem>{['draft', 'confirmed', 'invoiced', 'cancelled'].map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
        </TextField>
        <Can permission={P.RevenuesCreate}><Button variant="outlined" startIcon={<AddIcon />} onClick={() => setDialog(true)} sx={{ flexShrink: 0 }}>New revenue</Button></Can>
      </FilterBar>
      {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
        <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(r) => r.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
          emptyTitle="No revenue lines" emptyDescription="Create a revenue line manually or from a confirmed activity." />
      )}
      <NewRevenueDialog open={dialog} onClose={() => setDialog(false)} onCreated={refresh} />
      <Dialog open={!!cancelling} onClose={() => { setCancelling(null); setReason(''); }} maxWidth="xs" fullWidth>
        <DialogTitle>Cancel revenue line</DialogTitle>
        <DialogContent dividers>
          <TextField label="Reason" fullWidth required multiline minRows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => { setCancelling(null); setReason(''); }}>Close</Button>
          <LoadingButton variant="contained" color="error" loading={cancel.isPending} disabled={reason.trim().length < 3} onClick={() => cancel.mutate()}>Cancel line</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}

function NewRevenueDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const notify = useNotify();
  const blank = { revenue_category_id: null as number | null, description: '', quantity: '', rate: '', amount: '', currency: 'USD' as string | null, remarks: '' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: () => voyageRevenuesApi.create({ ...f, quantity: f.quantity || undefined, rate: f.rate || undefined, amount: f.amount || undefined }),
    onSuccess: (r) => { notify.success(r.message); setF(blank); onCreated(); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New revenue line</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}><ReferenceSelect type="revenue-categories" label="Category" required value={f.revenue_category_id} onChange={(id) => setF({ ...f, revenue_category_id: id })} /></Grid>
          <Grid size={12}><TextField label="Description" required value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField label="Quantity" value={f.quantity} onChange={(e) => setF({ ...f, quantity: e.target.value })} /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField label="Rate" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} /></Grid>
          <Grid size={{ xs: 12, sm: 3 }}><TextField label="Amount (override)" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} helperText="Leave blank to use qty × rate" /></Grid>
          <Grid size={{ xs: 12, sm: 3 }}><CurrencySelect label="Currency" required value={f.currency} onChange={(v) => setF({ ...f, currency: v })} /></Grid>
          <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={(e) => setF({ ...f, remarks: e.target.value })} /></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!f.revenue_category_id || !f.description || !f.currency} onClick={() => create.mutate()}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}

function ExpenseTable() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const [cancelling, setCancelling] = useState<VoyageExpense | null>(null);
  const [reason, setReason] = useState('');
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['voyage-expenses', q], queryFn: () => voyageExpensesApi.list(q), placeholderData: keepPreviousData });
  const refresh = () => qc.invalidateQueries({ queryKey: ['voyage-expenses'] });

  const confirm = useMutation({ mutationFn: (r: VoyageExpense) => voyageExpensesApi.confirm(r.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });
  const approve = useMutation({ mutationFn: (r: VoyageExpense) => voyageExpensesApi.approve(r.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });
  const cancel = useMutation({
    mutationFn: () => voyageExpensesApi.cancel(cancelling!.id, reason),
    onSuccess: (r) => { notify.success(r.message); setCancelling(null); setReason(''); refresh(); },
    onError: (e) => notify.error(e),
  });
  const remove = useMutation({ mutationFn: (r: VoyageExpense) => voyageExpensesApi.remove(r.id), onSuccess: (r) => { notify.success(r.message); refresh(); }, onError: (e) => notify.error(e) });

  const columns: Column<VoyageExpense>[] = [
    { key: 'desc', header: 'Description', render: (r) => <Box><Typography fontWeight={600} fontSize={14}>{r.description}</Typography><Typography variant="body2" color="text.secondary">{r.category?.name ?? '—'}{r.voyage ? ` · ${r.voyage.voyage_number}` : ''}</Typography></Box> },
    { key: 'supplier', header: 'Supplier', hideBelow: 'md', render: (r) => r.supplier?.legal_name ?? '—' },
    { key: 'amount', header: 'Amount', align: 'right', render: (r) => money(r.amount, r.currency) },
    { key: 'base', header: 'Base', align: 'right', hideBelow: 'lg', render: (r) => money(r.base_amount, r.base_currency) },
    { key: 'status', header: 'Status', render: (r) => <StatusChip status={r.status} /> },
    { key: 'actions', header: '', align: 'right', render: (r) => (
      <Stack direction="row" spacing={0.5} justifyContent="flex-end" onClick={(e) => e.stopPropagation()}>
        {can(P.ExpensesUpdate) && r.status === 'draft' && <Button size="small" onClick={() => confirm.mutate(r)}>Confirm</Button>}
        {can(P.ExpensesApprove) && r.status === 'confirmed' && <Button size="small" color="success" onClick={() => approve.mutate(r)}>Approve</Button>}
        {can(P.ExpensesUpdate) && ['draft', 'confirmed', 'approved'].includes(r.status) && <Button size="small" color="error" onClick={() => setCancelling(r)}>Cancel</Button>}
        {can(P.ExpensesUpdate) && r.status === 'draft' && <Button size="small" color="error" onClick={() => remove.mutate(r)}>Delete</Button>}
      </Stack>
    ) },
  ];

  return (
    <>
      <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search description…">
        <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
          <MenuItem value="">All</MenuItem>{['draft', 'confirmed', 'approved', 'paid', 'cancelled'].map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
        </TextField>
        <Can permission={P.ExpensesCreate}><Button variant="outlined" startIcon={<AddIcon />} onClick={() => setDialog(true)} sx={{ flexShrink: 0 }}>New expense</Button></Can>
      </FilterBar>
      {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
        <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(r) => r.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
          emptyTitle="No expense lines" emptyDescription="Create one manually, or they will arrive from Port DA / bunkers / payables." />
      )}
      <NewExpenseDialog open={dialog} onClose={() => setDialog(false)} onCreated={refresh} />
      <Dialog open={!!cancelling} onClose={() => { setCancelling(null); setReason(''); }} maxWidth="xs" fullWidth>
        <DialogTitle>Cancel expense line</DialogTitle>
        <DialogContent dividers>
          <TextField label="Reason" fullWidth required multiline minRows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => { setCancelling(null); setReason(''); }}>Close</Button>
          <LoadingButton variant="contained" color="error" loading={cancel.isPending} disabled={reason.trim().length < 3} onClick={() => cancel.mutate()}>Cancel line</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}

function NewExpenseDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const notify = useNotify();
  const blank = { expense_category_id: null as number | null, description: '', quantity: '', rate: '', amount: '', currency: 'USD' as string | null, remarks: '' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: () => voyageExpensesApi.create({ ...f, quantity: f.quantity || undefined, rate: f.rate || undefined, amount: f.amount || undefined }),
    onSuccess: (r) => { notify.success(r.message); setF(blank); onCreated(); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New expense line</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}><ReferenceSelect type="expense-categories" label="Category" required value={f.expense_category_id} onChange={(id) => setF({ ...f, expense_category_id: id })} /></Grid>
          <Grid size={12}><TextField label="Description" required value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField label="Quantity" value={f.quantity} onChange={(e) => setF({ ...f, quantity: e.target.value })} /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField label="Rate" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} /></Grid>
          <Grid size={{ xs: 12, sm: 3 }}><TextField label="Amount (override)" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} helperText="Leave blank to use qty × rate" /></Grid>
          <Grid size={{ xs: 12, sm: 3 }}><CurrencySelect label="Currency" required value={f.currency} onChange={(v) => setF({ ...f, currency: v })} /></Grid>
          <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={(e) => setF({ ...f, remarks: e.target.value })} /></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!f.expense_category_id || !f.description || !f.currency} onClick={() => create.mutate()}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
