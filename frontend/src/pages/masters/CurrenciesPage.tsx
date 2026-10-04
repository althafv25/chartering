import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, CardHeader, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Grid, IconButton, MenuItem, Stack, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { currenciesApi, fxApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { StatusChip } from '../../components/StatusChip';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { LoadingButton } from '../../components/LoadingButton';
import { CurrencySelect } from '../../components/MasterPickers';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { decimalPattern } from '../../constants/masters';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, humanize } from '../../utils/format';
import type { Currency, ExchangeRate, FxResolution } from '../../types/masters';

const today = () => new Date().toISOString().slice(0, 10);

export default function CurrenciesPage() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [ccyFilter, setCcyFilter] = useState<string | null>(null);
  const [rateDialog, setRateDialog] = useState<{ open: boolean; rate: ExchangeRate | null }>({ open: false, rate: null });
  const [ccyDialog, setCcyDialog] = useState(false);
  const [deleting, setDeleting] = useState<ExchangeRate | null>(null);

  const currencies = useQuery({ queryKey: ['currencies', 'all'], queryFn: () => currenciesApi.list() });
  const q = { page, per_page: 20, currency: ccyFilter ?? undefined };
  const rates = useQuery({ queryKey: ['exchange-rates', q], queryFn: () => fxApi.list(q), placeholderData: keepPreviousData, enabled: can(P.ExchangeRatesView) });

  const toggle = useMutation({
    mutationFn: (c: Currency) => currenciesApi.update(c.id, { status: c.status === 'active' ? 'inactive' : 'active' }),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['currencies'] }); },
    onError: (e) => notify.error(e),
  });
  const remove = useMutation({
    mutationFn: (r: ExchangeRate) => fxApi.remove(r.id),
    onSuccess: (r) => { notify.success(r.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['exchange-rates'] }); },
    onError: (e) => notify.error(e),
  });

  const ccyCols: Column<Currency>[] = [
    { key: 'code', header: 'Code', render: (c) => <Stack direction="row" spacing={1} alignItems="center"><Typography fontWeight={700}>{c.code}</Typography>{c.is_base && <Chip size="small" color="primary" label="Base" />}</Stack> },
    { key: 'name', header: 'Name', render: (c) => c.name },
    { key: 'dec', header: 'Decimals', align: 'right', render: (c) => c.decimals },
    { key: 'status', header: 'Status', render: (c) => <StatusChip status={c.status} /> },
    { key: 'actions', header: '', align: 'right', render: (c) => can(P.CurrenciesUpdate) && !c.is_base && (
      <Button size="small" onClick={() => toggle.mutate(c)}>{c.status === 'active' ? 'Deactivate' : 'Activate'}</Button>
    ) },
  ];

  const rateCols: Column<ExchangeRate>[] = [
    { key: 'date', header: 'Date', render: (r) => formatDate(r.rate_date) },
    { key: 'pair', header: 'Pair', render: (r) => <Typography fontWeight={600} fontSize={14}>1 {r.base_currency} = {r.rate} {r.quote_currency}</Typography> },
    { key: 'source', header: 'Source', hideBelow: 'md', render: (r) => humanize(r.source) },
    { key: 'by', header: 'Entered by', hideBelow: 'lg', render: (r) => r.created_by ?? '—' },
    { key: 'remarks', header: 'Remarks', hideBelow: 'lg', render: (r) => r.remarks ?? '—' },
    { key: 'actions', header: '', align: 'right', render: (r) => can(P.ExchangeRatesManage) && (
      <Stack direction="row" justifyContent="flex-end">
        <Tooltip title="Edit"><IconButton size="small" aria-label="Edit rate" onClick={() => setRateDialog({ open: true, rate: r })}><EditOutlined fontSize="small" /></IconButton></Tooltip>
        <Tooltip title="Delete"><IconButton size="small" color="error" aria-label="Delete rate" onClick={() => setDeleting(r)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>
      </Stack>
    ) },
  ];

  return (
    <>
      <PageHeader title="Currencies & exchange rates" subtitle="Rates are snapshotted on every transaction — editing a rate never changes historical records."
        breadcrumbs={[{ label: 'Masters' }, { label: 'Currencies & FX' }]} />
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 5 }}>
          <Stack spacing={2}>
            <Card>
              <CardHeader title="Currencies" slotProps={{ title: { variant: 'h6' } }}
                action={can(P.CurrenciesUpdate) && <Button size="small" startIcon={<AddIcon />} onClick={() => setCcyDialog(true)}>Add</Button>} />
              <DataTable columns={ccyCols} rows={currencies.data ?? []} rowKey={(c) => c.id} loading={currencies.isLoading} />
            </Card>
            {can(P.ExchangeRatesView) && <Converter />}
          </Stack>
        </Grid>
        {can(P.ExchangeRatesView) && (
          <Grid size={{ xs: 12, lg: 7 }}>
            <Card>
              <CardHeader title="Exchange rates" slotProps={{ title: { variant: 'h6' } }}
                action={<Stack direction="row" spacing={1} sx={{ mt: 0.5 }}>
                  <Box sx={{ width: 170 }}><CurrencySelect label="Filter currency" value={ccyFilter} onChange={(v) => { setCcyFilter(v); setPage(1); }} /></Box>
                  {can(P.ExchangeRatesManage) && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setRateDialog({ open: true, rate: null })}>Add rate</Button>}
                </Stack>} />
              <DataTable columns={rateCols} rows={rates.data?.data ?? []} rowKey={(r) => r.id} loading={rates.isFetching} meta={rates.data?.meta} onPageChange={setPage}
                emptyTitle="No exchange rates" emptyDescription="Add daily rates (e.g. 1 USD = 3.6725 AED). Inverse and cross rates are derived automatically." />
            </Card>
          </Grid>
        )}
      </Grid>
      <RateDialog open={rateDialog.open} rate={rateDialog.rate} onClose={() => setRateDialog({ open: false, rate: null })} />
      <CurrencyDialog open={ccyDialog} onClose={() => setCcyDialog(false)} />
      <ConfirmDialog open={!!deleting} title="Delete exchange rate" message={`Delete ${deleting?.base_currency}/${deleting?.quote_currency} for ${formatDate(deleting?.rate_date)}?`}
        confirmLabel="Delete" danger loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting)} onClose={() => setDeleting(null)} />
    </>
  );
}

