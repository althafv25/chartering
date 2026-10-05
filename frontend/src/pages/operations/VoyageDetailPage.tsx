import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import { voyagesApi, type VoyageAction } from '../../api/operations';
import { ApiError } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { StatusChip } from '../../components/StatusChip';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ActivityPanel } from '../../components/ActivityPanel';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime, humanize, zoneLabel } from '../../utils/format';
import { money } from '../../utils/decimal';
import { PortCallsPanel } from './PortCallsPanel';
import { MilestonesPanel } from './MilestonesPanel';
import { OffHirePanel } from './OffHirePanel';
import { ComparisonPanel } from './ComparisonPanel';
import { CaptainReportsTable } from './CaptainReportsTable';
import { ActivitiesTable } from './ActivitiesTable';
import { BunkerStemsTable } from './BunkerStemsTable';
import { RobLedgerPanel } from './RobLedgerPanel';
import { FinancialsPanel } from './FinancialsPanel';

type Pending = { kind: 'transition'; to: string } | { kind: VoyageAction };
const TITLES: Record<VoyageAction, string> = { complete: 'Complete voyage', finalize: 'Finalize voyage', reopen: 'Reopen voyage', cancel: 'Cancel voyage' };

export default function VoyageDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can, user } = useAuth();
  const [tab, setTab] = useState('overview');
  const [pending, setPending] = useState<Pending | null>(null);
  const [at, setAt] = useState('');
  const [text, setText] = useState('');
  const [waivers, setWaivers] = useState<Record<string, string>>({});
  const v = useQuery({ queryKey: ['voyages', id], queryFn: () => voyagesApi.get(id) });
  const gates = useQuery({ queryKey: ['voyages', id, 'finance-gates'], queryFn: () => voyagesApi.financeGates(id), enabled: v.data?.status === 'completed' });

  const run = useMutation({
    mutationFn: () => {
      const p = pending!;
      if (p.kind === 'transition') return voyagesApi.transition(id, { status: p.to, at: at || null, note: text || null });
      if (p.kind === 'complete') return voyagesApi.action(id, 'complete', { at: at || null });
      if (p.kind === 'finalize') return voyagesApi.action(id, 'finalize', { waivers });
      return voyagesApi.action(id, p.kind, { reason: text });
    },
    onSuccess: (r) => { notify.success(r.message); setPending(null); setAt(''); setText(''); setWaivers({}); qc.invalidateQueries({ queryKey: ['voyages'] }); },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'voyage_not_finalizable') {
        setWaivers((w) => ({ ...Object.fromEntries(Object.keys(e.fieldErrors).map((k) => [k, w[k] ?? ''])) }));
      }
      notify.error(e);
    },
  });

  if (v.isLoading) return <SectionLoader />;
  if (v.isError || !v.data) return <ErrorState error={v.error} onRetry={() => v.refetch()} />;
  const voyage = v.data;
  const initial = voyage.snapshots?.find((s) => s.type === 'initial');
  const results = (initial?.payload as { results?: Record<string, string> } | undefined)?.results ?? {};
  const needsReason = pending && (pending.kind === 'reopen' || pending.kind === 'cancel');
  const allowsTime = pending && (pending.kind === 'transition' || pending.kind === 'complete');
  const open = (p: Pending) => { setAt(''); setText(''); setWaivers({}); setPending(p); };

  return (
    <>
      <PageHeader title={voyage.voyage_number} subtitle={[voyage.vessel?.name, humanize(voyage.operation_type), voyage.charterer?.legal_name, voyage.currency].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Operations' }, { label: 'Voyages', to: '/operations/voyages' }, { label: voyage.voyage_number }]}
        actions={<>
          {can(P.VoyagesUpdate) && voyage.allowed_transitions.map((s) => <Button key={s} variant="outlined" onClick={() => open({ kind: 'transition', to: s })}>{humanize(s)}</Button>)}
          {can(P.VoyagesComplete) && voyage.can_complete && <Button variant="contained" onClick={() => open({ kind: 'complete' })}>Complete</Button>}
          {can(P.VoyagesFinalize) && voyage.status === 'completed' && <Button variant="contained" color="success" onClick={() => open({ kind: 'finalize' })}>Finalize</Button>}
          {can(P.VoyagesReopen) && voyage.status === 'finalized' && <Button onClick={() => open({ kind: 'reopen' })}>Reopen</Button>}
          {can(P.VoyagesCancel) && !['completed', 'finalized', 'cancelled'].includes(voyage.status) && <Button color="error" onClick={() => open({ kind: 'cancel' })}>Cancel</Button>}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} alignItems="center" flexWrap="wrap" useFlexGap>
        <StatusChip status={voyage.status} />
        <StatusChip status={voyage.conversion_type} label={voyage.conversion_type === 'direct_estimation' ? 'Direct estimation (no fixture)' : 'From fixture'} />
        {voyage.reopened_count > 0 && <Chip size="small" color="warning" variant="outlined" label={`Reopened ×${voyage.reopened_count}`} />}
        {voyage.fixture && <Chip size="small" variant="outlined" label={`Fixture ${voyage.fixture.fixture_number}`} onClick={() => navigate(`/chartering/fixtures/${voyage.fixture!.id}`)} />}
        {voyage.contract && <Chip size="small" variant="outlined" label={`Contract ${voyage.contract.contract_number}`} onClick={() => navigate(`/contracts/${voyage.contract!.id}`)} />}
        {voyage.estimation && <Chip size="small" variant="outlined" label={`${voyage.estimation.estimation_number} / ${voyage.scenario?.code}`}
          onClick={() => navigate(`/chartering/estimations/${voyage.estimation!.id}?scenario=${voyage.estimation_scenario_id}`)} />}
      </Stack>
      {voyage.status === 'finalized' && <Alert severity="success" sx={{ mb: 2 }}>Finalized {formatDateTime(voyage.finalized_at)}. The voyage is read-only; reopening keeps the current final snapshot as history.</Alert>}
      {voyage.status === 'cancelled' && <Alert severity="info" sx={{ mb: 2 }}>Cancelled {formatDateTime(voyage.cancelled_at)}{voyage.status_reason ? ` — ${voyage.status_reason}` : ''}</Alert>}
      {voyage.direct_reason && <Alert severity="info" sx={{ mb: 2 }}><b>Direct-operation reason:</b> {voyage.direct_reason}</Alert>}

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} variant="scrollable" sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="overview" label="Overview" />
          <Tab value="calls" label={`Itinerary (${voyage.port_calls?.length ?? 0})`} />
          <Tab value="milestones" label={`Milestones (${voyage.milestones?.length ?? 0})`} />
          <Tab value="reports" label="Captain reports" />
          {voyage.operation_type === 'offshore' && can(P.OffshoreActivitiesView) && <Tab value="activities" label="Offshore activities" />}
          {can(P.BunkersView) && <Tab value="bunkers" label="Bunkers" />}
          <Tab value="offhire" label={`Off-hire (${voyage.off_hires?.length ?? 0})`} />
          <Tab value="comparison" label="Estimate vs actual" />
          {can(P.FinancialsView) && <Tab value="financials" label="P&L" />}
          <Tab value="documents" label="Documents" />
          <Tab value="activity" label="Activity" />
        </Tabs>
        {tab === 'overview' && (
          <CardContent>
            <KeyValueGrid columns={4} items={[
              ['Commenced', formatDateTime(voyage.commenced_at)], ['Completed', formatDateTime(voyage.completed_at)], ['Last status change', formatDateTime(voyage.status_changed_at)],
              ['Next call', voyage.next_port_call ? voyage.next_port_call.label : null],
              ['Estimated days', results.total_days ?? null], ['Estimated revenue', money(results.gross_revenue ?? null, voyage.currency)],
              ['Estimated profit', money(results.profit ?? null, voyage.currency)], ['Estimated TCE / day', money(results.tce_per_day ?? null, voyage.currency)],
              ['Initial snapshot', initial ? `${formatDateTime(initial.created_at)}${initial.calculation_version ? ` · engine v${initial.calculation_version}` : ''}` : null],
              ['Snapshots', String(voyage.snapshots?.length ?? 0)], ['Status note', voyage.status_reason],
            ]} />
            <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>Shown in UTC unless stated. Enter port call times in each port's local time.</Typography>
            {voyage.status === 'completed' && gates.data && (
              <Box sx={{ mt: 3 }}>
                <Typography variant="subtitle2" sx={{ mb: 1 }}>Finalization readiness (OP-04)</Typography>
                <Stack spacing={0.75}>
                  {Object.entries(gates.data).map(([key, gate]) => (
                    <Stack key={key} direction="row" spacing={1} alignItems="center">
                      <StatusChip status={gate.passed ? 'agreed' : 'disputed'} label={gate.passed ? 'OK' : 'Blocked'} />
                      <Typography fontSize={14}>{gate.label}:</Typography>
                      <Typography fontSize={14} color="text.secondary">{gate.message}</Typography>
                    </Stack>
                  ))}
                </Stack>
              </Box>
            )}
            {voyage.finalization_waivers && Object.keys(voyage.finalization_waivers).length > 0 && (
              <Box sx={{ mt: 3 }}>
                <Typography variant="subtitle2" sx={{ mb: 1 }}>Finalization waivers</Typography>
                <Stack spacing={0.75}>
                  {Object.entries(voyage.finalization_waivers).map(([key, w]) => (
                    <Alert key={key} severity="warning" sx={{ py: 0.5 }}>
                      <b>{key}:</b> {w.reason} ({formatDateTime(w.waived_at)})
                    </Alert>
                  ))}
                </Stack>
              </Box>
            )}
          </CardContent>
        )}
        {tab === 'calls' && <PortCallsPanel voyage={voyage} />}
        {tab === 'milestones' && <MilestonesPanel voyage={voyage} />}
        {tab === 'reports' && <CaptainReportsTable filters={{ voyage_id: id }} vesselId={voyage.vessel_id} voyageId={id} readOnly={!voyage.is_open} />}
        {tab === 'activities' && <ActivitiesTable filters={{ voyage_id: id }} preset={{ vessel_id: voyage.vessel_id, voyage_id: id }} readOnly={!voyage.is_open} />}
        {tab === 'bunkers' && (
          <>
            <BunkerStemsTable filters={{ voyage_id: id }} preset={{ vessel_id: voyage.vessel_id, voyage_id: id }} readOnly={!voyage.is_open} />
            <RobLedgerPanel voyageId={id} />
          </>
        )}
        {tab === 'offhire' && <OffHirePanel voyage={voyage} />}
        {tab === 'comparison' && <ComparisonPanel voyage={voyage} />}
        {tab === 'financials' && <FinancialsPanel voyageId={id} />}
        {tab === 'documents' && <DocumentsPanel parentType="voyages" parentId={id} canEdit={can(P.VoyagesUpdate) && voyage.status !== 'finalized'} />}
        {tab === 'activity' && <ActivityPanel queryKey={['voyages', id]} fetcher={(p) => voyagesApi.activity(id, p)} />}
      </Card>

      <Dialog open={!!pending} onClose={() => setPending(null)} maxWidth={pending?.kind === 'finalize' ? 'sm' : 'xs'} fullWidth>
        <DialogTitle>{pending?.kind === 'transition' ? `Move to ${humanize(pending.to)}` : pending && TITLES[pending.kind]}</DialogTitle>
        <DialogContent dividers>
          {pending?.kind === 'complete' && <Alert severity="info" sx={{ mb: 2 }}>All port calls must be sailed or cancelled and no captain report may be pending.</Alert>}
          {pending?.kind === 'finalize' && (
            <>
              <Alert severity="info" sx={{ mb: 2 }}>Saves the immutable final snapshot and locks the voyage. All off-hire must be agreed or disputed.</Alert>
              {gates.data && Object.values(gates.data).some((g) => !g.passed) && (
                <Stack spacing={1.5} sx={{ mb: 1 }}>
                  {Object.entries(gates.data).filter(([, g]) => !g.passed).map(([key, g]) => (
                    <Box key={key}>
                      <Alert severity="warning" sx={{ mb: 1 }}>{g.label}: {g.message}</Alert>
                      <TextField label={`Waiver reason for "${g.label}"`} required multiline minRows={2} fullWidth
                        value={waivers[key] ?? ''} onChange={(e) => setWaivers((w) => ({ ...w, [key]: e.target.value }))} />
                    </Box>
                  ))}
                </Stack>
              )}
            </>
          )}
          {pending?.kind === 'transition' && voyage.commenced_at === null && !['nominated'].includes(pending.to) && <Alert severity="info" sx={{ mb: 2 }}>This marks the voyage as commenced.</Alert>}
          {allowsTime && (
            <Box sx={{ mb: 2 }}>
              <TextField type="datetime-local" label="Event time (optional)" value={at} onChange={(e) => setAt(e.target.value)} slotProps={{ inputLabel: { shrink: true } }}
                helperText={`Leave empty for now. Your time zone: ${zoneLabel(user?.timezone ?? 'UTC')}.`} />
            </Box>
          )}
          {(needsReason || pending?.kind === 'transition') && (
            <TextField label={needsReason ? 'Reason' : 'Note (optional)'} required={!!needsReason} multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} />
          )}
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setPending(null)}>Close</Button>
          <LoadingButton variant="contained" color={pending?.kind === 'cancel' ? 'error' : 'primary'} loading={run.isPending}
            disabled={(!!needsReason && text.trim().length < (pending?.kind === 'reopen' ? 5 : 3))
              || (pending?.kind === 'finalize' && !!gates.data && Object.entries(gates.data).some(([key, g]) => !g.passed && (waivers[key] ?? '').trim().length < 10))}
            onClick={() => run.mutate()}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
