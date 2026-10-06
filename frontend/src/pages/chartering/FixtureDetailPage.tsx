import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, AlertTitle, Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import LockOutlined from '@mui/icons-material/LockOutlined';
import { fixturesApi } from '../../api/chartering';
import { fixtureLifecycleApi } from '../../api/contracts';
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
import { BUSINESS_TYPES, RATE_BASES, labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatDateTime } from '../../utils/format';
import { groupDigits, money, trimZeros } from '../../utils/decimal';

type Action = 'submit' | 'approve' | 'reject' | 'fail' | 'cancel';
const NEEDS_REASON: Action[] = ['reject', 'fail', 'cancel'];
const TITLES: Record<Action, string> = { submit: 'Submit for approval', approve: 'Approve fixture', reject: 'Return to draft', fail: 'Mark fixture failed', cancel: 'Cancel fixture' };

export default function FixtureDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('recap');
  const [action, setAction] = useState<Action | null>(null);
  const [text, setText] = useState('');
  const [edit, setEdit] = useState<{ terms: string; remarks: string; cargo_description: string } | null>(null);

  const fx = useQuery({ queryKey: ['fixtures', id], queryFn: () => fixturesApi.get(id) });
  const invalidate = () => { qc.invalidateQueries({ queryKey: ['fixtures'] }); qc.invalidateQueries({ queryKey: ['enquiries'] }); };

  const move = useMutation({
    mutationFn: (a: Action) => fixtureLifecycleApi.transition(id, a, NEEDS_REASON.includes(a) ? { reason: text } : text ? { comment: text } : {}),
    onSuccess: (r) => { notify.success(r.message); setAction(null); setText(''); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const save = useMutation({
    mutationFn: () => fixtureLifecycleApi.update(id, { ...edit, lock_version: fx.data!.lock_version }),
    onSuccess: (r) => { notify.success(r.message); setEdit(null); invalidate(); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'stale_record') fx.refetch(); notify.error(e); },
  });
  const toContract = useMutation({
    mutationFn: () => fixtureLifecycleApi.toContract(id),
    onSuccess: (r) => { notify.success(r.message); invalidate(); navigate(`/contracts/${r.data.id}`); },
    onError: (e) => notify.error(e),
  });
  const toVoyage = useMutation({
    mutationFn: () => fixtureLifecycleApi.toVoyage(id),
    onSuccess: (r) => { notify.success(r.message); invalidate(); navigate(`/operations/voyages/${r.data.id}`); },
    onError: (e) => notify.error(e),
  });

  if (fx.isLoading) return <SectionLoader />;
  if (fx.isError || !fx.data) return <ErrorState error={fx.error} />;
  const f = fx.data;
  const snap = f.recap_snapshot as { estimation?: { number: string; scenario_code: string; results?: Record<string, string> }; offer?: { number: string; revision_no: number }; captured_at?: string } | undefined;
  const approved = f.status === 'approved';

  return (
    <>
      <PageHeader title={f.fixture_number} subtitle={[f.vessel?.name, f.charterer?.legal_name, labelOf(BUSINESS_TYPES, f.business_type)].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Chartering' }, { label: 'Fixtures', to: '/chartering/fixtures' }, { label: f.fixture_number }]}
        actions={<>
          <PdfDocumentButton parentType="fixtures" parentId={id} permission={P.FixturesUpdate} generate={() => fixturesApi.generatePdf(id)} />
          {f.status === 'draft' && can(P.FixturesUpdate) && <Button onClick={() => setEdit({ terms: f.terms ?? '', remarks: f.remarks ?? '', cargo_description: f.cargo_description ?? '' })}>Edit</Button>}
          {f.status === 'draft' && can(P.FixturesSubmit) && <Button variant="contained" onClick={() => setAction('submit')}>Submit for approval</Button>}
          {f.status === 'submitted' && can(P.FixturesApprove) && <Button color="error" onClick={() => setAction('reject')}>Return to draft</Button>}
          {f.status === 'submitted' && can(P.FixturesApprove) && <Button variant="contained" color="success" onClick={() => setAction('approve')}>Approve</Button>}
          {approved && !f.contract && can(P.ContractsCreate) && <LoadingButton variant="contained" loading={toContract.isPending} onClick={() => toContract.mutate()}>Create contract</LoadingButton>}
          {approved && !f.voyage && can(P.VoyagesCreate) && <LoadingButton variant="outlined" loading={toVoyage.isPending} onClick={() => toVoyage.mutate()}>Create voyage</LoadingButton>}
          {approved && !f.contract && !f.voyage && can(P.FixturesCancel) && <Button color="error" onClick={() => setAction('fail')}>Mark failed</Button>}
          {['draft', 'submitted'].includes(f.status) && can(P.FixturesCancel) && <Button color="error" onClick={() => setAction('cancel')}>Cancel</Button>}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <StatusChip status={f.status} />
        <Chip size="small" icon={<LockOutlined />} label="Commercial terms locked (snapshot)" />
        {f.enquiry && <Chip size="small" variant="outlined" label={`Enquiry ${f.enquiry.enquiry_number}`} onClick={() => navigate(`/chartering/enquiries/${f.enquiry!.id}`)} />}
        {f.contract && <Chip size="small" color="primary" label={`Contract ${f.contract.contract_number} (${f.contract.status})`} onClick={() => navigate(`/contracts/${f.contract!.id}`)} />}
        {f.voyage && <Chip size="small" color="secondary" label={`Voyage ${f.voyage.voyage_number}`} onClick={() => navigate(`/operations/voyages/${f.voyage!.id}`)} />}
      </Stack>
      {f.decision_comment && ['draft', 'failed', 'cancelled'].includes(f.status) && f.decided_at && (
        <Alert severity={f.status === 'draft' ? 'warning' : 'error'} sx={{ mb: 2 }}><AlertTitle>{f.status === 'draft' ? 'Returned to draft' : `Fixture ${f.status}`}</AlertTitle>{f.decision_comment}</Alert>
      )}

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="recap" label="Recap" /><Tab value="documents" label="Documents" /><Tab value="activity" label="Activity" />
        </Tabs>
        {tab === 'recap' && (
          <CardContent>
            <KeyValueGrid columns={4} items={[
              ['Fixture date', formatDate(f.fixture_date)], ['Rate', `${groupDigits(f.rate)} ${f.currency} ${labelOf(RATE_BASES, f.rate_basis)}`],
              ['Quantity', f.quantity ? `${groupDigits(f.quantity)} ${f.quantity_unit ?? ''}` : null], ['Laycan', f.laycan_from ? `${formatDate(f.laycan_from)} – ${formatDate(f.laycan_to)}` : null],
              ['Commissions', `add ${trimZeros(f.commissions.address_pct)}% · bkg ${trimZeros(f.commissions.brokerage_pct)}% · other ${trimZeros(f.commissions.other_pct)}%`],
              ['Ports', f.ports.map((p) => p.label).join(' → ')], ['Offer', snap?.offer ? `${snap.offer.number} rev ${snap.offer.revision_no}` : null],
              ['Estimation', snap?.estimation ? `${snap.estimation.number} / scenario ${snap.estimation.scenario_code}` : null],
              ['Estimated profit', money(snap?.estimation?.results?.profit ?? null)], ['Estimated TCE/day', money(snap?.estimation?.results?.tce_per_day ?? null)],
              ['Cargo / service', f.cargo_description], ['Snapshot taken', formatDateTime(snap?.captured_at ?? null)],
              ['Submitted', f.submitted_by ? `${f.submitted_by.name}, ${formatDateTime(f.submitted_at)}` : null],
              ['Decided', f.decided_by ? `${f.decided_by.name}, ${formatDateTime(f.decided_at)}` : null],
            ]} />
            {f.terms && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Terms</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{f.terms}</Typography></Box>}
            {f.remarks && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Remarks</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{f.remarks}</Typography></Box>}
          </CardContent>
        )}
        {tab === 'documents' && <DocumentsPanel parentType="fixtures" parentId={id} canEdit={can(P.FixturesUpdate)} />}
        {tab === 'activity' && <ActivityPanel queryKey={['fixtures', id]} fetcher={(p) => fixtureLifecycleApi.activity(id, p)} />}
      </Card>

      <Dialog open={!!action} onClose={() => setAction(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{action && TITLES[action]}</DialogTitle>
        <DialogContent dividers>
          {action === 'approve' && <Typography variant="body2" sx={{ mb: 1.5 }}>Approval confirms the fixture (subjects lifted). A contract and voyage can then be created.</Typography>}
          {action === 'fail' && <Typography variant="body2" sx={{ mb: 1.5 }}>The enquiry returns to “evaluating” so negotiation can continue.</Typography>}
          {action !== 'submit' && <TextField label={action && NEEDS_REASON.includes(action) ? 'Reason' : 'Comment (optional)'} required={!!action && NEEDS_REASON.includes(action)}
            multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} />}
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setAction(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={move.isPending} disabled={!!action && NEEDS_REASON.includes(action) && text.trim().length < 3} onClick={() => action && move.mutate(action)}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!edit} onClose={() => setEdit(null)} maxWidth="sm" fullWidth>
        <DialogTitle>Edit draft fixture</DialogTitle>
        <DialogContent dividers>
          <Alert severity="info" sx={{ mb: 2 }}>Rate, currency, quantity, laycan, ports and commissions come from the accepted offer and cannot be edited.</Alert>
          <Stack spacing={2}>
            <TextField label="Cargo / service description" value={edit?.cargo_description ?? ''} onChange={(e) => setEdit((x) => x && { ...x, cargo_description: e.target.value })} />
            <TextField label="Terms" multiline minRows={3} value={edit?.terms ?? ''} onChange={(e) => setEdit((x) => x && { ...x, terms: e.target.value })} />
            <TextField label="Remarks" multiline minRows={2} value={edit?.remarks ?? ''} onChange={(e) => setEdit((x) => x && { ...x, remarks: e.target.value })} />
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setEdit(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>Save</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