function Converter() {
  const [f, setF] = useState({ from: 'AED' as string | null, to: 'USD' as string | null, date: today(), amount: '1000' });
  const [res, setRes] = useState<FxResolution | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const run = useMutation({
    mutationFn: () => fxApi.convert({ from: f.from!, to: f.to!, date: f.date, amount: f.amount || undefined }),
    onSuccess: (r) => { setRes(r); setErr(null); },
    onError: (e) => { setRes(null); setErr(errorMessage(e)); },
  });

  return (
    <Card>
      <CardHeader title="Rate lookup" subheader="Uses the latest rate on or before the date" slotProps={{ title: { variant: 'h6' } }} />
      <CardContent sx={{ pt: 0 }}>
        <Grid container spacing={1.5}>
          <Grid size={6}><CurrencySelect label="From" required value={f.from} onChange={(v) => setF((x) => ({ ...x, from: v }))} /></Grid>
          <Grid size={6}><CurrencySelect label="To" required value={f.to} onChange={(v) => setF((x) => ({ ...x, to: v }))} /></Grid>
          <Grid size={6}><TextField type="date" label="Date" value={f.date} onChange={(e) => setF((x) => ({ ...x, date: e.target.value }))} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={6}><TextField label="Amount" inputMode="decimal" value={f.amount} onChange={(e) => setF((x) => ({ ...x, amount: e.target.value }))} /></Grid>
          <Grid size={12}><LoadingButton fullWidth variant="outlined" loading={run.isPending} disabled={!f.from || !f.to} onClick={() => run.mutate()}>Look up</LoadingButton></Grid>
        </Grid>
        {err && <Alert severity="warning" sx={{ mt: 2 }}>{err}</Alert>}
        {res && (
          <Box sx={{ mt: 2 }}>
            {res.converted_amount && <Typography variant="h5">{res.converted_amount} {f.to}</Typography>}
            <Typography variant="body2" color="text.secondary">Rate {res.rate} · {humanize(res.method)}{res.rate_date ? ` · dated ${formatDate(res.rate_date)}` : ''}</Typography>
          </Box>
        )}
      </CardContent>
    </Card>
  );
}

