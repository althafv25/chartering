import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert, Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, Grid, MenuItem, Stack, Tab, Table, TableBody,
  TableCell, TableHead, TableRow, Tabs, TextField, Tooltip, Typography,
} from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import LockOutlined from '@mui/icons-material/LockOutlined';
import CallMadeIcon from '@mui/icons-material/CallMade';
import CallReceivedIcon from '@mui/icons-material/CallReceived';
import CompareArrowsIcon from '@mui/icons-material/CompareArrows';
import { offersApi } from '../../api/chartering';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { ActivityPanel } from '../../components/ActivityPanel';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { PdfDocumentButton } from '../../components/PdfDocumentButton';
import { CurrencySelect } from '../../components/MasterPickers';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { RATE_BASES, labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatDateTime, humanize } from '../../utils/format';
import { groupDigits, isDecimal, trimZeros } from '../../utils/decimal';
import type { Offer, OfferRevision } from '../../types/chartering';

type Terms = { direction: 'outbound' | 'inbound'; rate: string; rate_basis: string; currency: string; quantity: string; laycan_from: string; laycan_to: string;
  period_days: string; address_pct: string; brokerage_pct: string; other_pct: string; terms: string; valid_until: string; remarks: string };

const fromRevision = (r?: OfferRevision | null, direction: 'outbound' | 'inbound' = 'outbound'): Terms => ({
  direction, rate: r?.rate ?? '', rate_basis: r?.rate_basis ?? 'per_mt', currency: r?.currency ?? 'USD', quantity: r?.quantity ?? '',
  laycan_from: r?.laycan_from ?? '', laycan_to: r?.laycan_to ?? '', period_days: r?.period_days ?? '',
  address_pct: r?.commissions.address_pct ?? '0', brokerage_pct: r?.commissions.brokerage_pct ?? '0', other_pct: r?.commissions.other_pct ?? '0',
  terms: r?.terms ?? '', valid_until: r?.valid_until ?? '', remarks: r?.remarks ?? '',
});

const toBody = (t: Terms, broker: number | null) => ({
  direction: t.direction, rate: t.rate, rate_basis: t.rate_basis, currency: t.currency, quantity: t.quantity || null,
  laycan_from: t.laycan_from || null, laycan_to: t.laycan_to || null, period_days: t.period_days || null,
  commissions: { address_pct: t.address_pct || '0', brokerage_pct: t.brokerage_pct || '0', other_pct: t.other_pct || '0', broker_company_id: broker },
  terms: t.terms || null, valid_until: t.valid_until || null, remarks: t.remarks || null,
});

