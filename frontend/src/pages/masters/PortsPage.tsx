import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, Dialog, DialogActions, DialogContent, DialogTitle, Grid, IconButton, MenuItem, Tab, Tabs, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { locationsApi, portsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CompanyAutocomplete, CountrySelect, PortAutocomplete } from '../../components/MasterPickers';
import { Can } from '../../auth/guards';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { countryName } from '../../constants/countries';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import type { OffshoreLocation, Port } from '../../types/masters';

type Row = Record<string, string | number | null>;
const clean = (o: Row) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]));

export function PortDialog({ open, port, onClose }: { open: boolean; port: Port | null; onClose: (saved?: Port) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const blank: Row = { name: '', unlocode: '', country: null, region: '', latitude: '', longitude: '', timezone: 'UTC', max_draft_m: '', max_loa_m: '', max_beam_m: '', restrictions: '', notes: '', status: 'active' };
  const [f, setF] = useState<Row>(blank);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setErrors({}); setError(null);
    setF(port ? Object.fromEntries(Object.keys(blank).map((k) => [k, (port as unknown as Row)[k] ?? ''])) : blank);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, port]);

  const save = useMutation({
    mutationFn: () => (port ? portsApi.update(port.id, clean(f)) : portsApi.create(clean(f))),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['ports'] }); onClose(r.data); },
    onError: (e: unknown) => {
      const fe = (e as { fieldErrors?: Record<string, string[]> }).fieldErrors ?? {};
      setErrors(fe);
      if (!Object.keys(fe).length) setError(errorMessage(e));
    },
  });
  const tf = (k: string, label: string, extra: object = {}) => (
    <TextField label={label} value={f[k] ?? ''} onChange={(e) => setF((x) => ({ ...x, [k]: e.target.value }))} error={!!errors[k]} helperText={errors[k]?.[0]} {...extra} />
  );

  return (
    <Dialog open={open} onClose={() => onClose()} maxWidth="md" fullWidth>
      <DialogTitle>{port ? `Edit ${port.name}` : 'New port'}</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 5 }}>{tf('name', 'Port name', { required: true })}</Grid>
          <Grid size={{ xs: 12, md: 3 }}>{tf('unlocode', 'UN/LOCODE', { helperText: errors.unlocode?.[0] ?? 'e.g. AEJEA' })}</Grid>
          <Grid size={{ xs: 12, md: 4 }}><CountrySelect label="Country" required value={f.country as string | null} onChange={(v) => setF((x) => ({ ...x, country: v }))} error={errors.country?.[0]} /></Grid>
          <Grid size={{ xs: 12, md: 4 }}>{tf('region', 'Region', { helperText: 'e.g. Arabian Gulf' })}</Grid>
          <Grid size={{ xs: 12, md: 4 }}>{tf('timezone', 'Time zone', { required: true, helperText: errors.timezone?.[0] ?? 'IANA, e.g. Asia/Dubai' })}</Grid>
          <Grid size={{ xs: 12, md: 4 }}>{tf('status', 'Status', { select: true, children: [<MenuItem key="a" value="active">Active</MenuItem>, <MenuItem key="i" value="inactive">Inactive</MenuItem>] })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('latitude', 'Latitude', { inputMode: 'decimal', helperText: errors.latitude?.[0] ?? '−90 … 90' })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('longitude', 'Longitude', { inputMode: 'decimal', helperText: errors.longitude?.[0] ?? '−180 … 180' })}</Grid>
          <Grid size={{ xs: 4, md: 2 }}>{tf('max_draft_m', 'Max draft (m)', { inputMode: 'decimal' })}</Grid>
          <Grid size={{ xs: 4, md: 2 }}>{tf('max_loa_m', 'Max LOA (m)', { inputMode: 'decimal' })}</Grid>
          <Grid size={{ xs: 4, md: 2 }}>{tf('max_beam_m', 'Max beam (m)', { inputMode: 'decimal' })}</Grid>
          <Grid size={12}>{tf('restrictions', 'Restrictions', { multiline: true, minRows: 2 })}</Grid>
          <Grid size={12}>{tf('notes', 'Port notes', { multiline: true, minRows: 2 })}</Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={() => onClose()}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>{port ? 'Save changes' : 'Create port'}</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}