function RateDialog({ open, rate, onClose }: { open: boolean; rate: ExchangeRate | null; onClose: () => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [f, setF] = useState({ rate_date: today(), base_currency: 'USD' as string | null, quote_currency: null as string | null, rate: '', remarks: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [lastOpen, setLastOpen] = useState(false);
  if (open !== lastOpen) {
    setLastOpen(open);
    if (open) {
      setErrors({});
      setF(rate ? { rate_date: rate.rate_date, base_currency: rate.base_currency, quote_currency: rate.quote_currency, rate: rate.rate, remarks: rate.remarks ?? '' }
        : { rate_date: today(), base_currency: 'USD', quote_currency: null, rate: '', remarks: '' });
    }
  }

  const save = useMutation({
    mutationFn: () => (rate ? fxApi.update(rate.id, { rate: f.rate, remarks: f.remarks || null }) : fxApi.create({ ...f, remarks: f.remarks || null })),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['exchange-rates'] }); onClose(); },
    onError: (e: unknown) => { const fe = (e as { fieldErrors?: Record<string, string[]> }).fieldErrors ?? {}; setErrors(fe); if (!Object.keys(fe).length) notify.error(e); },
  });
  const valid = decimalPattern(8).test(f.rate) && Number(f.rate) > 0;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>{rate ? 'Edit exchange rate' : 'Add exchange rate'}</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <TextField type="date" label="Rate date" disabled={!!rate} value={f.rate_date} onChange={(e) => setF((x) => ({ ...x, rate_date: e.target.value }))}
            slotProps={{ inputLabel: { shrink: true } }} error={!!errors.rate_date} helperText={errors.rate_date?.[0]} />
          <Stack direction="row" spacing={1}>
            <CurrencySelect label="Base (1 unit)" required disabled={!!rate} value={f.base_currency} onChange={(v) => setF((x) => ({ ...x, base_currency: v }))} error={errors.base_currency?.[0]} />
            <CurrencySelect label="Quote" required disabled={!!rate} value={f.quote_currency} onChange={(v) => setF((x) => ({ ...x, quote_currency: v }))} error={errors.quote_currency?.[0]} />
          </Stack>
          <TextField label="Rate" required inputMode="decimal" value={f.rate} onChange={(e) => setF((x) => ({ ...x, rate: e.target.value }))}
            error={(!!f.rate && !valid) || !!errors.rate} helperText={errors.rate?.[0] ?? `1 ${f.base_currency ?? '…'} = rate × ${f.quote_currency ?? '…'} (up to 8 decimals)`} />
          <TextField label="Remarks / source" value={f.remarks} onChange={(e) => setF((x) => ({ ...x, remarks: e.target.value }))} />
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!valid || !f.quote_currency} onClick={() => save.mutate()}>Save</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}

function CurrencyDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [f, setF] = useState({ code: '', name: '', symbol: '', decimals: 2 });
  const save = useMutation({
    mutationFn: () => currenciesApi.create(f),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['currencies'] }); setF({ code: '', name: '', symbol: '', decimals: 2 }); onClose(); },
    onError: (e) => notify.error(e),
  });
  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>Add currency</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <TextField label="ISO code" required value={f.code} onChange={(e) => setF((x) => ({ ...x, code: e.target.value.toUpperCase().slice(0, 3) }))} />
          <TextField label="Name" required value={f.name} onChange={(e) => setF((x) => ({ ...x, name: e.target.value }))} />
          <TextField label="Symbol" value={f.symbol} onChange={(e) => setF((x) => ({ ...x, symbol: e.target.value }))} />
          <TextField select label="Decimals" value={f.decimals} onChange={(e) => setF((x) => ({ ...x, decimals: Number(e.target.value) }))}>
            {[0, 1, 2, 3, 4].map((d) => <MenuItem key={d} value={d}>{d}</MenuItem>)}
          </TextField>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={f.code.length !== 3 || !f.name} onClick={() => save.mutate()}>Add</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