export default function OfferDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('revisions');
  const [editor, setEditor] = useState<{ mode: 'new' | 'edit'; rev?: OfferRevision; terms: Terms } | null>(null);
  const [decision, setDecision] = useState<{ kind: 'accept' | 'reject' | 'withdraw'; rev?: OfferRevision } | null>(null);
  const [reason, setReason] = useState('');
  const [cmp, setCmp] = useState<[number, number] | null>(null);

  const offer = useQuery({ queryKey: ['offers', id], queryFn: () => offersApi.get(id) });
  const diff = useQuery({ queryKey: ['offers', id, 'diff', cmp], queryFn: () => offersApi.diff(id, cmp![0], cmp![1]), enabled: !!cmp });
  const invalidate = () => { qc.invalidateQueries({ queryKey: ['offers'] }); qc.invalidateQueries({ queryKey: ['enquiries'] }); };

  const saveRev = useMutation({
    mutationFn: () => {
      const body = toBody(editor!.terms, offer.data?.latest_revision?.commissions.broker_company_id ?? null);
      return editor!.mode === 'new' ? offersApi.addRevision(id, body) : offersApi.updateRevision(id, editor!.rev!.id, body);
    },
    onSuccess: (r) => { notify.success(r.message); setEditor(null); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const act = useMutation({
    mutationFn: async ({ kind, rev }: { kind: string; rev?: OfferRevision }): Promise<{ message: string }> => {
      switch (kind) {
        case 'send': return offersApi.send(id, rev!.id);
        case 'receive': return offersApi.receive(id, rev!.id);
        case 'accept': return offersApi.accept(id, rev!.id, reason || undefined);
        case 'reject': return offersApi.reject(id, rev!.id, reason);
        case 'withdraw': return offersApi.withdraw(id, reason);
        default: throw new Error(kind);
      }
    },
    onSuccess: (r) => { notify.success(r.message); setDecision(null); setReason(''); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const fixture = useMutation({
    mutationFn: (rev: OfferRevision) => offersApi.toFixture(id, rev.id),
    onSuccess: (r) => { notify.success(r.message); invalidate(); navigate(`/chartering/fixtures/${r.data.id}`); },
    onError: (e) => notify.error(e),
  });

  if (offer.isLoading) return <SectionLoader />;
  if (offer.isError || !offer.data) return <ErrorState error={offer.error} onRetry={() => offer.refetch()} />;
  const o: Offer = offer.data;
  const revisions = [...(o.revisions ?? [])].sort((a, b) => b.revision_no - a.revision_no);
  const hasDraft = revisions.some((r) => r.status === 'draft');
  const open = o.status === 'open';
  const latest = revisions[0];

  return (
    <>
      <PageHeader
        title={o.offer_number}
        subtitle={[o.vessel?.name, o.enquiry?.enquiry_number, o.enquiry?.charterer ?? o.counterparty?.legal_name].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Chartering' }, { label: 'Offers', to: '/chartering/offers' }, { label: o.offer_number }]}
        actions={<>
          {open && can(P.OffersUpdate) && <Button color="error" onClick={() => setDecision({ kind: 'withdraw' })}>Withdraw offer</Button>}
          {open && can(P.OffersUpdate) && !hasDraft && <Button variant="outlined" startIcon={<CallReceivedIcon />} onClick={() => setEditor({ mode: 'new', terms: fromRevision(latest, 'inbound') })}>Record counter</Button>}
          {open && can(P.OffersUpdate) && !hasDraft && <Button variant="contained" startIcon={<CallMadeIcon />} onClick={() => setEditor({ mode: 'new', terms: fromRevision(latest, 'outbound') })}>New revision</Button>}
        </>}
      />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} alignItems="center">
        <StatusChip status={o.status} />
        {o.enquiry && <Chip size="small" variant="outlined" label={`Enquiry ${o.enquiry.enquiry_number} (${o.enquiry.status})`} onClick={() => navigate(`/chartering/enquiries/${o.enquiry!.id}`)} />}
      </Stack>
      {!open && <Alert severity={o.status === 'accepted' ? 'success' : 'info'} sx={{ mb: 2 }}>This offer is {o.status}. Its revision history is read-only.</Alert>}

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="revisions" label={`Revisions (${revisions.length})`} />
          <Tab value="documents" label="Documents" />
          <Tab value="activity" label="Activity" />
        </Tabs>
        {tab === 'revisions' && (
          <CardContent>
            <Stack spacing={2}>
              {revisions.map((r, idx) => (
                <Card key={r.id} variant="outlined" sx={{ borderColor: r.status === 'accepted' ? 'success.main' : r.status === 'draft' ? 'warning.main' : undefined, opacity: r.status === 'superseded' ? 0.75 : 1 }}>
                  <CardContent>
                    <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={1}>
                      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                        <Typography variant="h6">Revision {r.revision_no}</Typography>
                        <Chip size="small" icon={r.direction === 'outbound' ? <CallMadeIcon /> : <CallReceivedIcon />} label={r.direction === 'outbound' ? 'Our offer' : 'Counter (received)'} />
                        <StatusChip status={r.status} />
                        {r.is_immutable && <Tooltip title="Sent/received revisions cannot be changed. Create a new revision instead."><Chip size="small" icon={<LockOutlined />} label="Immutable" /></Tooltip>}
                        {r.fixture && <Chip size="small" color="success" label={`Fixture ${r.fixture.fixture_number}`} onClick={() => navigate(`/chartering/fixtures/${r.fixture!.id}`)} />}
                      </Stack>
                      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                        <PdfDocumentButton size="small" parentType="offers" parentId={id} permission={P.OffersUpdate} generate={() => offersApi.generatePdf(id, r.id)} />
                        {idx < revisions.length - 1 && <Button size="small" startIcon={<CompareArrowsIcon />} onClick={() => setCmp([revisions[idx + 1].id, r.id])}>Compare with rev {revisions[idx + 1].revision_no}</Button>}
                        {r.status === 'draft' && can(P.OffersUpdate) && <Button size="small" onClick={() => setEditor({ mode: 'edit', rev: r, terms: fromRevision(r, r.direction) })}>Edit draft</Button>}
                        {r.status === 'draft' && r.direction === 'outbound' && can(P.OffersSend) && <Button size="small" variant="contained" onClick={() => act.mutate({ kind: 'send', rev: r })}>Mark sent</Button>}
                        {r.status === 'draft' && r.direction === 'inbound' && can(P.OffersUpdate) && <Button size="small" variant="contained" onClick={() => act.mutate({ kind: 'receive', rev: r })}>Record received</Button>}
                        {r.is_open && open && can(P.OffersReject) && <Button size="small" color="error" onClick={() => setDecision({ kind: 'reject', rev: r })}>Reject</Button>}
                        {r.is_open && open && can(P.OffersAccept) && <Button size="small" variant="contained" color="success" onClick={() => setDecision({ kind: 'accept', rev: r })}>Accept</Button>}
                        {r.status === 'accepted' && !r.fixture && can(P.FixturesCreate) && <LoadingButton size="small" variant="contained" loading={fixture.isPending} onClick={() => fixture.mutate(r)}>Create fixture</LoadingButton>}
                      </Stack>
                    </Stack>
                    <Grid container spacing={2} sx={{ mt: 1 }}>
                      {[
                        ['Rate', `${groupDigits(r.rate)} ${r.currency} ${labelOf(RATE_BASES, r.rate_basis)}`],
                        ['Quantity', r.quantity ? `${groupDigits(r.quantity)} ${r.quantity_unit ?? ''}` : '—'],
                        ['Laycan', r.laycan_from ? `${formatDate(r.laycan_from)} – ${formatDate(r.laycan_to)}` : '—'],
                        ['Period', r.period_days ? `${trimZeros(r.period_days)} days` : '—'],
                        ['Commissions', `add ${trimZeros(r.commissions.address_pct)}% · bkg ${trimZeros(r.commissions.brokerage_pct)}% · other ${trimZeros(r.commissions.other_pct)}%`],
                        ['Pricing scenario', r.scenario ? `${r.scenario.estimation_number} / ${r.scenario.code} (${r.scenario.estimation_status})` : '—'],
                        ['Ports', r.ports.map((p) => p.label).join(' → ') || '—'],
                        ['Valid until', formatDate(r.valid_until)],
                      ].map(([k, v]) => (
                        <Grid key={k} size={{ xs: 12, sm: 6, md: 3 }}>
                          <Typography variant="caption" color="text.secondary" fontWeight={600}>{k}</Typography>
                          <Typography fontSize={14} sx={{ cursor: k === 'Pricing scenario' && r.scenario ? 'pointer' : undefined }}
                            onClick={() => k === 'Pricing scenario' && r.scenario && navigate(`/chartering/estimations/${r.scenario.estimation_id}?scenario=${r.scenario.id}`)}>{v}</Typography>
                        </Grid>
                      ))}
                    </Grid>
                    {r.terms && <Typography fontSize={14} whiteSpace="pre-wrap" sx={{ mt: 1.5 }}>{r.terms}</Typography>}
                    <Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 1 }}>
                      Created {formatDateTime(r.created_at)}{r.created_by ? ` by ${r.created_by}` : ''}
                      {r.sent_at && ` · sent ${formatDateTime(r.sent_at)}`}{r.received_at && ` · received ${formatDateTime(r.received_at)}`}
                      {r.decided_at && ` · ${r.status} ${formatDateTime(r.decided_at)}`}{r.decision_reason && ` — ${r.decision_reason}`}
                    </Typography>
                  </CardContent>
                </Card>
              ))}
            </Stack>
          </CardContent>
        )}
        {tab === 'documents' && <DocumentsPanel parentType="offers" parentId={id} canEdit={can(P.OffersUpdate)} />}
        {tab === 'activity' && <ActivityPanel queryKey={['offers', id]} fetcher={(p) => offersApi.activity(id, p)} />}
      </Card>

      <RevisionEditor editor={editor} onChange={(t) => setEditor((e) => (e ? { ...e, terms: t } : e))} onClose={() => setEditor(null)} saving={saveRev.isPending} onSave={() => saveRev.mutate()} />

      <Dialog open={!!decision} onClose={() => setDecision(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{decision?.kind === 'accept' ? `Accept revision ${decision.rev?.revision_no}` : decision?.kind === 'reject' ? `Reject revision ${decision?.rev?.revision_no}` : 'Withdraw offer'}</DialogTitle>
        <DialogContent dividers>
          {decision?.kind === 'accept' && <Alert severity="info" sx={{ mb: 2 }}>Accepting closes the offer: all other open or draft revisions become superseded. Only one revision can ever be accepted.</Alert>}
          <TextField label={decision?.kind === 'accept' ? 'Note (optional)' : 'Reason'} required={decision?.kind !== 'accept'} multiline minRows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDecision(null)}>Cancel</Button>
          <LoadingButton variant="contained" color={decision?.kind === 'accept' ? 'success' : 'error'} loading={act.isPending}
            disabled={decision?.kind !== 'accept' && reason.trim().length < 3} onClick={() => act.mutate({ kind: decision!.kind, rev: decision!.rev })}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!cmp} onClose={() => setCmp(null)} maxWidth="sm" fullWidth>
        <DialogTitle>Revision {diff.data?.from} → {diff.data?.to}</DialogTitle>
        <DialogContent dividers>
          {diff.isLoading ? <SectionLoader /> : diff.isError ? <Alert severity="error">{errorMessage(diff.error)}</Alert> : diff.data?.changes.length ? (
            <Table size="small">
              <TableHead><TableRow><TableCell>Field</TableCell><TableCell>From</TableCell><TableCell>To</TableCell></TableRow></TableHead>
              <TableBody>
                {diff.data.changes.map((c) => (
                  <TableRow key={c.field}>
                    <TableCell sx={{ fontWeight: 600 }}>{humanize(c.field)}</TableCell>
                    <TableCell sx={{ color: 'error.main', wordBreak: 'break-word' }}>{typeof c.from === 'object' ? JSON.stringify(c.from) : String(c.from ?? '—')}</TableCell>
                    <TableCell sx={{ color: 'success.main', wordBreak: 'break-word' }}>{typeof c.to === 'object' ? JSON.stringify(c.to) : String(c.to ?? '—')}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          ) : <Typography color="text.secondary">No commercial differences.</Typography>}
        </DialogContent>
        <DialogActions><Button onClick={() => setCmp(null)}>Close</Button></DialogActions>
      </Dialog>
    </>
  );
}

function RevisionEditor({ editor, onChange, onClose, onSave, saving }: {
  editor: { mode: 'new' | 'edit'; rev?: OfferRevision; terms: Terms } | null; onChange: (t: Terms) => void; onClose: () => void; onSave: () => void; saving: boolean;
}) {
  if (!editor) return null;
  const t = editor.terms;
  const set = (k: keyof Terms) => (e: React.ChangeEvent<HTMLInputElement>) => onChange({ ...t, [k]: e.target.value });
  const bad = (v: string, s: number) => !!v && !isDecimal(v, s);
  const valid = t.rate !== '' && isDecimal(t.rate, 4) && !bad(t.quantity, 3) && !bad(t.address_pct, 4) && !bad(t.brokerage_pct, 4) && !bad(t.other_pct, 4);

  return (
    <Dialog open onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>{editor.mode === 'new' ? `New ${t.direction === 'inbound' ? 'counter (received)' : 'outbound'} revision` : `Edit draft revision ${editor.rev?.revision_no}`}</DialogTitle>
      <DialogContent dividers>
        <Alert severity="info" sx={{ mb: 2 }}>Terms are prefilled from the latest revision. Once {t.direction === 'outbound' ? 'sent' : 'recorded as received'}, the revision becomes immutable.</Alert>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 4 }}>
            <TextField select label="Direction" value={t.direction} disabled={editor.mode === 'edit'} onChange={set('direction')}>
              <MenuItem value="outbound">Outbound (our offer)</MenuItem><MenuItem value="inbound">Inbound (counter received)</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField label="Rate" required value={t.rate} onChange={set('rate')} inputMode="decimal" error={bad(t.rate, 4)} /></Grid>
          <Grid size={{ xs: 6, sm: 2.5 }}>
            <TextField select label="Basis" value={t.rate_basis} onChange={set('rate_basis')}>{RATE_BASES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>)}</TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 2.5 }}><CurrencySelect label="Currency" required value={t.currency} onChange={(v) => onChange({ ...t, currency: v ?? 'USD' })} /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField label="Quantity" value={t.quantity} onChange={set('quantity')} inputMode="decimal" error={bad(t.quantity, 3)} /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField label="Period (days)" value={t.period_days} onChange={set('period_days')} inputMode="decimal" /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField type="date" label="Laycan from" value={t.laycan_from} onChange={set('laycan_from')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 6, sm: 3 }}><TextField type="date" label="Laycan to" value={t.laycan_to} onChange={set('laycan_to')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={{ xs: 4 }}><TextField label="Address comm. %" value={t.address_pct} onChange={set('address_pct')} inputMode="decimal" error={bad(t.address_pct, 4)} /></Grid>
          <Grid size={{ xs: 4 }}><TextField label="Brokerage %" value={t.brokerage_pct} onChange={set('brokerage_pct')} inputMode="decimal" error={bad(t.brokerage_pct, 4)} /></Grid>
          <Grid size={{ xs: 4 }}><TextField label="Other %" value={t.other_pct} onChange={set('other_pct')} inputMode="decimal" error={bad(t.other_pct, 4)} /></Grid>
          <Grid size={{ xs: 12, sm: 4 }}><TextField type="date" label="Valid until" value={t.valid_until} onChange={set('valid_until')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
          <Grid size={12}><TextField label="Terms" multiline minRows={3} value={t.terms} onChange={set('terms')} /></Grid>
          <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={t.remarks} onChange={set('remarks')} /></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <Box sx={{ flex: 1 }} />
        <LoadingButton variant="contained" loading={saving} disabled={!valid} onClick={onSave}>Save draft</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
