import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert, AlertTitle, Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, FormControlLabel, Stack, Switch,
  Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs, TextField, Tooltip, Typography,
} from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import ContentCopy from '@mui/icons-material/ContentCopy';
import CheckCircleOutline from '@mui/icons-material/CheckCircleOutline';
import LockOutlined from '@mui/icons-material/LockOutlined';
import RefreshIcon from '@mui/icons-material/Refresh';
import SaveIcon from '@mui/icons-material/Save';
import { estimationsApi, offersApi } from '../../api/chartering';
import { ApiError } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { ActivityPanel } from '../../components/ActivityPanel';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { ESTIMATION_TYPES, labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime } from '../../utils/format';
import { days, money, trimZeros } from '../../utils/decimal';
import type { Estimation, Scenario, ScenarioInputs } from '../../types/chartering';
import { ScenarioInputsEditor } from './ScenarioInputsEditor';
import { ScenarioResultsPanel, TraceTable } from './ScenarioResultsPanel';

const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T;

/** Older/imported scenarios may omit optional arrays; keep the editor render-safe. */
const normalizeScenarioInputs = (inputs?: Partial<ScenarioInputs>): ScenarioInputs => ({
  sea_margin_pct: inputs?.sea_margin_pct ?? '0',
  eca_fuel_type_id: inputs?.eca_fuel_type_id ?? null,
  legs: Array.isArray(inputs?.legs) ? inputs.legs : [],
  calls: Array.isArray(inputs?.calls) ? inputs.calls : [],
  consumption: Array.isArray(inputs?.consumption) ? inputs.consumption : [],
  fuel_prices: Array.isArray(inputs?.fuel_prices) ? inputs.fuel_prices : [],
  revenue_items: Array.isArray(inputs?.revenue_items) ? inputs.revenue_items : [],
  cost_items: Array.isArray(inputs?.cost_items) ? inputs.cost_items : [],
});

