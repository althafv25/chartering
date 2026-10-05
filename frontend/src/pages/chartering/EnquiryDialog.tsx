import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, DialogActions, DialogContent, Grid, IconButton, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import ArrowUpward from '@mui/icons-material/ArrowUpward';
import { enquiriesApi } from '../../api/chartering';
import { ApiError, errorMessage } from '../../api/client';
import { LoadingButton } from '../../components/LoadingButton';
import { CompanyAutocomplete, CurrencySelect, ReferenceSelect, RoutePointAutocomplete } from '../../components/MasterPickers';
import { BUSINESS_TYPES, PORT_PURPOSES, RATE_BASES } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { humanize } from '../../utils/format';
import { isDecimal } from '../../utils/decimal';
import type { Enquiry } from '../../types/chartering';
import type { RoutePoint } from '../../types/masters';

type Row = { point: RoutePoint | null; purpose: string; notes: string };
type Form = Record<string, string | number | null>;

const FIELDS = ['business_type', 'source', 'charterer_company_id', 'broker_company_id', 'cargo_type_id', 'cargo_description', 'quantity', 'quantity_unit',
  'quantity_tolerance_pct', 'laycan_from', 'laycan_to', 'period_days', 'rate_idea', 'rate_basis', 'currency', 'commission_terms', 'terms', 'remarks'] as const;

const toForm = (e: Enquiry | null): Form => {
  const f: Form = { business_type: 'voyage_charter', source: 'direct', currency: 'USD', quantity_unit: 'mt', rate_basis: 'per_mt' };
  if (e) FIELDS.forEach((k) => { f[k] = (e as unknown as Form)[k] ?? ''; });
  return f;
};

