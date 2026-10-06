import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert, AlertTitle, Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, Grid, MenuItem, Stack, Tab, Table, TableBody,
  TableCell, TableHead, TableRow, Tabs, TextField, Typography,
} from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import LockOutlined from '@mui/icons-material/LockOutlined';
import { contractsApi } from '../../api/contracts';
import { ApiError } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { StatusChip } from '../../components/StatusChip';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { PdfDocumentButton } from '../../components/PdfDocumentButton';
import { ActivityPanel } from '../../components/ActivityPanel';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { CONTRACT_TYPES, RATE_TYPES } from '../../constants/contracts';
import { labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatDateTime, humanize } from '../../utils/format';
import { groupDigits, trimZeros } from '../../utils/decimal';
import type { Contract, ContractClause, ContractRate } from '../../types/contracts';
import { ClausesEditor, RatesEditor } from './TermsEditors';
import { AmendmentsPanel } from './AmendmentsPanel';
import { clausesValid, currentClauses, currentRates, ratesValid } from './termsHelpers';

type Action = 'submit' | 'approve' | 'reject' | 'activate' | 'complete' | 'cancel';
const REASON: Action[] = ['reject', 'cancel'];

export default function ContractDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('overview');
  const [action, setAction] = useState<Action | null>(null);
  const [text, setText] = useState('');
  const contract = useQuery({ queryKey: ['contracts', id], queryFn: () => contractsApi.get(id) });
  const refresh = () => { qc.invalidateQueries({ queryKey: ['contracts'] }); qc.invalidateQueries({ queryKey: ['fixtures'] }); };

  const act = useMutation({
    mutationFn: (a: Action) => contractsApi.action(id, a, REASON.includes(a) ? { reason: text } : a === 'approve' && text ? { comment: text } : a === 'complete' && text ? { note: text } : {}),
    onSuccess: (r) => { notify.success(r.message); setAction(null); setText(''); refresh(); },
    onError: (e) => notify.error(e),
  });

  if (contract.isLoading) return <SectionLoader />;
  if (contract.isError || !contract.data) return <ErrorState error={contract.error} onRetry={() => contract.refetch()} />;
  const c = contract.data;
  const btn = (a: Action, label: string, perm: string, props: object = {}) => can(perm) && <Button {...props} onClick={() => (a === 'submit' || a === 'activate' ? act.mutate(a) : setAction(a))}>{label}</Button>;

  return (
    <>
      <PageHeader title={c.contract_number} subtitle={[c.title, labelOf(CONTRACT_TYPES, c.contract_type), c.customer?.legal_name, c.vessel?.name].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Contracts', to: '/contracts' }, { label: c.contract_number }]}
        actions={<>
          {can(P.ContractsRatesView) && <PdfDocumentButton parentType="contracts" parentId={id} permission={P.ContractsUpdate} generate={() => contractsApi.generatePdf(id)} />}
          {c.status === 'draft' && btn('submit', 'Submit for review', P.ContractsSubmit, { variant: 'contained' })}
          {c.status === 'under_review' && btn('reject', 'Return to draft', P.ContractsApprove, { color: 'error' })}
          {c.status === 'under_review' && btn('approve', 'Approve', P.ContractsApprove, { variant: 'contained', color: 'success' })}
          {c.status === 'approved' && btn('activate', 'Activate', P.ContractsActivate, { variant: 'contained' })}
          {c.status === 'active' && btn('complete', 'Complete', P.ContractsActivate, { variant: 'outlined' })}
          {['draft', 'under_review', 'approved'].includes(c.status) && btn('cancel', 'Cancel contract', P.ContractsCancel, { color: 'error' })}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap alignItems="center">
        <StatusChip status={c.status} />
        <Chip size="small" label={`Version ${c.current_version}`} />
        {!c.is_editable && <Chip size="small" icon={<LockOutlined />} label={c.is_amendable ? 'Changes by amendment only' : 'Read-only'} />}
        {c.fixture && <Chip size="small" variant="outlined" label={`Fixture ${c.fixture.fixture_number}`} onClick={() => navigate(`/chartering/fixtures/${c.fixture!.id}`)} />}
      </Stack>
      {c.status === 'draft' && c.decision_comment && c.decided_at && <Alert severity="warning" sx={{ mb: 2 }}><AlertTitle>Returned to draft</AlertTitle>{c.decision_comment}</Alert>}
      {['cancelled', 'expired', 'completed'].includes(c.status) && <Alert severity="info" sx={{ mb: 2 }}>Contract {c.status} {formatDateTime(c.closed_at)}{c.close_reason ? ` — ${c.close_reason}` : ''}</Alert>}

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} variant="scrollable" sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="overview" label="Overview" />
          {c.rates_visible && <Tab value="rates" label="Rates" />}
          <Tab value="clauses" label="Clauses" />
          <Tab value="amendments" label={`Amendments (${c.amendments?.length ?? 0})`} />
          <Tab value="versions" label={`Versions (${c.versions?.length ?? 0})`} />
          <Tab value="documents" label="Documents" />
          <Tab value="activity" label="Activity" />
        </Tabs>
        {tab === 'overview' && <OverviewTab contract={c} onSaved={refresh} />}
        {tab === 'rates' && c.rates_visible && <RatesTab contract={c} onSaved={refresh} />}
        {tab === 'clauses' && <ClausesTab contract={c} onSaved={refresh} />}
        {tab === 'amendments' && <AmendmentsPanel contract={c} />}
        {tab === 'versions' && <VersionsTab contract={c} />}
        {tab === 'documents' && <DocumentsPanel parentType="contracts" parentId={id} canEdit={can(P.ContractsUpdate)} />}
        {tab === 'activity' && <ActivityPanel queryKey={['contracts', id]} fetcher={(p) => contractsApi.activity(id, p)} />}
      </Card>

      <Dialog open={!!action} onClose={() => setAction(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{action && { submit: '', activate: '', approve: 'Approve contract', reject: 'Return to draft', complete: 'Complete contract', cancel: 'Cancel contract' }[action]}</DialogTitle>
        <DialogContent dividers>
          {action === 'approve' && <Alert severity="info" sx={{ mb: 2 }}>Approval freezes version 1. Later changes require amendments.</Alert>}
          <TextField label={action && REASON.includes(action) ? 'Reason' : action === 'complete' ? 'Note (optional)' : 'Comment (optional)'} required={!!action && REASON.includes(action)}
            multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setAction(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={act.isPending} disabled={!!action && REASON.includes(action) && text.trim().length < 3} onClick={() => action && act.mutate(action)}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}

function OverviewTab({ contract: c, onSaved }: { contract: Contract; onSaved: () => void }) {
  const notify = useNotify();
  const { can } = useAuth();
  const editable = c.is_editable && can(P.ContractsUpdate);
  const init = () => ({ title: c.title, start_date: c.start_date ?? '', end_date: c.end_date ?? '', payment_terms_days: c.payment_terms_days?.toString() ?? '',
    payment_terms_text: c.payment_terms_text ?? '', extension_options: c.extension_options ?? '', terms: c.terms ?? '', remarks: c.remarks ?? '',
    address_pct: c.commissions.address_pct, brokerage_pct: c.commissions.brokerage_pct, other_pct: c.commissions.other_pct });
  const [f, setF] = useState(init);
  useEffect(() => setF(init()), [c.lock_version]); // eslint-disable-line react-hooks/exhaustive-deps
  const save = useMutation({
    mutationFn: () => contractsApi.update(c.id, {
      lock_version: c.lock_version, title: f.title, start_date: f.start_date || null, end_date: f.end_date || null,
      payment_terms_days: f.payment_terms_days === '' ? null : Number(f.payment_terms_days), payment_terms_text: f.payment_terms_text || null,
      extension_options: f.extension_options || null, terms: f.terms || null, remarks: f.remarks || null,
      commissions: { address_pct: f.address_pct || '0', brokerage_pct: f.brokerage_pct || '0', other_pct: f.other_pct || '0', broker_company_id: c.commissions.broker_company_id },
    }),
    onSuccess: (r) => { notify.success(r.message); onSaved(); },
    onError: (e) => { notify.error(e); if (e instanceof ApiError && e.code === 'stale_record') onSaved(); },
  });

  if (!editable) {
    return (
      <CardContent>
        <KeyValueGrid columns={4} items={[
          ['Customer', c.customer?.legal_name], ['Vessel', c.vessel?.name], ['Currency', c.currency], ['Type', labelOf(CONTRACT_TYPES, c.contract_type)],
          ['Start', formatDate(c.start_date)], ['End', formatDate(c.end_date)], ['Payment terms', c.payment_terms_days != null ? `${c.payment_terms_days} days` : null],
          ['Payment notes', c.payment_terms_text], ['Commissions', `add ${trimZeros(c.commissions.address_pct)}% · bkg ${trimZeros(c.commissions.brokerage_pct)}% · other ${trimZeros(c.commissions.other_pct)}%`],
          ['Extension options', c.extension_options], ['Submitted', c.submitted_by ? `${c.submitted_by.name}, ${formatDateTime(c.submitted_at)}` : null],
          ['Approved', c.decided_by && c.status !== 'draft' ? `${c.decided_by.name}, ${formatDateTime(c.decided_at)}` : null], ['Activated', formatDateTime(c.activated_at)],
        ]} />
        {c.terms && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Terms</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{c.terms}</Typography></Box>}
        {c.remarks && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Remarks</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{c.remarks}</Typography></Box>}
      </CardContent>
    );
  }
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });
  return (
    <CardContent>
      {c.fixture && <Alert severity="info" sx={{ mb: 2 }}>Customer, vessel and currency come from fixture {c.fixture.fixture_number} and are locked.</Alert>}
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 6 }}><TextField label="Title" value={f.title} onChange={set('title')} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><TextField type="date" label="Start date" value={f.start_date} onChange={set('start_date')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><TextField type="date" label="End date" value={f.end_date} onChange={set('end_date')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><TextField label="Payment terms (days)" value={f.payment_terms_days} onChange={(e) => setF({ ...f, payment_terms_days: e.target.value.replace(/\D/g, '') })} /></Grid>
        <Grid size={{ xs: 6, md: 9 }}><TextField label="Payment terms text" value={f.payment_terms_text} onChange={set('payment_terms_text')} helperText="e.g. hire 15 days in advance" /></Grid>
        <Grid size={{ xs: 4 }}><TextField label="Address comm. %" value={f.address_pct} onChange={set('address_pct')} /></Grid>
        <Grid size={{ xs: 4 }}><TextField label="Brokerage %" value={f.brokerage_pct} onChange={set('brokerage_pct')} /></Grid>
        <Grid size={{ xs: 4 }}><TextField label="Other %" value={f.other_pct} onChange={set('other_pct')} /></Grid>
        <Grid size={12}><TextField label="Extension options" value={f.extension_options} onChange={set('extension_options')} /></Grid>
        <Grid size={12}><TextField label="Terms" multiline minRows={3} value={f.terms} onChange={set('terms')} /></Grid>
        <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={f.remarks} onChange={set('remarks')} /></Grid>
      </Grid>
      <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}><LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>Save</LoadingButton></Stack>
    </CardContent>
  );
}

function RatesTab({ contract: c, onSaved }: { contract: Contract; onSaved: () => void }) {
  const notify = useNotify();
  const { can } = useAuth();
  const [rates, setRates] = useState<ContractRate[]>(currentRates(c));
  const [version, setVersion] = useState(c.current_version);
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  useEffect(() => setRates(currentRates(c)), [c.lock_version, c.current_version]); // eslint-disable-line react-hooks/exhaustive-deps
  const effective = useQuery({ queryKey: ['contracts', c.id, 'effective', date, c.current_version], queryFn: () => contractsApi.effectiveRates(c.id, date), enabled: !c.is_editable && !!date });
  const save = useMutation({
    mutationFn: () => contractsApi.saveRates(c.id, c.lock_version, rates),
    onSuccess: (r) => { notify.success(r.message); onSaved(); },
    onError: (e) => notify.error(e),
  });

  if (c.is_editable && can(P.ContractsUpdate)) {
    return (
      <CardContent>
        <RatesEditor rates={rates} currency={c.currency} onChange={setRates} />
        <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}><LoadingButton variant="contained" loading={save.isPending} disabled={!ratesValid(rates)} onClick={() => save.mutate()}>Save rates</LoadingButton></Stack>
      </CardContent>
    );
  }
  const shown = (c.rates ?? []).filter((r) => r.version_no === version);
  return (
    <CardContent>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mb: 2 }} alignItems={{ md: 'center' }}>
        <TextField select size="small" label="Version" value={version} onChange={(e) => setVersion(Number(e.target.value))} sx={{ width: 140 }}>
          {(c.versions ?? []).map((v) => <MenuItem key={v.version_no} value={v.version_no}>v{v.version_no} (from {formatDate(v.effective_from)})</MenuItem>)}
          {!c.versions?.length && <MenuItem value={c.current_version}>v{c.current_version}</MenuItem>}
        </TextField>
        <TextField size="small" type="date" label="Effective on" value={date} onChange={(e) => setDate(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: 170 }} />
        {effective.data && <Typography variant="body2">On {formatDate(date)}: {effective.data.version_no ? `version ${effective.data.version_no}, ${effective.data.rates.length} rate(s) apply` : 'no version in force'}</Typography>}
      </Stack>
      <RateTable rates={shown} />
    </CardContent>
  );
}