function LocationDialog({ open, location, onClose }: { open: boolean; location: OffshoreLocation | null; onClose: () => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const blank: Row = { code: '', name: '', field_name: '', block: '', operator_company_id: null, nearest_port_id: null, latitude: '', longitude: '', water_depth_m: '', timezone: 'UTC', remarks: '', status: 'active' };
  const [f, setF] = useState<Row>(blank);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setF(location ? Object.fromEntries(Object.keys(blank).map((k) => [k, (location as unknown as Row)[k] ?? ''])) : blank);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, location]);

  const save = useMutation({
    mutationFn: () => (location ? locationsApi.update(location.id, clean(f)) : locationsApi.create(clean(f))),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['offshore-locations'] }); onClose(); },
    onError: (e: unknown) => { const fe = (e as { fieldErrors?: Record<string, string[]> }).fieldErrors ?? {}; setErrors(fe); if (!Object.keys(fe).length) notify.error(e); },
  });
  const tf = (k: string, label: string, extra: object = {}) => (
    <TextField label={label} value={f[k] ?? ''} onChange={(e) => setF((x) => ({ ...x, [k]: e.target.value }))} error={!!errors[k]} helperText={errors[k]?.[0]} {...extra} />
  );

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>{location ? `Edit ${location.name}` : 'New offshore location'}</DialogTitle>
      <DialogContent dividers>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 3 }}>{tf('code', 'Code', { required: true })}</Grid>
          <Grid size={{ xs: 12, md: 5 }}>{tf('name', 'Name', { required: true })}</Grid>
          <Grid size={{ xs: 12, md: 4 }}>{tf('field_name', 'Field')}</Grid>
          <Grid size={{ xs: 12, md: 4 }}>{tf('block', 'Block / concession')}</Grid>
          <Grid size={{ xs: 12, md: 8 }}>
            <CompanyAutocomplete label="Operator" role="operator" value={f.operator_company_id as number | null} initial={location?.operator ?? null}
              onChange={(id) => setF((x) => ({ ...x, operator_company_id: id }))} />
          </Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('latitude', 'Latitude', { required: true, inputMode: 'decimal' })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('longitude', 'Longitude', { required: true, inputMode: 'decimal' })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('water_depth_m', 'Water depth (m)', { inputMode: 'decimal' })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('timezone', 'Time zone')}</Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            <PortAutocomplete label="Nearest port / supply base" value={f.nearest_port_id as number | null} initialLabel={location?.nearest_port?.label}
              onChange={(id) => setF((x) => ({ ...x, nearest_port_id: id }))} />
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}>{tf('status', 'Status', { select: true, children: [<MenuItem key="a" value="active">Active</MenuItem>, <MenuItem key="i" value="inactive">Inactive</MenuItem>] })}</Grid>
          <Grid size={12}>{tf('remarks', 'Remarks', { multiline: true, minRows: 2 })}</Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>Save</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}

