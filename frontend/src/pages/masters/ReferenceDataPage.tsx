import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, DialogActions, DialogContent, FormControlLabel, Grid, IconButton, List, ListItemButton, ListItemText, MenuItem, Stack, Switch, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import TuneIcon from '@mui/icons-material/Tune';
import { referenceApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { StatusChip } from '../../components/StatusChip';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { ReferenceSelect } from '../../components/MasterPickers';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { humanize } from '../../utils/format';
import type { AttributeDef, ReferenceFieldDef, ReferenceItem, VesselTypeItem } from '../../types/masters';

type Values = Record<string, unknown>;

export default function ReferenceDataPage() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const editable = can(P.MastersUpdate);
  const [params, setParams] = useSearchParams();
  const catalogue = useQuery({ queryKey: ['reference', 'catalogue'], queryFn: referenceApi.catalogue, staleTime: Infinity });
  const type = params.get('type') ?? 'vessel-types';
  const def = catalogue.data?.[type];
  const items = useQuery({ queryKey: ['reference', type, 'all'], queryFn: () => referenceApi.list(type), enabled: !!def });

  const [editing, setEditing] = useState<{ open: boolean; item: ReferenceItem | null }>({ open: false, item: null });
  const [deleting, setDeleting] = useState<ReferenceItem | null>(null);
  const [schemaFor, setSchemaFor] = useState<VesselTypeItem | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ['reference', type] });
  const remove = useMutation({
    mutationFn: (i: ReferenceItem) => referenceApi.remove(type, i.id),
    onSuccess: (r) => { notify.success(r.message); setDeleting(null); invalidate(); },
    onError: (e) => { setDeleting(null); notify.error(e); },
  });

  if (catalogue.isLoading) return <SectionLoader />;
  if (catalogue.isError) return <ErrorState error={catalogue.error} />;

  const fieldEntries = Object.entries(def?.fields ?? {});
  const columns: Column<ReferenceItem>[] = [
    { key: 'code', header: 'Code', render: (i) => <Typography fontFamily="monospace" fontSize={13}>{i.code}</Typography>, width: 140 },
    { key: 'name', header: 'Name', render: (i) => <Typography fontWeight={600} fontSize={14}>{i.name}</Typography> },
    ...fieldEntries.filter(([, f]) => f.type !== 'reference').map(([k, f]): Column<ReferenceItem> => ({
      key: k, header: f.label, hideBelow: 'md',
      render: (i) => (f.type === 'bool' ? (i[k] ? 'Yes' : 'No') : humanize(String(i[k] ?? '—'))),
    })),
    ...(type === 'vessel-types' ? [{ key: 'fields', header: 'Custom fields', hideBelow: 'md' as const, render: (i: ReferenceItem) => ((i as VesselTypeItem).attribute_schema ?? []).length }] : []),
    { key: 'order', header: 'Order', align: 'right', hideBelow: 'lg', render: (i) => i.sort_order },
    { key: 'status', header: 'Status', render: (i) => <StatusChip status={i.status} /> },
    { key: 'actions', header: '', align: 'right', render: (i) => editable && (
      <Stack direction="row" justifyContent="flex-end">
        {type === 'vessel-types' && <Tooltip title="Configure type-specific fields"><IconButton size="small" aria-label={`Configure fields for ${i.name}`} onClick={() => setSchemaFor(i as VesselTypeItem)}><TuneIcon fontSize="small" /></IconButton></Tooltip>}
        <Tooltip title="Edit"><IconButton size="small" aria-label={`Edit ${i.name}`} onClick={() => setEditing({ open: true, item: i })}><EditOutlined fontSize="small" /></IconButton></Tooltip>
        <Tooltip title="Delete"><IconButton size="small" color="error" aria-label={`Delete ${i.name}`} onClick={() => setDeleting(i)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>
      </Stack>
    ) },
  ];

  return (
    <>
      <PageHeader title="Reference data" subtitle="Lookup lists used across chartering, operations and finance"
        breadcrumbs={[{ label: 'Masters' }, { label: 'Reference data' }]}
        actions={editable && def && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing({ open: true, item: null })}>Add {def.label.replace(/s$/, '').toLowerCase()}</Button>} />
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 3 }}>
          <Card>
            <List dense component="nav" aria-label="Reference lists">
              {Object.entries(catalogue.data ?? {}).map(([k, d]) => (
                <ListItemButton key={k} selected={k === type} onClick={() => setParams({ type: k })}><ListItemText primary={d.label} /></ListItemButton>
              ))}
            </List>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, md: 9 }}>
          <Card>
            {!def ? <Alert severity="warning" sx={{ m: 2 }}>Unknown list.</Alert> : items.isError ? <ErrorState error={items.error} onRetry={() => items.refetch()} /> : (
              <DataTable columns={columns} rows={items.data ?? []} rowKey={(i) => i.id} loading={items.isFetching} emptyTitle="No entries" />
            )}
          </Card>
        </Grid>
      </Grid>

      {def && <ItemDialog open={editing.open} type={type} item={editing.item} fields={def.fields} onClose={() => setEditing({ open: false, item: null })} onSaved={invalidate} />}
      <AttributeSchemaDialog vesselType={schemaFor} onClose={() => setSchemaFor(null)} onSaved={invalidate} />
      <ConfirmDialog open={!!deleting} title="Delete entry" message={`Delete “${deleting?.name}”? Entries in use cannot be deleted — set them inactive instead.`}
        confirmLabel="Delete" danger loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting)} onClose={() => setDeleting(null)} />
    </>
  );
}

