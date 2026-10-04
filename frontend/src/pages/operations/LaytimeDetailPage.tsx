import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Autocomplete, Button, Card, CardContent, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Grid, IconButton, MenuItem, Stack, Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs, TextField, Tooltip, Typography } from '@mui/material';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { laytimeApi } from '../../api/operations';
import { ApiError, errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { StatusChip } from '../../components/StatusChip';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CurrencySelect } from '../../components/MasterPickers';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { LAYTIME_EXCEPTION_TYPES, SOF_EVENT_CODES } from '../../constants/operations';
import { useNotify } from '../../hooks/useNotify';
import { formatLocal, humanize, zoneLabel } from '../../utils/format';
import { isNegative, money, trimZeros } from '../../utils/decimal';
import type { LaytimeCalculation } from '../../types/operations';

interface Terms {
  fixed_hours: string; cargo_quantity: string; rate_per_day: string; rate_unit: string; notice_time_hours: string;
  nor_tendered_at: string; nor_accepted_at: string; laytime_commenced_at: string; laytime_completed_at: string;
  demurrage_rate_per_day: string; despatch_rate_per_day: string; currency: string | null; once_on_demurrage_rule: string; remarks: string;
}

const toTerms = (c: LaytimeCalculation): Terms => ({
  fixed_hours: c.fixed_hours ?? '', cargo_quantity: c.cargo_quantity ?? '', rate_per_day: c.rate_per_day ?? '', rate_unit: c.rate_unit ?? '', notice_time_hours: c.notice_time_hours ?? '',
  nor_tendered_at: c.nor_tendered_at_local ?? '', nor_accepted_at: c.nor_accepted_at_local ?? '', laytime_commenced_at: c.laytime_commenced_at_local ?? '', laytime_completed_at: c.laytime_completed_at_local ?? '',
  demurrage_rate_per_day: c.demurrage_rate_per_day ?? '', despatch_rate_per_day: c.despatch_rate_per_day ?? '', currency: c.currency, once_on_demurrage_rule: c.once_on_demurrage_rule, remarks: c.remarks ?? '',
});
const nul = (v: string) => (v.trim() === '' ? null : v.trim());