export function EnquiryDialog({ open, enquiry, onClose, onSaved }: { open: boolean; enquiry: Enquiry | null; onClose: () => void; onSaved?: (e: Enquiry) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [f, setF] = useState<Form>(toForm(null));
  const [rows, setRows] = useState<Row[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setF(toForm(enquiry));
    setRows(enquiry?.ports?.map((p) => ({ point: p.point ?? null, purpose: p.purpose, notes: p.notes ?? '' }))
      ?? [{ point: null, purpose: 'load', notes: '' }, { point: null, purpose: 'discharge', notes: '' }]);
    setErrors({});
    setError(null);
  }, [open, enquiry]);

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = Object.fromEntries(FIELDS.map((k) => [k, f[k] === '' ? null : f[k]]));
      body.ports = rows.filter((r) => r.point).map((r) => ({
        port_id: r.point!.type === 'port' ? r.point!.id : null,
        offshore_location_id: r.point!.type === 'location' ? r.point!.id : null,
        purpose: r.purpose, notes: r.notes || null,
      }));
      return enquiry ? enquiriesApi.update(enquiry.id, { ...body, lock_version: enquiry.lock_version }) : enquiriesApi.create(body);
    },
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['enquiries'] }); onSaved?.(r.data); onClose(); },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'validation_failed') { setErrors(e.fieldErrors); setError('Please correct the highlighted fields.'); return; }
      setError(errorMessage(e));
    },
  });

  const set = (k: string, v: string | number | null) => setF((s) => ({ ...s, [k]: v }));
  const tf = (k: string, label: string, extra: Record<string, unknown> = {}) => (
    <TextField label={label} value={f[k] ?? ''} onChange={(e) => set(k, e.target.value)} error={!!errors[k]} helperText={errors[k]?.[0] ?? (extra.helperText as string)} {...extra} />
  );
  const decErr = (k: string, scale: number) => !!f[k] && !isDecimal(String(f[k]), scale);

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} maxWidth="md" fullWidth>
      <DialogTitle>{enquiry ? `Edit ${enquiry.enquiry_number}` : 'New enquiry'}</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 4 }}>
            {tf('business_type', 'Business type', { select: true, required: true, children: BUSINESS_TYPES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>) })}
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            {tf('source', 'Source', { select: true, children: ['direct', 'broker', 'tender'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>) })}
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}><CurrencySelect label="Currency" value={(f.currency as string) ?? null} onChange={(v) => set('currency', v)} error={errors.currency?.[0]} /></Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            <CompanyAutocomplete label="Charterer / customer" role={['charterer', 'customer']} value={(f.charterer_company_id as number) || null}
              initial={enquiry?.charterer ?? null} onChange={(id) => set('charterer_company_id', id)} error={errors.charterer_company_id?.[0]} />
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            <CompanyAutocomplete label="Broker" role="broker" value={(f.broker_company_id as number) || null}
              initial={enquiry?.broker ?? null} onChange={(id) => set('broker_company_id', id)} />
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}><ReferenceSelect type="cargo-types" label="Cargo / service type" value={(f.cargo_type_id as number) || null} allowEmpty onChange={(v) => set('cargo_type_id', v)} /></Grid>
          <Grid size={{ xs: 12, md: 8 }}>{tf('cargo_description', 'Cargo / service description')}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('quantity', 'Quantity', { inputMode: 'decimal', error: decErr('quantity', 3) || !!errors.quantity })}</Grid>
          <Grid size={{ xs: 6, md: 2 }}>{tf('quantity_unit', 'Unit', { select: true, children: ['mt', 'm3', 'bbl', 'units', 'days'].map((u) => <MenuItem key={u} value={u}>{u}</MenuItem>) })}</Grid>
          <Grid size={{ xs: 6, md: 2 }}>{tf('quantity_tolerance_pct', 'Tolerance %', { inputMode: 'decimal' })}</Grid>
          <Grid size={{ xs: 6, md: 2.5 }}>{tf('laycan_from', 'Laycan from', { type: 'date', slotProps: { inputLabel: { shrink: true } } })}</Grid>
          <Grid size={{ xs: 6, md: 2.5 }}>{tf('laycan_to', 'Laycan to', { type: 'date', slotProps: { inputLabel: { shrink: true } } })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('period_days', 'Period (days)', { inputMode: 'decimal', helperText: 'Time / offshore charters' })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('rate_idea', 'Rate idea', { inputMode: 'decimal', error: decErr('rate_idea', 4) || !!errors.rate_idea })}</Grid>
          <Grid size={{ xs: 6, md: 3 }}>{tf('rate_basis', 'Rate basis', { select: true, children: RATE_BASES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>) })}</Grid>
          <Grid size={{ xs: 12, md: 3 }}>{tf('commission_terms', 'Commission terms', { helperText: 'e.g. 3.75% add + 1.25% bkg' })}</Grid>
        </Grid>

        <Typography variant="subtitle2" sx={{ mt: 3, mb: 1 }}>Itinerary (ports & offshore locations)</Typography>
        <Stack spacing={1}>
          {rows.map((r, i) => (
            <Grid container spacing={1} key={i} alignItems="center">
              <Grid size={{ xs: 12, sm: 5.5 }}><RoutePointAutocomplete label={`Point ${i + 1}`} value={r.point} onChange={(p) => setRows((x) => x.map((y, j) => (j === i ? { ...y, point: p } : y)))} /></Grid>
              <Grid size={{ xs: 6, sm: 2.5 }}>
                <TextField select label="Purpose" value={r.purpose} onChange={(e) => setRows((x) => x.map((y, j) => (j === i ? { ...y, purpose: e.target.value } : y)))}>
                  {PORT_PURPOSES.map((p) => <MenuItem key={p} value={p}>{humanize(p)}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={{ xs: 6, sm: 3 }}><TextField label="Notes" value={r.notes} onChange={(e) => setRows((x) => x.map((y, j) => (j === i ? { ...y, notes: e.target.value } : y)))} /></Grid>
              <Grid size={{ xs: 12, sm: 1 }}>
                <Stack direction="row">
                  <IconButton size="small" aria-label="Move up" disabled={i === 0} onClick={() => setRows((x) => { const n = [...x]; [n[i - 1], n[i]] = [n[i], n[i - 1]]; return n; })}><ArrowUpward fontSize="small" /></IconButton>
                  <IconButton size="small" color="error" aria-label="Remove point" onClick={() => setRows((x) => x.filter((_, j) => j !== i))}><DeleteOutline fontSize="small" /></IconButton>
                </Stack>
              </Grid>
            </Grid>
          ))}
        </Stack>
        <Box><Button startIcon={<AddIcon />} sx={{ mt: 1 }} onClick={() => setRows((x) => [...x, { point: null, purpose: 'other', notes: '' }])}>Add point</Button></Box>

        <Grid container spacing={2} sx={{ mt: 1 }}>
          <Grid size={12}>{tf('terms', 'Terms', { multiline: true, minRows: 2 })}</Grid>
          <Grid size={12}>{tf('remarks', 'Remarks', { multiline: true, minRows: 2 })}</Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>{enquiry ? 'Save changes' : 'Create enquiry'}</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
