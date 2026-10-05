import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, Grid, MenuItem, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import { laytimeApi, voyagesApi } from '../../api/operations';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { humanize } from '../../utils/format';
import { money, trimZeros } from '../../utils/decimal';
import type { LaytimeCalculation } from '../../types/operations';

export default function LaytimePage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const q = { page, per_page: 15, status: status || undefined, calculation_type: type || undefined };
  const list = useQuery({ queryKey: ['laytime', q], queryFn: () => laytimeApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<LaytimeCalculation>[] = [
    { key: 'call', header: 'Port call', render: (c) => <Box><Typography fontWeight={600} fontSize={14}>{c.port_call?.label ?? '—'}</Typography><Typography variant="body2" color="text.secondary">{c.voyage?.voyage_number} · {humanize(c.calculation_type)}</Typography></Box> },
    { key: 'allowed', header: 'Allowed h', align: 'right', hideBelow: 'md', render: (c) => (c.allowed_hours ? trimZeros(c.allowed_hours) : '—') },
    { key: 'used', header: 'Used h', align: 'right', hideBelow: 'md', render: (c) => (c.used_hours ? trimZeros(c.used_hours) : '—') },
    { key: 'result', header: 'Result', align: 'right', render: (c) => (c.demurrage_amount ? `Demurrage ${money(c.demurrage_amount, c.currency ?? undefined)}` : c.despatch_amount ? `Despatch ${money(c.despatch_amount, c.currency ?? undefined)}` : '—') },
    { key: 'status', header: 'Status', render: (c) => <StatusChip status={c.status} /> },
  ];

  return (
    <>
      <PageHeader title="Laytime" subtitle="Laytime, demurrage and despatch per port call. Terms are configurable; nothing is hard-coded to a charter party." breadcrumbs={[{ label: 'Operations' }, { label: 'Laytime' }]}
        actions={<Can permission={P.LaytimeCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>New calculation</Button></Can>} />
      <Card>
        <FilterBar search="" onSearch={() => {}} placeholder="">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{['draft', 'submitted', 'agreed', 'disputed'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select label="Type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{['load', 'discharge', 'reversible'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(c) => c.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(c) => navigate(`/operations/laytime/${c.id}`)} emptyTitle="No laytime calculations" emptyDescription="Create one for a port call, then enter the terms, statement of facts and exceptions." />
        )}
      </Card>
      <NewLaytimeDialog open={dialog} onClose={() => setDialog(false)} onCreated={(c) => navigate(`/operations/laytime/${c.id}`)} />
    </>
  );
}

function NewLaytimeDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (c: LaytimeCalculation) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [voyageId, setVoyageId] = useState<number | ''>('');
  const [callId, setCallId] = useState<number | ''>('');
  const [type, setType] = useState('load');
  const [error, setError] = useState<string | null>(null);
  const voyages = useQuery({ queryKey: ['voyages', 'open-for-da'], queryFn: () => voyagesApi.list({ per_page: 100, open: 1 }), enabled: open });
  const voyage = useQuery({ queryKey: ['voyages', voyageId], queryFn: () => voyagesApi.get(Number(voyageId)), enabled: open && voyageId !== '' });
  const calls = (voyage.data?.port_calls ?? []).filter((c) => c.status !== 'cancelled');
  const create = useMutation({
    mutationFn: () => laytimeApi.create({ port_call_id: callId, voyage_id: voyageId, calculation_type: type, contract_id: voyage.data?.contract?.id ?? null }),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['laytime'] }); setVoyageId(''); setCallId(''); setError(null); onCreated(r.data); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New laytime calculation</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}>
            <TextField select label="Voyage" required value={voyageId} onChange={(e) => { setVoyageId(Number(e.target.value)); setCallId(''); }}>
              {(voyages.data?.data ?? []).map((v) => <MenuItem key={v.id} value={v.id}>{v.voyage_number}{v.vessel ? ` · ${v.vessel.name}` : ''}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={12}>
            <TextField select label="Port call" required disabled={voyageId === ''} value={callId} onChange={(e) => setCallId(Number(e.target.value))}>
              {calls.map((c) => <MenuItem key={c.id} value={c.id}>{c.sequence}. {c.label} · {humanize(c.purpose)}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={12}>
            <TextField select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
              {['load', 'discharge', 'reversible'].map((t) => <MenuItem key={t} value={t}>{humanize(t)}</MenuItem>)}
            </TextField>
          </Grid>
        </Grid>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>Times are entered in the port's local time on the next page.</Typography>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={callId === ''} onClick={() => create.mutate()}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