function RateTable({ rates }: { rates: ContractRate[] }) {
  return (
    <Table size="small">
      <TableHead><TableRow><TableCell>Type</TableCell><TableCell>Description</TableCell><TableCell align="right">Amount</TableCell><TableCell>Unit</TableCell><TableCell>Period</TableCell></TableRow></TableHead>
      <TableBody>
        {rates.map((r, i) => (
          <TableRow key={r.id ?? i}>
            <TableCell>{labelOf(RATE_TYPES, r.rate_type)}</TableCell>
            <TableCell>{r.description ?? '—'}</TableCell>
            <TableCell align="right" sx={{ fontVariantNumeric: 'tabular-nums' }}>{groupDigits(r.amount)} {r.currency}</TableCell>
            <TableCell>{humanize(r.unit)}</TableCell>
            <TableCell>{r.effective_from || r.effective_to ? `${formatDate(r.effective_from)} – ${formatDate(r.effective_to)}` : 'Whole contract'}</TableCell>
          </TableRow>
        ))}
        {rates.length === 0 && <TableRow><TableCell colSpan={5}><Typography variant="body2" color="text.secondary">No rates.</Typography></TableCell></TableRow>}
      </TableBody>
    </Table>
  );
}

function ClausesTab({ contract: c, onSaved }: { contract: Contract; onSaved: () => void }) {
  const notify = useNotify();
  const { can } = useAuth();
  const [clauses, setClauses] = useState<ContractClause[]>(currentClauses(c));
  useEffect(() => setClauses(currentClauses(c)), [c.lock_version, c.current_version]); // eslint-disable-line react-hooks/exhaustive-deps
  const save = useMutation({
    mutationFn: () => contractsApi.saveClauses(c.id, c.lock_version, clauses),
    onSuccess: (r) => { notify.success(r.message); onSaved(); },
    onError: (e) => notify.error(e),
  });
  if (c.is_editable && can(P.ContractsUpdate)) {
    return (
      <CardContent>
        <ClausesEditor clauses={clauses} onChange={setClauses} />
        <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}><LoadingButton variant="contained" loading={save.isPending} disabled={!clausesValid(clauses)} onClick={() => save.mutate()}>Save clauses</LoadingButton></Stack>
      </CardContent>
    );
  }
  return (
    <CardContent>
      <Typography variant="caption" color="text.secondary">Version {c.current_version}</Typography>
      {clauses.length === 0 ? <Typography variant="body2" color="text.secondary">No clauses.</Typography> : clauses.map((x, i) => (
        <Box key={i} sx={{ mt: 2 }}>
          <Typography variant="subtitle2">{x.clause_ref ? `${x.clause_ref}. ` : ''}{x.title}</Typography>
          <Typography fontSize={14} whiteSpace="pre-wrap">{x.body}</Typography>
        </Box>
      ))}
    </CardContent>
  );
}