export default function EstimationDetailPage() {
  const id = Number(useParams().id);
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const est = useQuery({ queryKey: ['estimations', id], queryFn: () => estimationsApi.get(id) });

  const scenarioParam = params.get('scenario');
  const view = params.get('view') ?? 'scenario';
  const scenarios = est.data?.scenarios ?? [];
  const currentId = scenarioParam ? Number(scenarioParam) : (scenarios.find((s) => s.is_selected) ?? scenarios[0])?.id;
  const [decision, setDecision] = useState<'approve' | 'reject' | 'voyage' | 'offer' | null>(null);
  const [text, setText] = useState('');

  const invalidate = () => { qc.invalidateQueries({ queryKey: ['estimations'] }); qc.invalidateQueries({ queryKey: ['enquiries'] }); };
  const act = useMutation({
    mutationFn: async (kind: string) => {
      switch (kind) {
        case 'submit': return estimationsApi.submit(id);
        case 'approve': return estimationsApi.approve(id, text || undefined);
        case 'reject': return estimationsApi.reject(id, text);
        case 'reopen': return estimationsApi.reopen(id);
        default: throw new Error(kind);
      }
    },
    onSuccess: (r) => { notify.success(r.message); setDecision(null); setText(''); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const cloneEst = useMutation({
    mutationFn: () => estimationsApi.clone(id),
    onSuccess: (r) => { notify.success(r.message); invalidate(); navigate(`/chartering/estimations/${r.data.id}`); },
    onError: (e) => notify.error(e),
  });
  const toVoyage = useMutation({
    mutationFn: () => estimationsApi.convertToVoyage(id, text),
    onSuccess: (r) => { notify.success(r.message); setDecision(null); setText(''); navigate(`/operations/voyages/${r.data.id}`); },
    onError: (e) => notify.error(e),
  });
  const toOffer = useMutation({
    mutationFn: () => offersApi.create({ enquiry_id: est.data!.enquiry_id, vessel_id: est.data!.vessel_id, estimation_scenario_id: currentId }),
    onSuccess: (r) => { notify.success(r.message); navigate(`/chartering/offers/${r.data.id}`); },
    onError: (e) => notify.error(e),
  });

  if (est.isLoading) return <SectionLoader />;
  if (est.isError || !est.data) return <ErrorState error={est.error} onRetry={() => est.refetch()} />;
  const e = est.data;
  const selected = scenarios.find((s) => s.is_selected);

  return (
    <>
      <PageHeader
        title={e.estimation_number}
        subtitle={[labelOf(ESTIMATION_TYPES, e.estimation_type), e.vessel?.name, e.enquiry?.enquiry_number, e.currency].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Chartering' }, { label: 'Estimations', to: '/chartering/estimations' }, { label: e.estimation_number }]}
        actions={<>
          {e.status === 'draft' && can(P.EstimationsSubmit) && <Button variant="contained" disabled={!selected} onClick={() => act.mutate('submit')}>Submit for approval</Button>}
          {e.status === 'submitted' && can(P.EstimationsReject) && <Button color="error" onClick={() => setDecision('reject')}>Reject</Button>}
          {e.status === 'submitted' && can(P.EstimationsApprove) && <Button variant="contained" color="success" onClick={() => setDecision('approve')}>Approve</Button>}
          {e.status === 'rejected' && can(P.EstimationsUpdate) && <Button variant="outlined" onClick={() => act.mutate('reopen')}>Reopen as draft</Button>}
          {e.status === 'approved' && e.enquiry_id && can(P.OffersCreate) && <Button variant="contained" onClick={() => setDecision('offer')}>Create offer</Button>}
          {e.status === 'approved' && can(P.VoyagesCreate) && <Button variant="outlined" onClick={() => setDecision('voyage')}>Direct operation → voyage</Button>}
          {can(P.EstimationsClone) && <Tooltip title="Copy into a new draft estimation (scenario lineage preserved)"><Button startIcon={<ContentCopy />} onClick={() => cloneEst.mutate()} disabled={cloneEst.isPending}>Clone</Button></Tooltip>}
        </>}
      />

      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <StatusChip status={e.status} />
        {!e.is_editable && <Chip size="small" icon={<LockOutlined />} label="Read-only" />}
        {e.cloned_from && <Chip size="small" variant="outlined" label={`Cloned from ${e.cloned_from.estimation_number}`} onClick={() => navigate(`/chartering/estimations/${e.cloned_from!.id}`)} />}
        {e.enquiry && <Chip size="small" variant="outlined" label={`Enquiry ${e.enquiry.enquiry_number}`} onClick={() => navigate(`/chartering/enquiries/${e.enquiry!.id}`)} />}
        {e.submitted_by && <Typography variant="body2" color="text.secondary">Submitted by {e.submitted_by.name} {formatDateTime(e.submitted_at)}</Typography>}
        {e.decided_by && <Typography variant="body2" color="text.secondary">· {e.status === 'approved' ? 'Approved' : 'Decided'} by {e.decided_by.name} {formatDateTime(e.decided_at)}</Typography>}
      </Stack>
      {e.status === 'rejected' && e.decision_comment && <Alert severity="error" sx={{ mb: 2 }}><AlertTitle>Rejected</AlertTitle>{e.decision_comment}</Alert>}
      {e.status === 'approved' && <Alert severity="success" sx={{ mb: 2 }}>Approved estimations are read-only. Clone to explore changes; the approved figures stay unchanged.</Alert>}

      <Card>
        <Tabs value={view === 'scenario' ? `s${currentId}` : view} variant="scrollable"
          onChange={(_, v: string) => setParams(v.startsWith('s') ? { scenario: v.slice(1) } : { view: v })} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          {scenarios.map((s) => (
            <Tab key={s.id} value={`s${s.id}`} label={
              <Stack direction="row" spacing={0.75} alignItems="center">
                <span>{s.code} · {s.name}</span>
                {s.is_selected && <CheckCircleOutline fontSize="small" color="success" titleAccess="Selected" />}
                {s.calc_status !== 'calculated' && <Chip size="small" color="warning" variant="outlined" label={s.calc_status === 'incomplete' ? 'incomplete' : 'not calculated'} />}
              </Stack>
            } />
          ))}
          <Tab value="compare" label="Compare" />
          <Tab value="documents" label="Documents" />
          <Tab value="activity" label="Activity" />
        </Tabs>

        {view === 'scenario' && currentId && <ScenarioWorkspace key={currentId} estimation={e} scenarioId={currentId} onChanged={invalidate}
          onCreated={(sid) => setParams({ scenario: String(sid) })} />}
        {view === 'compare' && <CompareView estimation={e} />}
        {view === 'documents' && <DocumentsPanel parentType="estimations" parentId={id} canEdit={can(P.EstimationsUpdate)} />}
        {view === 'activity' && <ActivityPanel queryKey={['estimations', id]} fetcher={(p) => estimationsApi.activity(id, p)} />}
      </Card>

      <Dialog open={!!decision} onClose={() => setDecision(null)} maxWidth="sm" fullWidth>
        <DialogTitle>
          {decision === 'approve' && 'Approve estimation'}{decision === 'reject' && 'Reject estimation'}
          {decision === 'voyage' && 'Convert to voyage without fixture'}{decision === 'offer' && 'Create offer'}
        </DialogTitle>
        <DialogContent dividers>
          {decision === 'voyage' && <Alert severity="info" sx={{ mb: 2 }}>For internal positioning, owner operations or spot offshore jobs with no fixture. Scenario {selected?.code} is copied into the voyage's initial snapshot. This is audited.</Alert>}
          {decision === 'offer' && <Typography>Revision 1 will be drafted from scenario <b>{scenarios.find((s) => s.id === currentId)?.code}</b> (rate, basis, quantity and commissions) and the enquiry itinerary. You can edit it before sending.</Typography>}
          {decision !== 'offer' && (
            <TextField autoFocus label={decision === 'approve' ? 'Comment (optional)' : decision === 'voyage' ? 'Direct-operation reason' : 'Reason'} required={decision !== 'approve'}
              multiline minRows={3} value={text} onChange={(ev) => setText(ev.target.value)}
              helperText={decision === 'voyage' ? 'At least 10 characters' : undefined} />
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDecision(null)}>Cancel</Button>
          {decision === 'approve' && <LoadingButton variant="contained" color="success" loading={act.isPending} onClick={() => act.mutate('approve')}>Approve</LoadingButton>}
          {decision === 'reject' && <LoadingButton variant="contained" color="error" loading={act.isPending} disabled={text.trim().length < 3} onClick={() => act.mutate('reject')}>Reject</LoadingButton>}
          {decision === 'voyage' && <LoadingButton variant="contained" loading={toVoyage.isPending} disabled={text.trim().length < 10} onClick={() => toVoyage.mutate()}>Create voyage</LoadingButton>}
          {decision === 'offer' && <LoadingButton variant="contained" loading={toOffer.isPending} onClick={() => toOffer.mutate()}>Create offer</LoadingButton>}
        </DialogActions>
      </Dialog>
    </>
  );
}

function ScenarioWorkspace({ estimation, scenarioId, onChanged, onCreated }: { estimation: Estimation; scenarioId: number; onChanged: () => void; onCreated: (id: number) => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const key = ['estimations', estimation.id, 'scenario', scenarioId];
  const scenario = useQuery({ queryKey: key, queryFn: () => estimationsApi.scenario(estimation.id, scenarioId) });
  const [draft, setDraft] = useState<ScenarioInputs | null>(null);
  const [name, setName] = useState('');
  const [panel, setPanel] = useState<'inputs' | 'results' | 'trace'>('inputs');
  const [refresh, setRefresh] = useState<{ vessel: boolean; consumption: boolean } | null>(null);
  const [conflict, setConflict] = useState(false);

  useEffect(() => {
    if (scenario.data) { setDraft(normalizeScenarioInputs(clone(scenario.data.inputs))); setName(scenario.data.name); setConflict(false); }
  }, [scenario.data]);

  const readOnly = !estimation.is_editable || !can(P.EstimationsUpdate);
  const dirty = useMemo(() => !!scenario.data && !!draft && (JSON.stringify(draft) !== JSON.stringify(scenario.data.inputs) || name !== scenario.data.name),
    [draft, name, scenario.data]);

  const apply = (s: Scenario) => { qc.setQueryData(key, s); onChanged(); };
  const save = useMutation({
    mutationFn: () => estimationsApi.saveScenario(estimation.id, scenarioId, { lock_version: scenario.data!.lock_version, name, inputs: draft! }),
    onSuccess: (r) => { notify.success(r.message); apply(r.data); if (r.data.calc_status === 'calculated') setPanel('results'); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'stale_record') setConflict(true); else notify.error(e); },
  });
  const run = useMutation({
    mutationFn: (kind: 'select' | 'clone' | 'new' | 'refresh') => {
      if (kind === 'select') return estimationsApi.select(estimation.id, scenarioId);
      if (kind === 'clone') return estimationsApi.addScenario(estimation.id, { clone_from_id: scenarioId });
      if (kind === 'new') return estimationsApi.addScenario(estimation.id, {});
      return estimationsApi.refreshDefaults(estimation.id, scenarioId, refresh!);
    },
    onSuccess: (r, kind) => {
      notify.success(r.message);
      setRefresh(null);
      if (kind === 'clone' || kind === 'new') { onChanged(); onCreated(r.data.id); } else apply(r.data);
    },
    onError: (e) => notify.error(e),
  });

  if (scenario.isLoading || !draft) return <SectionLoader />;
  if (scenario.isError || !scenario.data) return <ErrorState error={scenario.error} onRetry={() => scenario.refetch()} />;
  const s = scenario.data;

  const patch = (fn: (d: ScenarioInputs) => void) => setDraft((d) => { const n = clone(d!); fn(n); return n; });

  return (
    <CardContent sx={{ p: { xs: 2, sm: 3 }, '&:last-child': { pb: { xs: 2, sm: 3 } } }}>
      <Stack direction={{ xs: 'column', xl: 'row' }} spacing={1.5} justifyContent="space-between" alignItems={{ xl: 'center' }} sx={{ mb: 2, p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'action.hover' }}>
        <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
          <TextField size="small" label="Scenario name" value={name} disabled={readOnly} onChange={(ev) => setName(ev.target.value)} sx={{ width: { xs: '100%', sm: 280 }, maxWidth: '100%' }} />
          <StatusChip status={s.calc_status} />
          {s.is_selected && <Chip size="small" color="success" icon={<CheckCircleOutline />} label="Selected" />}
          {s.cloned_from_id && <Chip size="small" variant="outlined" label={`Cloned from #${s.cloned_from_id}`} />}
          {dirty && !readOnly && <Chip size="small" color="warning" label="Unsaved changes" />}
        </Stack>
        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
          {!readOnly && <LoadingButton variant="contained" startIcon={<SaveIcon />} loading={save.isPending} disabled={!dirty} onClick={() => save.mutate()}>Save & calculate</LoadingButton>}
          {!readOnly && !s.is_selected && <Button variant="outlined" disabled={dirty || s.calc_status !== 'calculated'} onClick={() => run.mutate('select')}>Select scenario</Button>}
          {!readOnly && <Button startIcon={<ContentCopy />} disabled={dirty} onClick={() => run.mutate('clone')}>Clone</Button>}
          {!readOnly && <Button startIcon={<AddIcon />} disabled={dirty} onClick={() => run.mutate('new')}>New from defaults</Button>}
          {!readOnly && <Button startIcon={<RefreshIcon />} disabled={dirty} onClick={() => setRefresh({ vessel: true, consumption: true })}>Refresh defaults</Button>}
        </Stack>
      </Stack>

      {conflict && <Alert severity="error" sx={{ mb: 2 }} action={<Button color="inherit" size="small" onClick={() => scenario.refetch()}>Reload</Button>}>
        Someone else saved this scenario meanwhile. Reload to see their version (your unsaved changes will be discarded).</Alert>}
      {s.calc_issues.length > 0 && (
        <Alert severity="warning" sx={{ mb: 2 }}><AlertTitle>Calculation incomplete</AlertTitle>{s.calc_issues.map((i) => <div key={i}>{i}</div>)}</Alert>
      )}
      <Typography variant="caption" color="text.secondary" display="block" sx={{ mb: 1.5 }}>
        Vessel snapshot: {s.vessel_snapshot?.name} (captured {formatDateTime(s.vessel_snapshot?.captured_at ?? null)}) · defaults refreshed {formatDateTime(s.defaults_refreshed_at)}
      </Typography>

      <Tabs value={panel} onChange={(_, v) => setPanel(v)} sx={{ mb: 2 }}>
        <Tab value="inputs" label="Inputs" />
        <Tab value="results" label="Results" disabled={!s.result} />
        <Tab value="trace" label="Calculation trace" disabled={!s.result?.trace} />
      </Tabs>

      {panel === 'inputs' && <ScenarioInputsEditor inputs={draft} currency={estimation.currency} readOnly={readOnly} patch={patch} />}
      {panel === 'results' && s.result && (
        <>
          {dirty && <Alert severity="info" sx={{ mb: 2 }}>Results reflect the last saved inputs. Save to recalculate.</Alert>}
          <ScenarioResultsPanel result={s.result} currency={estimation.currency} type={estimation.estimation_type} />
        </>
      )}
      {panel === 'trace' && s.result?.trace && <TraceTable steps={s.result.trace} />}

      <Dialog open={!!refresh} onClose={() => setRefresh(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Refresh defaults from master data</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" sx={{ mb: 1.5 }}>Replaces the scenario's snapshot with current master data. Entered prices, costs and revenue are kept. This is recorded in the audit trail.</Typography>
          <FormControlLabel control={<Switch checked={!!refresh?.vessel} onChange={(ev) => setRefresh((r) => r && { ...r, vessel: ev.target.checked })} />} label="Vessel particulars" />
          <FormControlLabel control={<Switch checked={!!refresh?.consumption} onChange={(ev) => setRefresh((r) => r && { ...r, consumption: ev.target.checked })} />} label="Consumption profile" />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setRefresh(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={run.isPending} disabled={!refresh?.vessel && !refresh?.consumption} onClick={() => run.mutate('refresh')}>Refresh</LoadingButton>
        </DialogActions>
      </Dialog>
    </CardContent>
  );
}

function CompareView({ estimation }: { estimation: Estimation }) {
  const cmp = useQuery({ queryKey: ['estimations', estimation.id, 'compare'], queryFn: () => estimationsApi.compare(estimation.id) });
  if (cmp.isLoading) return <SectionLoader />;
  if (cmp.isError || !cmp.data) return <ErrorState error={cmp.error} />;
  const rows = cmp.data.scenarios;
  const c = cmp.data.currency;
  const metric: [string, (r: (typeof rows)[number]) => string][] = [
    ['Speed (kn)', (r) => r.speed_kn.map(trimZeros).join(' / ') || '—'],
    ['Bunker price', (r) => r.fuel_prices.map((f) => `${f.code} ${f.price ? trimZeros(f.price) : '—'}`).join(', ') || '—'],
    ['Sea days', (r) => days(r.result?.sea_days, 2)],
    ['Total days', (r) => days(r.result?.total_days, 2)],
    ['Fuel (MT)', (r) => trimZeros(r.result?.fuel_total_mt)],
    ['Fuel cost', (r) => money(r.result?.fuel_cost)],
    ['Voyage costs', (r) => money(r.result?.voyage_costs)],
    ['Gross revenue', (r) => money(r.result?.gross_revenue)],
    ['Net revenue', (r) => money(r.result?.net_revenue)],
    ['Profit', (r) => money(r.result?.profit)],
    ['TCE / day', (r) => money(r.result?.tce_per_day)],
    ['Break-even rate', (r) => trimZeros(r.result?.breakeven_rate)],
  ];
  return (
    <Box sx={{ p: 2, overflowX: 'auto' }}>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>All amounts in {c}. Incomplete scenarios show “—”.</Typography>
      <Table size="small">
        <TableHead>
          <TableRow>
            <TableCell />
            {rows.map((r) => <TableCell key={r.id} align="right"><b>{r.code}</b> · {r.name}{r.is_selected ? ' ✓' : ''}<br /><StatusChip status={r.calc_status} /></TableCell>)}
          </TableRow>
        </TableHead>
        <TableBody>
          {metric.map(([label, fn]) => (
            <TableRow key={label}>
              <TableCell sx={{ fontWeight: 600 }}>{label}</TableCell>
              {rows.map((r) => <TableCell key={r.id} align="right" sx={{ fontVariantNumeric: 'tabular-nums' }}>{fn(r)}</TableCell>)}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Box>
  );
}