export default function LaytimeDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('terms');
  const [terms, setTerms] = useState<Terms | null>(null);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dispute, setDispute] = useState(false);
  const [reason, setReason] = useState('');
  const calc = useQuery({ queryKey: ['laytime', id], queryFn: () => laytimeApi.get(id) });
  useEffect(() => { if (calc.data) { setTerms(toTerms(calc.data)); setDirty(false); } }, [calc.data]);
  const invalidate = () => qc.invalidateQueries({ queryKey: ['laytime'] });
  const stale = (e: unknown) => { if (e instanceof ApiError && e.code === 'stale_record') calc.refetch(); };

  const save = useMutation({
    mutationFn: () => laytimeApi.update(id, {
      lock_version: calc.data!.lock_version, fixed_hours: nul(terms!.fixed_hours), cargo_quantity: nul(terms!.cargo_quantity), rate_per_day: nul(terms!.rate_per_day), rate_unit: nul(terms!.rate_unit),
      notice_time_hours: nul(terms!.notice_time_hours), nor_tendered_at: nul(terms!.nor_tendered_at), nor_accepted_at: nul(terms!.nor_accepted_at),
      laytime_commenced_at: nul(terms!.laytime_commenced_at), laytime_completed_at: nul(terms!.laytime_completed_at), demurrage_rate_per_day: nul(terms!.demurrage_rate_per_day),
      despatch_rate_per_day: nul(terms!.despatch_rate_per_day), currency: terms!.currency, once_on_demurrage_rule: terms!.once_on_demurrage_rule, remarks: nul(terms!.remarks),
    }),
    onSuccess: (r) => { notify.success(r.message); setError(null); invalidate(); },
    onError: (e) => { stale(e); setError(errorMessage(e)); },
  });
  const run = useMutation({
    mutationFn: (a: 'calculate' | 'submit' | 'agree') => (a === 'calculate' ? laytimeApi.calculate(id) : laytimeApi.action(id, a)),
    onSuccess: (r) => { notify.success(r.message); invalidate(); },
    onError: (e) => { stale(e); notify.error(e); },
  });
  const disputeIt = useMutation({
    mutationFn: () => laytimeApi.dispute(id, reason),
    onSuccess: (r) => { notify.success(r.message); setDispute(false); setReason(''); invalidate(); },
    onError: (e) => notify.error(e),
  });

  if (calc.isLoading) return <SectionLoader />;
  if (calc.isError || !calc.data || !terms) return <ErrorState error={calc.error} onRetry={() => calc.refetch()} />;
  const c = calc.data;
  const editing = c.is_editable && can(P.LaytimeUpdate);
  const zone = zoneLabel(c.timezone);
  const set = (p: Partial<Terms>) => { setTerms((t) => t && { ...t, ...p }); setDirty(true); };
  const text = (label: string, key: keyof Terms, extra: object = {}) => (
    <TextField label={label} value={terms[key] ?? ''} disabled={!editing} onChange={(e) => set({ [key]: e.target.value } as Partial<Terms>)} {...extra} />
  );
  const when = (label: string, key: keyof Terms) => (
    <TextField type="datetime-local" label={`${label} (${zone})`} value={terms[key] ?? ''} disabled={!editing} onChange={(e) => set({ [key]: e.target.value } as Partial<Terms>)} slotProps={{ inputLabel: { shrink: true } }} />
  );
  const diffNegative = isNegative(c.difference_hours);
  const detail = c.trace?.used_hours_detail ?? [];

  return (
    <>
      <PageHeader title={`Laytime · ${c.port_call?.label ?? 'port call'}`} subtitle={[c.voyage?.voyage_number, humanize(c.calculation_type), `${zone} time`].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Operations' }, { label: 'Laytime', to: '/operations/laytime' }, { label: c.port_call?.label ?? `#${c.id}` }]}
        actions={<>
          {editing && <LoadingButton variant="outlined" loading={run.isPending} disabled={dirty} onClick={() => run.mutate('calculate')}>Calculate</LoadingButton>}
          {c.status === 'draft' && can(P.LaytimeUpdate) && <LoadingButton variant="contained" loading={run.isPending} disabled={dirty || !c.calculated_at} onClick={() => run.mutate('submit')}>Submit</LoadingButton>}
          {c.status === 'submitted' && can(P.LaytimeAgree) && <Button color="error" onClick={() => setDispute(true)}>Dispute</Button>}
          {c.status === 'submitted' && can(P.LaytimeAgree) && <LoadingButton variant="contained" color="success" loading={run.isPending} onClick={() => run.mutate('agree')}>Agree</LoadingButton>}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <StatusChip status={c.status} />
        {c.voyage && <Chip size="small" variant="outlined" label={`Voyage ${c.voyage.voyage_number}`} onClick={() => navigate(`/operations/voyages/${c.voyage!.id}`)} />}
        {c.contract && <Chip size="small" variant="outlined" label={`Contract ${c.contract.contract_number}`} onClick={() => navigate(`/contracts/${c.contract!.id}`)} />}
      </Stack>
      <Alert severity="info" sx={{ mb: 2 }}>Laytime rules (commencement after NOR, excluded days, reversible laytime, despatch basis) are still awaiting business confirmation (BR-LT-01..07). Enter what your charter party says in the terms, SOF events and exceptions; the server does the arithmetic and keeps a trace.</Alert>
      {c.status === 'agreed' && <Alert severity="success" sx={{ mb: 2 }}>Agreed. Any demurrage is booked as voyage revenue and any despatch as a voyage expense.</Alert>}

      <Card sx={{ mb: 2 }}>
        <CardContent>
          <Typography variant="subtitle2" sx={{ mb: 1 }}>Result {c.calculated_at ? `(engine v${c.calculation_version})` : ''}</Typography>
          {c.allowed_hours === null && <Typography variant="body2" color="text.secondary">Not calculated yet. Enter the terms, save, then press Calculate.</Typography>}
          {c.allowed_hours !== null && (
            <KeyValueGrid columns={4} items={[
              ['Allowed (h)', trimZeros(c.allowed_hours)], ['Used (h)', trimZeros(c.used_hours)],
              [diffNegative ? 'Over allowed (h)' : 'Saved (h)', <Typography key="d" component="span" fontSize={14} color={diffNegative ? 'error.main' : 'success.main'}>{trimZeros(c.difference_hours)}</Typography>],
              [c.demurrage_amount ? 'Demurrage' : 'Despatch', money(c.demurrage_amount ?? c.despatch_amount, c.currency ?? undefined)],
            ]} />
          )}
        </CardContent>
      </Card>

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} variant="scrollable" sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="terms" label="Terms & times" /><Tab value="sof" label={`Statement of facts (${c.sof_events?.length ?? 0})`} />
          <Tab value="exceptions" label={`Exceptions (${c.exceptions?.length ?? 0})`} /><Tab value="trace" label="Calculation trace" /><Tab value="documents" label="Documents" />
        </Tabs>
        {tab === 'terms' && (
          <CardContent>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Typography variant="subtitle2" sx={{ mb: 1 }}>Allowed time: fixed hours, or cargo quantity ÷ rate per day × 24</Typography>
            <Grid container spacing={2} sx={{ mb: 2 }}>
              <Grid size={{ xs: 12, sm: 3 }}>{text('Fixed hours', 'fixed_hours')}</Grid>
              <Grid size={{ xs: 12, sm: 3 }}>{text('Cargo quantity', 'cargo_quantity')}</Grid>
              <Grid size={{ xs: 12, sm: 3 }}>{text('Rate per day', 'rate_per_day')}</Grid>
              <Grid size={{ xs: 12, sm: 3 }}>{text('Rate unit', 'rate_unit')}</Grid>
            </Grid>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>Times (port local, {zone})</Typography>
            <Grid container spacing={2} sx={{ mb: 2 }}>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>{when('NOR tendered', 'nor_tendered_at')}</Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>{when('NOR accepted', 'nor_accepted_at')}</Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>{when('Laytime commenced', 'laytime_commenced_at')}</Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>{when('Laytime completed', 'laytime_completed_at')}</Grid>
              <Grid size={{ xs: 12, sm: 3 }}>{text('Notice time (h)', 'notice_time_hours')}</Grid>
            </Grid>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>Rates and rule</Typography>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 3 }}>{text('Demurrage per day', 'demurrage_rate_per_day')}</Grid>
              <Grid size={{ xs: 12, sm: 3 }}>{text('Despatch per day', 'despatch_rate_per_day')}</Grid>
              <Grid size={{ xs: 12, sm: 3 }}><CurrencySelect label="Currency" value={terms.currency} disabled={!editing} onChange={(v) => set({ currency: v })} /></Grid>
              <Grid size={{ xs: 12, sm: 3 }}>
                <TextField select label="Once on demurrage" value={terms.once_on_demurrage_rule} disabled={!editing} onChange={(e) => set({ once_on_demurrage_rule: e.target.value })}>
                  <MenuItem value="always_on_demurrage">Always on demurrage</MenuItem><MenuItem value="exceptions_apply">Exceptions still apply</MenuItem>
                </TextField>
              </Grid>
              <Grid size={12}>{text('Remarks', 'remarks', { multiline: true, minRows: 2 })}</Grid>
            </Grid>
            {editing && (
              <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}>
                <LoadingButton variant="contained" loading={save.isPending} disabled={!dirty} onClick={() => save.mutate()}>Save terms</LoadingButton>
              </Stack>
            )}
          </CardContent>
        )}
        {tab === 'sof' && <SofPanel calc={c} editing={editing} onChange={invalidate} zone={zone} />}
        {tab === 'exceptions' && <ExceptionsPanel calc={c} editing={editing} onChange={invalidate} zone={zone} />}
        {tab === 'trace' && (
          <CardContent>
            {detail.length === 0 ? <Typography color="text.secondary">No trace yet. Calculate first.</Typography> : (
              <>
                {c.trace?.once_on_demurrage_applied && <Alert severity="info" sx={{ mb: 2 }}>The once-on-demurrage rule changed how late exceptions were counted.</Alert>}
                <Table size="small">
                  <TableHead><TableRow><TableCell>From (UTC)</TableCell><TableCell>To (UTC)</TableCell><TableCell align="right">Clock h</TableCell><TableCell align="right">Counted %</TableCell><TableCell align="right">Counted h</TableCell><TableCell>Exceptions</TableCell></TableRow></TableHead>
                  <TableBody>
                    {detail.map((s, i) => (
                      <TableRow key={i}>
                        <TableCell>{s.from.slice(0, 16).replace('T', ' ')}</TableCell><TableCell>{s.to.slice(0, 16).replace('T', ' ')}</TableCell>
                        <TableCell align="right">{trimZeros(s.hours)}</TableCell><TableCell align="right">{trimZeros(s.pct_counted)}</TableCell><TableCell align="right">{trimZeros(s.counted_hours)}</TableCell>
                        <TableCell>{s.exception_types.length ? s.exception_types.map(humanize).join(', ') : '—'}{s.once_on_demurrage ? ' · on demurrage' : ''}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </>
            )}
          </CardContent>
        )}
        {tab === 'documents' && <DocumentsPanel parentType="laytime-calculations" parentId={id} canEdit={editing} />}
      </Card>

      <Dialog open={dispute} onClose={() => setDispute(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Dispute laytime calculation</DialogTitle>
        <DialogContent dividers><TextField label="Reason" required multiline minRows={2} value={reason} onChange={(e) => setReason(e.target.value)} /></DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDispute(false)}>Close</Button>
          <LoadingButton variant="contained" color="error" loading={disputeIt.isPending} disabled={reason.trim().length < 3} onClick={() => disputeIt.mutate()}>Dispute</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}

function SofPanel({ calc, editing, onChange, zone }: { calc: LaytimeCalculation; editing: boolean; onChange: () => void; zone: string }) {
  const notify = useNotify();
  const [f, setF] = useState({ event_at: '', event_code: '', description: '' });
  const add = useMutation({
    mutationFn: () => laytimeApi.addSofEvent(calc.id, { event_at: f.event_at, event_code: f.event_code.trim(), description: nul(f.description) }),
    onSuccess: (r) => { notify.success(r.message); setF({ event_at: '', event_code: '', description: '' }); onChange(); },
    onError: (e) => notify.error(e),
  });
  const remove = useMutation({ mutationFn: (eventId: number) => laytimeApi.removeSofEvent(calc.id, eventId), onSuccess: (r) => { notify.success(r.message); onChange(); }, onError: (e) => notify.error(e) });
  return (
    <CardContent>
      <Table size="small">
        <TableHead><TableRow><TableCell>Time ({zone})</TableCell><TableCell>Event</TableCell><TableCell>Description</TableCell>{editing && <TableCell />}</TableRow></TableHead>
        <TableBody>
          {(calc.sof_events ?? []).map((e) => (
            <TableRow key={e.id}>
              <TableCell>{formatLocal(e.event_at_local)}</TableCell><TableCell>{humanize(e.event_code)}</TableCell><TableCell>{e.description ?? '—'}</TableCell>
              {editing && <TableCell align="right"><Tooltip title="Remove"><IconButton size="small" color="error" aria-label="Remove event" onClick={() => remove.mutate(e.id)}><DeleteOutline fontSize="small" /></IconButton></Tooltip></TableCell>}
            </TableRow>
          ))}
          {(calc.sof_events ?? []).length === 0 && <TableRow><TableCell colSpan={4}><Typography color="text.secondary" align="center" sx={{ py: 2 }}>No events recorded.</Typography></TableCell></TableRow>}
        </TableBody>
      </Table>
      {editing && (
        <Grid container spacing={2} sx={{ mt: 1 }} alignItems="flex-start">
          <Grid size={{ xs: 12, md: 3 }}><TextField type="datetime-local" size="small" label={`Time (${zone})`} value={f.event_at} onChange={(e) => setF({ ...f, event_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 12, md: 3 }}><Autocomplete freeSolo size="small" options={SOF_EVENT_CODES} inputValue={f.event_code} onInputChange={(_, v) => setF((x) => ({ ...x, event_code: v }))} renderInput={(p) => <TextField {...p} label="Event code" />} /></Grid>
          <Grid size={{ xs: 12, md: 4 }}><TextField size="small" label="Description" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Grid>
          <Grid size={{ xs: 12, md: 2 }}><LoadingButton variant="outlined" fullWidth loading={add.isPending} disabled={!f.event_at || !f.event_code.trim()} onClick={() => add.mutate()}>Add event</LoadingButton></Grid>
        </Grid>
      )}
    </CardContent>
  );
}

function ExceptionsPanel({ calc, editing, onChange, zone }: { calc: LaytimeCalculation; editing: boolean; onChange: () => void; zone: string }) {
  const notify = useNotify();
  const blank = { from_at: '', to_at: '', exception_type: '', pct_counted: '0', remarks: '' };
  const [f, setF] = useState(blank);
  const [error, setError] = useState<string | null>(null);
  const add = useMutation({
    mutationFn: () => laytimeApi.addException(calc.id, { from_at: f.from_at, to_at: f.to_at, exception_type: f.exception_type.trim(), pct_counted: f.pct_counted, remarks: nul(f.remarks) }),
    onSuccess: (r) => { notify.success(r.message); setF(blank); setError(null); onChange(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const remove = useMutation({ mutationFn: (exceptionId: number) => laytimeApi.removeException(calc.id, exceptionId), onSuccess: (r) => { notify.success(r.message); onChange(); }, onError: (e) => notify.error(e) });
  const pctOk = /^\d+(\.\d{1,4})?$/.test(f.pct_counted) && Number(f.pct_counted) <= 100;
  return (
    <CardContent>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>Periods that do not count fully. 0 % = excluded, 50 % = half counted. Overlapping exceptions use the lowest percentage.</Typography>
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      <Table size="small">
        <TableHead><TableRow><TableCell>From ({zone})</TableCell><TableCell>To ({zone})</TableCell><TableCell>Type</TableCell><TableCell align="right">Counted %</TableCell><TableCell>Remarks</TableCell>{editing && <TableCell />}</TableRow></TableHead>
        <TableBody>
          {(calc.exceptions ?? []).map((e) => (
            <TableRow key={e.id}>
              <TableCell>{formatLocal(e.from_at_local)}</TableCell><TableCell>{formatLocal(e.to_at_local)}</TableCell><TableCell>{humanize(e.exception_type)}</TableCell>
              <TableCell align="right">{trimZeros(e.pct_counted)}</TableCell><TableCell>{e.remarks ?? '—'}</TableCell>
              {editing && <TableCell align="right"><Tooltip title="Remove"><IconButton size="small" color="error" aria-label="Remove exception" onClick={() => remove.mutate(e.id)}><DeleteOutline fontSize="small" /></IconButton></Tooltip></TableCell>}
            </TableRow>
          ))}
          {(calc.exceptions ?? []).length === 0 && <TableRow><TableCell colSpan={6}><Typography color="text.secondary" align="center" sx={{ py: 2 }}>No exceptions.</Typography></TableCell></TableRow>}
        </TableBody>
      </Table>
      {editing && (
        <Grid container spacing={2} sx={{ mt: 1 }} alignItems="flex-start">
          <Grid size={{ xs: 12, md: 2.5 }}><TextField type="datetime-local" size="small" label={`From (${zone})`} value={f.from_at} onChange={(e) => setF({ ...f, from_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 12, md: 2.5 }}><TextField type="datetime-local" size="small" label={`To (${zone})`} value={f.to_at} onChange={(e) => setF({ ...f, to_at: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 12, md: 2 }}><Autocomplete freeSolo size="small" options={LAYTIME_EXCEPTION_TYPES} inputValue={f.exception_type} onInputChange={(_, v) => setF((x) => ({ ...x, exception_type: v }))} renderInput={(p) => <TextField {...p} label="Type" />} /></Grid>
          <Grid size={{ xs: 6, md: 1.5 }}><TextField size="small" label="Counted %" value={f.pct_counted} error={!pctOk} onChange={(e) => setF({ ...f, pct_counted: e.target.value })} /></Grid>
          <Grid size={{ xs: 6, md: 1.5 }}><TextField size="small" label="Remarks" value={f.remarks} onChange={(e) => setF({ ...f, remarks: e.target.value })} /></Grid>
          <Grid size={{ xs: 12, md: 2 }}><LoadingButton variant="outlined" fullWidth loading={add.isPending} disabled={!f.from_at || !f.to_at || !f.exception_type.trim() || !pctOk} onClick={() => add.mutate()}>Add</LoadingButton></Grid>
        </Grid>
      )}
    </CardContent>
  );
}