function ItemDialog({ open, type, item, fields, onClose, onSaved }: {
  open: boolean; type: string; item: ReferenceItem | null; fields: Record<string, ReferenceFieldDef>; onClose: () => void; onSaved: () => void;
}) {
  const notify = useNotify();
  const [v, setV] = useState<Values>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    const base: Values = { code: '', name: '', sort_order: 0, status: 'active' };
    Object.entries(fields).forEach(([k, f]) => { base[k] = f.type === 'bool' ? false : f.type === 'select' ? f.options?.[0] ?? '' : null; });
    setV(item ? { ...base, ...Object.fromEntries(Object.keys(base).map((k) => [k, item[k] ?? base[k]])) } : base);
  }, [open, item, fields]);

  const save = useMutation({
    mutationFn: () => (item ? referenceApi.update(type, item.id, v) : referenceApi.create(type, v)),
    onSuccess: (r) => { notify.success(r.message); onSaved(); onClose(); },
    onError: (e: unknown) => { const fe = (e as { fieldErrors?: Record<string, string[]> }).fieldErrors ?? {}; setErrors(fe); if (!Object.keys(fe).length) notify.error(e); },
  });
  const set = (k: string, x: unknown) => setV((s) => ({ ...s, [k]: x }));

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>{item ? `Edit ${item.name}` : 'New entry'}</DialogTitle>
      <DialogContent dividers>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 5 }}><TextField label="Code" required value={v.code ?? ''} onChange={(e) => set('code', e.target.value.toUpperCase())} error={!!errors.code} helperText={errors.code?.[0] ?? 'Letters, digits, - and _'} /></Grid>
          <Grid size={{ xs: 12, sm: 7 }}><TextField label="Name" required value={v.name ?? ''} onChange={(e) => set('name', e.target.value)} error={!!errors.name} helperText={errors.name?.[0]} /></Grid>
          {Object.entries(fields).map(([k, f]) => (
            <Grid key={k} size={{ xs: 12, sm: 6 }}>
              {f.type === 'bool' ? (
                <FormControlLabel control={<Switch checked={!!v[k]} onChange={(e) => set(k, e.target.checked)} />} label={f.label} />
              ) : f.type === 'select' ? (
                <TextField select label={f.label} required={f.required} value={v[k] ?? ''} onChange={(e) => set(k, e.target.value)} error={!!errors[k]} helperText={errors[k]?.[0]}>
                  {(f.options ?? []).map((o) => <MenuItem key={o} value={o}>{humanize(o)}</MenuItem>)}
                </TextField>
              ) : f.type === 'reference' ? (
                <ReferenceSelect type={f.reference!} label={f.label} value={(v[k] as number | null) ?? null} onChange={(id) => set(k, id)} error={errors[k]?.[0]} />
              ) : (
                <TextField label={f.label} value={v[k] ?? ''} onChange={(e) => set(k, e.target.value)} />
              )}
            </Grid>
          ))}
          <Grid size={{ xs: 6 }}><TextField label="Sort order" type="number" value={v.sort_order ?? 0} onChange={(e) => set('sort_order', Number(e.target.value))} /></Grid>
          <Grid size={{ xs: 6 }}>
            <TextField select label="Status" value={v.status ?? 'active'} onChange={(e) => set('status', e.target.value)}>
              <MenuItem value="active">Active</MenuItem><MenuItem value="inactive">Inactive</MenuItem>
            </TextField>
          </Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>Save</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}

