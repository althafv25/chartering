import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { bunkersApi } from '../../api/bunkers';
import { voyagesApi } from '../../api/operations';
import { vesselsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { DataTable, type Column } from '../../components/DataTable';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { CompanyAutocomplete, CurrencySelect, ReferenceSelect } from '../../components/MasterPickers';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatLocal } from '../../utils/format';
import { groupDigits, isDecimal, money } from '../../utils/decimal';
import type { BunkerStem } from '../../types/bunkers';

interface Props {
  filters: Record<string, string | number | undefined>;
  preset?: { vessel_id: number; voyage_id: number };
  readOnly?: boolean;
}

type OrderForm = { vessel_id: string; voyage_id: string; port_call_id: string; supplier_company_id: number | null; fuel_type_id: number | null; ordered_on: string;
  ordered_mt: string; price_per_mt: string; currency: string | null; remarks: string };

export function BunkerStemsTable({ filters, preset, readOnly }: Props) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [order, setOrder] = useState<OrderForm | null>(null);
  const [deliver, setDeliver] = useState<{ s: BunkerStem; at: string; mt: string; bdn: string } | null>(null);
  const [cancel, setCancel] = useState<BunkerStem | null>(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const q = { page, per_page: 15, ...filters };
  const list = useQuery({ queryKey: ['bunker-stems', q], queryFn: () => bunkersApi.list(q), placeholderData: keepPreviousData });
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }), enabled: !!order && !preset });
  const vid = order?.vessel_id;
  const voyages = useQuery({ queryKey: ['voyages', { vessel: vid, open: 1 }], queryFn: () => voyagesApi.list({ vessel_id: vid, open: 1, per_page: 50 }), enabled: !!vid && !preset });
  const voyId = Number(order?.voyage_id) || null;
  const voyage = useQuery({ queryKey: ['voyages', voyId], queryFn: () => voyagesApi.get(voyId!), enabled: !!voyId });
  const refresh = () => { qc.invalidateQueries({ queryKey: ['bunker-stems'] }); qc.invalidateQueries({ queryKey: ['rob-ledger'] }); };

  const save = useMutation({
    mutationFn: () => bunkersApi.save(null, { ...order!, vessel_id: Number(order!.vessel_id), voyage_id: voyId, port_call_id: Number(order!.port_call_id) || null, remarks: order!.remarks || null }),
    onSuccess: (r) => { notify.success(r.message); setOrder(null); refresh(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const doDeliver = useMutation({
    mutationFn: () => bunkersApi.deliver(deliver!.s.id, { lock_version: deliver!.s.lock_version, delivered_at: deliver!.at, delivered_mt: deliver!.mt, bdn_number: deliver!.bdn }),
    onSuccess: (r) => { notify.success(r.message); setDeliver(null); refresh(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const doCancel = useMutation({
    mutationFn: () => bunkersApi.cancel(cancel!.id, reason),
    onSuccess: (r) => { notify.success(r.message); setCancel(null); setReason(''); refresh(); },
    onError: (e) => notify.error(e),
  });

  const editable = !readOnly && can(P.BunkersManage);
  const columns: Column<BunkerStem>[] = [
    { key: 'no', header: 'Stem', render: (s) => <Box><Typography fontSize={14} fontWeight={600}>{s.stem_number}</Typography><Typography variant="caption" color="text.secondary">{s.fuel_type?.code} · ordered {formatDate(s.ordered_on)}</Typography></Box> },
    { key: 'where', header: 'Vessel / port', hideBelow: 'md', render: (s) => <Box><Typography fontSize={14}>{s.vessel?.name}</Typography><Typography variant="caption" color="text.secondary">{s.port_call?.label ?? s.port?.name ?? '—'}</Typography></Box> },
    { key: 'supplier', header: 'Supplier', hideBelow: 'lg', render: (s) => s.supplier?.legal_name ?? '—' },
    { key: 'qty', header: 'Quantity (mt)', align: 'right', render: (s) => (s.delivered_mt ? `${groupDigits(s.delivered_mt)} delivered` : `${groupDigits(s.ordered_mt)} ordered`) },
    { key: 'price', header: 'Price / mt', align: 'right', hideBelow: 'md', render: (s) => `${groupDigits(s.price_per_mt)} ${s.currency}` },
    { key: 'total', header: 'Amount', align: 'right', render: (s) => <Box>{money(s.total_amount, s.currency)}{s.base_amount && s.currency !== s.base_currency && <Typography variant="caption" display="block" color="text.secondary">{money(s.base_amount, s.base_currency)}</Typography>}</Box> },
    { key: 'delivered', header: 'Delivered', hideBelow: 'lg', render: (s) => (s.delivered_at_local ? `${formatLocal(s.delivered_at_local)} · BDN ${s.bdn_number}` : '—') },
    { key: 'status', header: 'Status', render: (s) => <StatusChip status={s.status} /> },
    { key: 'actions', header: '', align: 'right', render: (s) => editable && s.status === 'ordered' && (
      <Stack direction="row" spacing={0.5} justifyContent="flex-end">
        <Button size="small" onClick={() => { setError(null); setDeliver({ s, at: '', mt: s.ordered_mt, bdn: s.bdn_number ?? '' }); }}>Deliver</Button>
        <Button size="small" color="error" onClick={() => setCancel(s)}>Cancel</Button>
      </Stack>
    ) },
  ];
  const valid = order && order.vessel_id && order.fuel_type_id && order.ordered_on && isDecimal(order.ordered_mt, 3) && isDecimal(order.price_per_mt, 4) && order.currency;

  return (
    <>
      {editable && (
        <Stack direction="row" justifyContent="flex-end" sx={{ px: 2, pt: 2 }}>
          <Button variant="outlined" onClick={() => { setError(null); setOrder({ vessel_id: String(preset?.vessel_id ?? ''), voyage_id: String(preset?.voyage_id ?? ''), port_call_id: '',
            supplier_company_id: null, fuel_type_id: null, ordered_on: new Date().toISOString().slice(0, 10), ordered_mt: '', price_per_mt: '', currency: 'USD', remarks: '' }); }}>Order bunkers</Button>
        </Stack>
      )}
      {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
        <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(s) => s.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage} emptyTitle="No bunker stems" />
      )}

      <Dialog open={!!order} onClose={() => setOrder(null)} maxWidth="sm" fullWidth>
        <DialogTitle>Order bunkers</DialogTitle>
        {order && (
          <DialogContent dividers>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Grid container spacing={2}>
              {!preset && (
                <>
                  <Grid size={6}>
                    <TextField select label="Vessel" required value={order.vessel_id} onChange={(e) => setOrder({ ...order, vessel_id: e.target.value, voyage_id: '', port_call_id: '' })}>
                      {(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name}</MenuItem>)}
                    </TextField>
                  </Grid>
                  <Grid size={6}>
                    <TextField select label="Voyage (optional)" value={order.voyage_id} disabled={!order.vessel_id} onChange={(e) => setOrder({ ...order, voyage_id: e.target.value, port_call_id: '' })}>
                      <MenuItem value="">—</MenuItem>{(voyages.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.voyage_number}</MenuItem>)}
                    </TextField>
                  </Grid>
                </>
              )}
              <Grid size={6}>
                <TextField select label="Port call" value={order.port_call_id} disabled={!voyage.data} onChange={(e) => setOrder({ ...order, port_call_id: e.target.value })}>
                  <MenuItem value="">—</MenuItem>{voyage.data?.port_calls?.filter((c) => c.status !== 'cancelled').map((c) => <MenuItem key={c.id} value={String(c.id)}>{c.sequence}. {c.label}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={6}><ReferenceSelect type="fuel-types" label="Fuel" required value={order.fuel_type_id} onChange={(v) => setOrder({ ...order, fuel_type_id: v })} /></Grid>
              <Grid size={12}><CompanyAutocomplete label="Supplier" role="supplier" value={order.supplier_company_id} onChange={(id) => setOrder({ ...order, supplier_company_id: id })} /></Grid>
              <Grid size={4}><TextField type="date" label="Ordered on" required value={order.ordered_on} onChange={(e) => setOrder({ ...order, ordered_on: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
              <Grid size={4}><TextField label="Quantity (mt)" required value={order.ordered_mt} error={!!order.ordered_mt && !isDecimal(order.ordered_mt, 3)} onChange={(e) => setOrder({ ...order, ordered_mt: e.target.value.trim() })} /></Grid>
              <Grid size={4}><CurrencySelect label="Currency" required value={order.currency} onChange={(c) => setOrder({ ...order, currency: c })} /></Grid>
              <Grid size={6}><TextField label="Price per mt" required value={order.price_per_mt} error={!!order.price_per_mt && !isDecimal(order.price_per_mt, 4)} onChange={(e) => setOrder({ ...order, price_per_mt: e.target.value.trim() })} /></Grid>
              <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={order.remarks} onChange={(e) => setOrder({ ...order, remarks: e.target.value })} /></Grid>
            </Grid>
          </DialogContent>
        )}
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setOrder(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>Order</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!deliver} onClose={() => setDeliver(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Record delivery · {deliver?.s.stem_number}</DialogTitle>
        {deliver && (
          <DialogContent dividers>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Stack spacing={2}>
              <TextField type="datetime-local" label="Delivered (port local time)" required value={deliver.at} onChange={(e) => setDeliver({ ...deliver, at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
              <TextField label="Delivered quantity (mt)" required value={deliver.mt} error={!isDecimal(deliver.mt, 3)} onChange={(e) => setDeliver({ ...deliver, mt: e.target.value.trim() })} />
              <TextField label="BDN number" required value={deliver.bdn} onChange={(e) => setDeliver({ ...deliver, bdn: e.target.value })} />
              <Typography variant="caption" color="text.secondary">The amount (delivered × price) and the exchange rate to the base currency at delivery are calculated by the server.</Typography>
            </Stack>
          </DialogContent>
        )}
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDeliver(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={doDeliver.isPending} disabled={!deliver?.at || !deliver.bdn || !isDecimal(deliver.mt, 3)} onClick={() => doDeliver.mutate()}>Save delivery</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!cancel} onClose={() => setCancel(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Cancel {cancel?.stem_number}</DialogTitle>
        <DialogContent dividers><TextField label="Reason" required value={reason} onChange={(e) => setReason(e.target.value)} /></DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setCancel(null)}>Close</Button>
          <LoadingButton variant="contained" color="error" loading={doCancel.isPending} disabled={reason.trim().length < 3} onClick={() => doCancel.mutate()}>Cancel stem</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
