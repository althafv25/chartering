import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, Grid, MenuItem, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import { portDasApi, voyagesApi } from '../../api/operations';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CurrencySelect } from '../../components/MasterPickers';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { humanize } from '../../utils/format';
import { money } from '../../utils/decimal';
import type { PortDa } from '../../types/operations';

export default function PortDasPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined, da_type: type || undefined };
  const list = useQuery({ queryKey: ['port-das', q], queryFn: () => portDasApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<PortDa>[] = [
    { key: 'no', header: 'DA', render: (d) => <Box><Typography fontWeight={600} fontSize={14}>{d.da_number}</Typography><Typography variant="body2" color="text.secondary">{d.voyage?.voyage_number} · {d.port?.name}</Typography></Box> },
    { key: 'type', header: 'Type', render: (d) => humanize(d.da_type) },
    { key: 'agent', header: 'Agent', hideBelow: 'md', render: (d) => d.agent?.legal_name ?? '—' },
    { key: 'total', header: 'Total', align: 'right', render: (d) => money(d.total_amount, d.currency) },
    { key: 'base', header: 'Base', align: 'right', hideBelow: 'lg', render: (d) => money(d.base_amount, d.base_currency) },
    { key: 'status', header: 'Status', render: (d) => <StatusChip status={d.status} /> },
  ];

  return (
    <>
      <PageHeader title="Port DA" subtitle="Proforma and final port disbursement accounts. Approved final DAs book voyage expenses." breadcrumbs={[{ label: 'Operations' }, { label: 'Port DA' }]}
        actions={<Can permission={P.PortDaCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>New DA</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search DA number…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{['draft', 'submitted', 'approved', 'settled'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select label="Type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem><MenuItem value="proforma">Proforma</MenuItem><MenuItem value="final">Final</MenuItem>
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(d) => d.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(d) => navigate(`/operations/port-da/${d.id}`)} emptyTitle="No port DAs" emptyDescription="Create a proforma DA for a port call, then a final DA when the agent's account arrives." />
        )}
      </Card>
      <NewDaDialog open={dialog} onClose={() => setDialog(false)} onCreated={(d) => navigate(`/operations/port-da/${d.id}`)} />
    </>
  );
}

function NewDaDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (d: PortDa) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [voyageId, setVoyageId] = useState<number | ''>('');
  const [callId, setCallId] = useState<number | ''>('');
  const [daType, setDaType] = useState<'proforma' | 'final'>('proforma');
  const [currency, setCurrency] = useState<string | null>('USD');
  const [proformaId, setProformaId] = useState<number | ''>('');
  const [error, setError] = useState<string | null>(null);

  const voyages = useQuery({ queryKey: ['voyages', 'open-for-da'], queryFn: () => voyagesApi.list({ per_page: 100, open: 1 }), enabled: open });
  const voyage = useQuery({ queryKey: ['voyages', voyageId], queryFn: () => voyagesApi.get(Number(voyageId)), enabled: open && voyageId !== '' });
  // Only port calls at a port can carry a DA (offshore locations have no port agent).
  const calls = (voyage.data?.port_calls ?? []).filter((c) => c.port !== null && c.status !== 'cancelled');
  const call = calls.find((c) => c.id === callId);
  const proformas = useQuery({
    queryKey: ['port-das', 'proformas', callId],
    queryFn: () => portDasApi.list({ port_call_id: Number(callId), da_type: 'proforma', per_page: 50 }),
    enabled: open && callId !== '' && daType === 'final',
  });

  const reset = () => { setVoyageId(''); setCallId(''); setDaType('proforma'); setProformaId(''); setError(null); };
  const create = useMutation({
    mutationFn: () => portDasApi.create({ port_call_id: callId, voyage_id: voyageId, port_id: call!.port!.id, da_type: daType, currency, proforma_da_id: daType === 'final' && proformaId !== '' ? proformaId : null }),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['port-das'] }); reset(); onCreated(r.data); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New port DA</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}>
            <TextField select label="Voyage" required value={voyageId} onChange={(e) => { setVoyageId(Number(e.target.value)); setCallId(''); setProformaId(''); }}>
              {(voyages.data?.data ?? []).map((v) => <MenuItem key={v.id} value={v.id}>{v.voyage_number}{v.vessel ? ` · ${v.vessel.name}` : ''}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={12}>
            <TextField select label="Port call" required disabled={voyageId === ''} value={callId} onChange={(e) => { setCallId(Number(e.target.value)); setProformaId(''); }}
              helperText={voyageId !== '' && !voyage.isLoading && calls.length === 0 ? 'This voyage has no port calls at a port. Add one on the voyage itinerary first.' : undefined}>
              {calls.map((c) => <MenuItem key={c.id} value={c.id}>{c.sequence}. {c.label} · {humanize(c.purpose)}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6 }}>
            <TextField select label="Type" value={daType} onChange={(e) => setDaType(e.target.value as 'proforma' | 'final')}>
              <MenuItem value="proforma">Proforma (estimate)</MenuItem><MenuItem value="final">Final (actual)</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6 }}><CurrencySelect label="Currency" required value={currency} onChange={setCurrency} /></Grid>
          {daType === 'final' && (
            <Grid size={12}>
              <TextField select label="Proforma to compare with (optional)" value={proformaId} onChange={(e) => setProformaId(e.target.value === '' ? '' : Number(e.target.value))}>
                <MenuItem value="">—</MenuItem>
                {(proformas.data?.data ?? []).map((d) => <MenuItem key={d.id} value={d.id}>{d.da_number} · {humanize(d.status)}</MenuItem>)}
              </TextField>
            </Grid>
          )}
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!call || !currency} onClick={() => create.mutate()}>Create</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