const DATA_TYPES: AttributeDef['data_type'][] = ['decimal', 'integer', 'string', 'bool', 'select'];

/** Editor for vessel-type specific fields (e.g. PSV tank capacities, AHTS winch pull). */
function AttributeSchemaDialog({ vesselType, onClose, onSaved }: { vesselType: VesselTypeItem | null; onClose: () => void; onSaved: () => void }) {
  const notify = useNotify();
  const [rows, setRows] = useState<(AttributeDef & { optionsText: string })[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setError(null);
    setRows((vesselType?.attribute_schema ?? []).map((a) => ({ ...a, unit: a.unit ?? '', optionsText: (a.options ?? []).join(', ') })));
  }, [vesselType]);

  const save = useMutation({
    mutationFn: () => referenceApi.saveVesselTypeAttributes(vesselType!.id, rows.map(({ optionsText, ...a }) => ({
      ...a, unit: a.unit || null, options: a.data_type === 'select' ? optionsText.split(',').map((s) => s.trim()).filter(Boolean) : null,
    }))),
    onSuccess: (r) => { notify.success(r.message); onSaved(); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const update = (i: number, patch: Partial<AttributeDef & { optionsText: string }>) => setRows((r) => r.map((x, j) => (j === i ? { ...x, ...patch } : x)));

  return (
    <Dialog open={!!vesselType} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>Fields for {vesselType?.name}</DialogTitle>
      <DialogContent dividers>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>These fields appear on the vessel form for this type. Removing a field does not delete values already stored on vessels.</Typography>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Stack spacing={1.5}>
          {rows.map((r, i) => (
            <Grid container spacing={1} key={i} alignItems="center">
              <Grid size={{ xs: 12, sm: 3 }}><TextField label="Key" value={r.key} onChange={(e) => update(i, { key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} /></Grid>
              <Grid size={{ xs: 12, sm: 3 }}><TextField label="Label" value={r.label} onChange={(e) => update(i, { label: e.target.value })} /></Grid>
              <Grid size={{ xs: 6, sm: 2 }}>
                <TextField select label="Type" value={r.data_type} onChange={(e) => update(i, { data_type: e.target.value as AttributeDef['data_type'] })}>
                  {DATA_TYPES.map((t) => <MenuItem key={t} value={t}>{humanize(t)}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={{ xs: 6, sm: 1.5 }}><TextField label="Unit" value={r.unit ?? ''} onChange={(e) => update(i, { unit: e.target.value })} /></Grid>
              <Grid size={{ xs: 10, sm: 2 }}>{r.data_type === 'select' && <TextField label="Options" value={r.optionsText} onChange={(e) => update(i, { optionsText: e.target.value })} helperText="Comma separated" />}</Grid>
              <Grid size={{ xs: 2, sm: 0.5 }}><IconButton aria-label="Remove field" color="error" onClick={() => setRows((x) => x.filter((_, j) => j !== i))}><DeleteOutline fontSize="small" /></IconButton></Grid>
            </Grid>
          ))}
        </Stack>
        <Box sx={{ mt: 2 }}><Button startIcon={<AddIcon />} onClick={() => setRows((r) => [...r, { key: '', label: '', data_type: 'decimal', unit: '', optionsText: '' }])}>Add field</Button></Box>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={rows.some((r) => !r.key || !r.label)} onClick={() => save.mutate()}>Save fields</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