export default function PortsPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'ports';
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [portDialog, setPortDialog] = useState(false);
  const [locDialog, setLocDialog] = useState<{ open: boolean; loc: OffshoreLocation | null }>({ open: false, loc: null });
  const [deleting, setDeleting] = useState<OffshoreLocation | null>(null);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined };

  const ports = useQuery({ queryKey: ['ports', q], queryFn: () => portsApi.list(q), placeholderData: keepPreviousData, enabled: tab === 'ports' });
  const locs = useQuery({ queryKey: ['offshore-locations', q], queryFn: () => locationsApi.list(q), placeholderData: keepPreviousData, enabled: tab === 'locations' });
  const remove = useMutation({
    mutationFn: (l: OffshoreLocation) => locationsApi.remove(l.id),
    onSuccess: (r) => { notify.success(r.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['offshore-locations'] }); },
    onError: (e) => notify.error(e),
  });

  const portCols: Column<Port>[] = [
    { key: 'name', header: 'Port', render: (p) => <Typography fontWeight={600} fontSize={14}>{p.name}</Typography> },
    { key: 'code', header: 'UN/LOCODE', render: (p) => p.unlocode ?? '—' },
    { key: 'country', header: 'Country', render: (p) => countryName(p.country) },
    { key: 'region', header: 'Region', hideBelow: 'md', render: (p) => p.region ?? '—' },
    { key: 'draft', header: 'Max draft', hideBelow: 'lg', align: 'right', render: (p) => (p.max_draft_m ? `${p.max_draft_m} m` : '—') },
    { key: 'agents', header: 'Agents', hideBelow: 'md', align: 'right', render: (p) => p.agents_count ?? 0 },
    { key: 'status', header: 'Status', render: (p) => <StatusChip status={p.status} /> },
  ];
  const locCols: Column<OffshoreLocation>[] = [
    { key: 'name', header: 'Location', render: (l) => <Box><Typography fontWeight={600} fontSize={14}>{l.name}</Typography><Typography variant="body2" color="text.secondary">{l.code}</Typography></Box> },
    { key: 'field', header: 'Field / block', render: (l) => [l.field_name, l.block].filter(Boolean).join(' · ') || '—' },
    { key: 'operator', header: 'Operator', hideBelow: 'md', render: (l) => l.operator?.legal_name ?? '—' },
    { key: 'pos', header: 'Position', hideBelow: 'md', render: (l) => `${l.latitude}, ${l.longitude}` },
    { key: 'base', header: 'Nearest port', hideBelow: 'lg', render: (l) => l.nearest_port?.label ?? '—' },
    { key: 'actions', header: '', align: 'right', render: (l) => (
      <>
        {can(P.PortsUpdate) && <Tooltip title="Edit"><IconButton size="small" aria-label={`Edit ${l.name}`} onClick={() => setLocDialog({ open: true, loc: l })}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
        {can(P.PortsDelete) && <Tooltip title="Delete"><IconButton size="small" color="error" aria-label={`Delete ${l.name}`} onClick={() => setDeleting(l)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
      </>
    ) },
  ];

  const active = tab === 'ports' ? ports : locs;

  return (
    <>
      <PageHeader
        title="Ports & offshore locations"
        subtitle="Port master data, agents and offshore fields used for itineraries and distances"
        breadcrumbs={[{ label: 'Masters' }, { label: 'Ports' }]}
        actions={<Can permission={P.PortsCreate}>
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => (tab === 'ports' ? setPortDialog(true) : setLocDialog({ open: true, loc: null }))}>
            {tab === 'ports' ? 'New port' : 'New location'}
          </Button>
        </Can>}
      />
      <Card>
        <Tabs value={tab} onChange={(_, v) => { setParams({ tab: v }); setPage(1); }} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="ports" label="Ports" />
          <Tab value="locations" label="Offshore locations" />
        </Tabs>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder={tab === 'ports' ? 'Search name or UN/LOCODE…' : 'Search name, code, field…'} />
        {active.isError ? <ErrorState error={active.error} onRetry={() => active.refetch()} /> : tab === 'ports' ? (
          <DataTable columns={portCols} rows={ports.data?.data ?? []} rowKey={(p) => p.id} loading={ports.isFetching} meta={ports.data?.meta} onPageChange={setPage}
            onRowClick={(p) => navigate(`/masters/ports/${p.id}`)} emptyTitle="No ports found" />
        ) : (
          <DataTable columns={locCols} rows={locs.data?.data ?? []} rowKey={(l) => l.id} loading={locs.isFetching} meta={locs.data?.meta} onPageChange={setPage} emptyTitle="No offshore locations" />
        )}
      </Card>
      <PortDialog open={portDialog} port={null} onClose={(saved) => { setPortDialog(false); if (saved) navigate(`/masters/ports/${saved.id}`); }} />
      <LocationDialog open={locDialog.open} location={locDialog.loc} onClose={() => setLocDialog({ open: false, loc: null })} />
      <ConfirmDialog open={!!deleting} title="Delete offshore location" message={`Delete ${deleting?.name}?`} confirmLabel="Delete" danger
        loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting)} onClose={() => setDeleting(null)} />
    </>
  );
}
