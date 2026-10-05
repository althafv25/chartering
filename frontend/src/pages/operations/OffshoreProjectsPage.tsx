import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, Grid, IconButton, MenuItem, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import ListAltOutlined from '@mui/icons-material/ListAltOutlined';
import { offshoreProjectsApi } from '../../api/offshore';
import { contractsApi } from '../../api/contracts';
import { locationsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CompanyAutocomplete } from '../../components/MasterPickers';
import { Can } from '../../auth/guards';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { PROJECT_STATUSES } from '../../constants/operations';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, humanize } from '../../utils/format';
import type { OffshoreProject } from '../../types/offshore';

type Form = { code: string; name: string; client_company_id: number | null; contract_id: string; offshore_location_id: string; field_name: string;
  start_date: string; end_date: string; status: string; remarks: string };

export default function OffshoreProjectsPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [edit, setEdit] = useState<{ p: OffshoreProject | null; f: Form } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['offshore-projects', q], queryFn: () => offshoreProjectsApi.list(q), placeholderData: keepPreviousData });
  const contracts = useQuery({ queryKey: ['contracts', 'usable'], queryFn: () => contractsApi.list({ per_page: 100 }), enabled: !!edit });
  const locations = useQuery({ queryKey: ['offshore-locations', 'all'], queryFn: () => locationsApi.list({ per_page: 100 }), enabled: !!edit });

  const save = useMutation({
    mutationFn: () => {
      const { p, f } = edit!;
      return offshoreProjectsApi.save(p?.id ?? null, {
        ...f, contract_id: f.contract_id ? Number(f.contract_id) : null, offshore_location_id: f.offshore_location_id ? Number(f.offshore_location_id) : null,
        start_date: f.start_date || null, end_date: f.end_date || null, field_name: f.field_name || null, remarks: f.remarks || null,
        ...(f.contract_id ? {} : { client_company_id: f.client_company_id }), ...(p ? { lock_version: p.lock_version } : { code: f.code.toUpperCase() }),
      });
    },
    onSuccess: (r) => { notify.success(r.message); setEdit(null); qc.invalidateQueries({ queryKey: ['offshore-projects'] }); },
    onError: (e) => setError(errorMessage(e)),
  });

  const open = (p: OffshoreProject | null) => {
    setError(null);
    setEdit({ p, f: { code: p?.code ?? '', name: p?.name ?? '', client_company_id: p?.client_company_id ?? null, contract_id: p?.contract_id ? String(p.contract_id) : '',
      offshore_location_id: p?.offshore_location_id ? String(p.offshore_location_id) : '', field_name: p?.field_name ?? '', start_date: p?.start_date ?? '',
      end_date: p?.end_date ?? '', status: p?.status ?? 'planned', remarks: p?.remarks ?? '' } });
  };

  const columns: Column<OffshoreProject>[] = [
    { key: 'code', header: 'Project', render: (p) => <Box><Typography fontWeight={600} fontSize={14}>{p.code}</Typography><Typography variant="body2" color="text.secondary">{p.name}</Typography></Box> },
    { key: 'client', header: 'Client', render: (p) => p.client?.legal_name ?? '—' },
    { key: 'contract', header: 'Contract', hideBelow: 'md', render: (p) => p.contract?.contract_number ?? '—' },
    { key: 'field', header: 'Field / location', hideBelow: 'md', render: (p) => [p.field_name, p.location?.name].filter(Boolean).join(' · ') || '—' },
    { key: 'period', header: 'Period', hideBelow: 'lg', render: (p) => (p.start_date ? `${formatDate(p.start_date)} – ${formatDate(p.end_date)}` : '—') },
    { key: 'n', header: 'Activities', align: 'right', render: (p) => p.activities_count ?? 0 },
    { key: 'status', header: 'Status', render: (p) => <StatusChip status={p.status} /> },
    { key: 'actions', header: '', align: 'right', render: (p) => (
      <Box onClick={(e) => e.stopPropagation()}>
        <Tooltip title="Activities"><IconButton size="small" aria-label={`Activities of ${p.code}`} onClick={() => navigate(`/operations/offshore-activities?project=${p.id}`)}><ListAltOutlined fontSize="small" /></IconButton></Tooltip>
        {can(P.OffshoreProjectsManage) && <Tooltip title="Edit"><IconButton size="small" aria-label={`Edit ${p.code}`} onClick={() => open(p)}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
      </Box>
    ) },
  ];
  const f = edit?.f;
  const setF = (patch: Partial<Form>) => setEdit((x) => x && { ...x, f: { ...x.f, ...patch } });

  return (
    <>
      <PageHeader title="Offshore Projects" subtitle="Campaigns per client and field; activities roll up per project" breadcrumbs={[{ label: 'Operations' }, { label: 'Offshore Projects' }]}
        actions={<Can permission={P.OffshoreProjectsManage}><Button variant="contained" startIcon={<AddIcon />} onClick={() => open(null)}>New project</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search code, name or field…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{PROJECT_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(p) => p.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(p) => navigate(`/operations/offshore-activities?project=${p.id}`)} emptyTitle="No projects" />
        )}
      </Card>

      <Dialog open={!!edit} onClose={() => setEdit(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{edit?.p ? `Edit ${edit.p.code}` : 'New offshore project'}</DialogTitle>
        {f && (
          <DialogContent dividers>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 4 }}><TextField label="Code" required disabled={!!edit?.p} value={f.code} onChange={(e) => setF({ code: e.target.value.toUpperCase() })} helperText="A–Z, 0–9, - _" /></Grid>
              <Grid size={{ xs: 12, sm: 8 }}><TextField label="Name" required value={f.name} onChange={(e) => setF({ name: e.target.value })} /></Grid>
              <Grid size={12}>
                <TextField select label="Contract (optional)" value={f.contract_id} onChange={(e) => setF({ contract_id: e.target.value })} helperText="The client is taken from the contract customer">
                  <MenuItem value="">—</MenuItem>
                  {contracts.data?.data.filter((c) => !['draft', 'cancelled', 'under_review'].includes(c.status)).map((c) => <MenuItem key={c.id} value={String(c.id)}>{c.contract_number} — {c.customer?.legal_name}</MenuItem>)}
                </TextField>
              </Grid>
              {!f.contract_id && <Grid size={12}><CompanyAutocomplete label="Client" required value={f.client_company_id} onChange={(id) => setF({ client_company_id: id })} /></Grid>}
              <Grid size={{ xs: 12, sm: 6 }}><TextField label="Field" value={f.field_name} onChange={(e) => setF({ field_name: e.target.value })} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField select label="Location" value={f.offshore_location_id} onChange={(e) => setF({ offshore_location_id: e.target.value })}>
                  <MenuItem value="">—</MenuItem>{(locations.data?.data ?? []).map((l) => <MenuItem key={l.id} value={String(l.id)}>{l.name}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={6}><TextField type="date" label="Start" value={f.start_date} onChange={(e) => setF({ start_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
              <Grid size={6}><TextField type="date" label="End" value={f.end_date} onChange={(e) => setF({ end_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
              {edit?.p && (
                <Grid size={12}>
                  <TextField select label="Status" value={f.status} onChange={(e) => setF({ status: e.target.value })}>
                    {PROJECT_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
                  </TextField>
                </Grid>
              )}
              <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={(e) => setF({ remarks: e.target.value })} /></Grid>
            </Grid>
          </DialogContent>
        )}
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setEdit(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} disabled={!f?.code || !f.name || (!f.contract_id && !f.client_company_id)} onClick={() => save.mutate()}>Save</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