function VersionsTab({ contract: c }: { contract: Contract }) {
  return (
    <CardContent>
      {!c.versions?.length ? <Typography variant="body2" color="text.secondary">Version 1 is created on approval.</Typography> : (
        <Table size="small">
          <TableHead><TableRow><TableCell>Version</TableCell><TableCell>Effective from</TableCell><TableCell>Source</TableCell><TableCell>End date</TableCell><TableCell>Payment terms</TableCell><TableCell>Recorded</TableCell></TableRow></TableHead>
          <TableBody>
            {c.versions.map((v) => (
              <TableRow key={v.version_no} selected={v.version_no === c.current_version}>
                <TableCell>v{v.version_no}{v.version_no === c.current_version ? ' (current)' : ''}</TableCell>
                <TableCell>{formatDate(v.effective_from)}</TableCell>
                <TableCell>{v.amendment_id ? `Amendment ${c.amendments?.find((a) => a.id === v.amendment_id)?.amendment_no ?? ''}` : 'Original approval'}</TableCell>
                <TableCell>{formatDate((v.header.end_date as string) ?? null)}</TableCell>
                <TableCell>{v.header.payment_terms_days != null ? `${String(v.header.payment_terms_days)} days` : '—'}</TableCell>
                <TableCell>{formatDateTime(v.created_at)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </CardContent>
  );
}
