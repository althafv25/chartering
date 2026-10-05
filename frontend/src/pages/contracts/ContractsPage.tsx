import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, Grid, MenuItem, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import { contractsApi } from '../../api/contracts';
import { vesselsApi } from '../../api/masters';
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
import { CONTRACT_STATUSES, CONTRACT_TYPES } from '../../constants/contracts';
import { labelOf } from '../../constants/chartering';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, humanize } from '../../utils/format';
import type { Contract } from '../../types/contracts';

export default function ContractsPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [expiring, setExpiring] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined, contract_type: type || undefined, expiring_within_days: expiring || undefined };
  const list = useQuery({ queryKey: ['contracts', q], queryFn: () => contractsApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Contract>[] = [
    { key: 'no', header: 'Contract', render: (c) => <Box><Typography fontWeight={600} fontSize={14}>{c.contract_number}</Typography><Typography variant="body2" color="text.secondary">{c.title}</Typography></Box> },
    { key: 'type', header: 'Type', render: (c) => labelOf(CONTRACT_TYPES, c.contract_type) },
    { key: 'customer', header: 'Customer', render: (c) => c.customer?.legal_name ?? '—' },
    { key: 'vessel', header: 'Vessel', hideBelow: 'md', render: (c) => c.vessel?.name ?? '—' },
    { key: 'period', header: 'Period', hideBelow: 'md', render: (c) => (c.start_date ? `${formatDate(c.start_date)} – ${formatDate(c.end_date)}` : '—') },
    { key: 'ver', header: 'Version', align: 'right', hideBelow: 'lg', render: (c) => `v${c.current_version}` },
    { key: 'status', header: 'Status', render: (c) => <StatusChip status={c.status} /> },
  ];

  return (
    <>
      <PageHeader title="Contracts" subtitle="Charter and service contracts with versioned rates, clauses and amendments" breadcrumbs={[{ label: 'Contracts' }]}
        actions={<Can permission={P.ContractsCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>New contract</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search number, title, customer, vessel…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{CONTRACT_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select label="Type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 190 } }}>
            <MenuItem value="">All</MenuItem>{CONTRACT_TYPES.map((t) => <MenuItem key={t.value} value={t.value}>{t.label}</MenuItem>)}
          </TextField>
          <TextField select label="Expiring" value={expiring} onChange={(e) => { setExpiring(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">Any time</MenuItem>{['30', '60', '90'].map((d) => <MenuItem key={d} value={d}>within {d} days</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(c) => c.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(c) => navigate(`/contracts/${c.id}`)} emptyTitle="No contracts" emptyDescription="Create one from an approved fixture or manually." />
        )}
      </Card>
      <NewContractDialog open={dialog} onClose={() => setDialog(false)} onCreated={(c) => navigate(`/contracts/${c.id}`)} />
    </>
  );
}

function NewContractDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (c: Contract) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }), enabled: open });
  const blank = { contract_type: 'offshore_charter', title: '', customer_company_id: null as number | null, vessel_id: '' as number | '', currency: 'USD' as string | null, start_date: '', end_date: '' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: () => contractsApi.create({ ...f, vessel_id: f.vessel_id || null, start_date: f.start_date || null, end_date: f.end_date || null }),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['contracts'] }); setF(blank); onCreated(r.data); },
    onError: (e) => setError(errorMessage(e)),
  });
  const needsVessel = !['service', 'other'].includes(f.contract_type);

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New contract</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 6 }}>
            <TextField select label="Contract type" value={f.contract_type} onChange={(e) => setF({ ...f, contract_type: e.target.value })}>
              {CONTRACT_TYPES.map((t) => <MenuItem key={t.value} value={t.value}>{t.label}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6 }}><CurrencySelect label="Currency" required value={f.currency} onChange={(v) => setF({ ...f, currency: v })} /></Grid>
          <Grid size={12}><TextField label="Title" required value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Grid>
          <Grid size={12}><CompanyAutocomplete label="Customer" required value={f.customer_company_id} onChange={(id) => setF({ ...f, customer_company_id: id })} /></Grid>
          <Grid size={12}>
            <TextField select label={needsVessel ? 'Vessel' : 'Vessel (optional)'} required={needsVessel} value={f.vessel_id} onChange={(e) => setF({ ...f, vessel_id: Number(e.target.value) || '' })}>
              {!needsVessel && <MenuItem value="">—</MenuItem>}
              {(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={v.id}>{v.name} ({v.code})</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={6}><TextField type="date" label="Start" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={6}><TextField type="date" label="End" value={f.end_date} onChange={(e) => setF({ ...f, end_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
        </Grid>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>Rates and clauses are added on the contract page while it is a draft.</Typography>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!f.title || !f.customer_company_id || !f.currency || (needsVessel && !f.vessel_id)} onClick={() => create.mutate()}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
