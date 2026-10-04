import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, Button, Card, CardContent, MenuItem, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import EditOutlined from '@mui/icons-material/EditOutlined';
import UndoIcon from '@mui/icons-material/Undo';
import { vesselsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { DataTable, type Column } from '../../components/DataTable';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { countryName } from '../../constants/countries';
import { OWNERSHIP_TYPES } from '../../constants/masters';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatDateTime } from '../../utils/format';
import { withUnit } from '../../utils/format';
import type { StatusTrack, VesselStatusEntry } from '../../types/masters';
import { ChangeStatusDialog } from './ChangeStatusDialog';
import { ConsumptionProfilesPanel } from './ConsumptionProfilesPanel';

export default function VesselDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('particulars');
  const [statusOpen, setStatusOpen] = useState(false);
  const [historyTrack, setHistoryTrack] = useState<StatusTrack | ''>('');
  const [historyPage, setHistoryPage] = useState(1);
  const [undoTrack, setUndoTrack] = useState<StatusTrack | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const vessel = useQuery({ queryKey: ['vessels', id], queryFn: () => vesselsApi.get(id) });
  const history = useQuery({
    queryKey: ['vessel-status', 'history', id, historyTrack, historyPage],
    queryFn: () => vesselsApi.statusHistory(id, { page: historyPage, track: historyTrack || undefined }),
    enabled: tab === 'status' && can(P.VesselStatusView),
    placeholderData: keepPreviousData,
  });

  const undo = useMutation({
    mutationFn: (t: StatusTrack) => vesselsApi.undoStatus(id, t),
    onSuccess: (r) => { notify.success(r.message); setUndoTrack(null); qc.invalidateQueries({ queryKey: ['vessel-status'] }); qc.invalidateQueries({ queryKey: ['vessels'] }); },
    onError: (e) => { setUndoTrack(null); notify.error(e); },
  });
  const remove = useMutation({ mutationFn: () => vesselsApi.remove(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['vessels'] }); navigate('/fleet/vessels'); }, onError: (e) => notify.error(e) });

  if (vessel.isLoading) return <SectionLoader />;
  if (vessel.isError || !vessel.data) return <ErrorState error={vessel.error} onRetry={() => vessel.refetch()} />;
  const v = vessel.data;
  const canEdit = can(P.VesselsUpdate);

  const historyCols: Column<VesselStatusEntry>[] = [
    { key: 'track', header: 'Track', render: (h) => (h.track === 'commercial' ? 'Commercial' : 'Operational') },
    { key: 'status', header: 'Status', render: (h) => <StatusChip status={h.status} /> },
    { key: 'from', header: 'From', render: (h) => formatDateTime(h.effective_from) },
    { key: 'to', header: 'To', render: (h) => (h.effective_to ? formatDateTime(h.effective_to) : <Typography fontSize={14} color="success.main" fontWeight={600}>Current</Typography>) },
    { key: 'loc', header: 'Location', hideBelow: 'md', render: (h) => h.location ?? '—' },
    { key: 'reason', header: 'Reason', hideBelow: 'lg', render: (h) => h.reason ?? '—' },
    { key: 'by', header: 'Changed by', hideBelow: 'lg', render: (h) => h.changed_by?.name ?? '—' },
  ];

  const custom = v.vessel_type?.attribute_schema ?? [];

  return (
    <>
      <PageHeader
        title={v.name}
        subtitle={[v.code, v.imo_number && `IMO ${v.imo_number}`, v.vessel_type?.name, v.flag_country && `${countryName(v.flag_country)} flag`].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Fleet' }, { label: 'Vessels', to: '/fleet/vessels' }, { label: v.name }]}
        actions={<>
          {can(P.VesselsDelete) && <Button color="error" onClick={() => setConfirmDelete(true)}>Delete</Button>}
          {can(P.VesselStatusUpdate) && <Button variant="outlined" onClick={() => setStatusOpen(true)}>Update status</Button>}
          {canEdit && <Button variant="contained" startIcon={<EditOutlined />} onClick={() => navigate(`/fleet/vessels/${id}/edit`)}>Edit</Button>}
        </>}
      />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" alignItems="center">
        <Typography variant="body2" color="text.secondary">Commercial:</Typography>{v.commercial_status ? <StatusChip status={v.commercial_status} /> : <Typography variant="body2">—</Typography>}
        <Typography variant="body2" color="text.secondary" sx={{ pl: 1 }}>Operational:</Typography>{v.operational_status ? <StatusChip status={v.operational_status} /> : <Typography variant="body2">—</Typography>}
        <Box sx={{ pl: 1 }}><StatusChip status={v.status} /></Box>
      </Stack>

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} variant="scrollable" sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="particulars" label="Particulars" />
          <Tab value="consumption" label="Consumption" />
          {can(P.VesselStatusView) && <Tab value="status" label="Status history" />}
          <Tab value="documents" label="Documents" />
        </Tabs>

        {tab === 'particulars' && (
          <CardContent>
            <Stack spacing={3}>
              <Section title="Identity & registry"><KeyValueGrid columns={4} items={[
                ['IMO', v.imo_number], ['MMSI', v.mmsi], ['Call sign', v.call_sign], ['Official number', v.official_number],
                ['Type', v.vessel_type?.name], ['Subtype', v.subtype], ['Flag', countryName(v.flag_country)], ['Port of registry', v.port_of_registry],
                ['Year built', v.year_built], ['Builder', v.builder], ['Class', v.class_society], ['Class notation', v.class_notation],
              ]} /></Section>
              <Section title="Parties"><KeyValueGrid columns={4} items={[
                ['Ownership', OWNERSHIP_TYPES.find((o) => o.value === v.ownership_type)?.label], ['Owner', v.owner?.legal_name],
                ['Manager', v.manager?.legal_name], ['Commercial manager', v.commercial_manager?.legal_name], ['Technical manager', v.technical_manager?.legal_name],
              ]} /></Section>
              <Section title="Dimensions & tonnage"><KeyValueGrid columns={4} items={[
                ['LOA', withUnit(v.loa_m, 'm')], ['LBP', withUnit(v.lbp_m, 'm')], ['Beam', withUnit(v.beam_m, 'm')], ['Depth', withUnit(v.depth_m, 'm')],
                ['Summer draft', withUnit(v.summer_draft_m, 'm')], ['Air draft', withUnit(v.air_draft_m, 'm')], ['DWT', withUnit(v.dwt_mt, 't')], ['GT / NT', [v.gt, v.nt].filter(Boolean).join(' / ')],
              ]} /></Section>
              <Section title="Machinery & speed"><KeyValueGrid columns={4} items={[
                ['Main engine', v.main_engine], ['Main power', withUnit(v.main_engine_power_kw, 'kW')], ['Aux engines', v.aux_engines], ['Aux power', withUnit(v.aux_engine_power_kw, 'kW')],
                ['Propulsion', v.propulsion], ['Service speed', withUnit(v.service_speed_kn, 'kn')], ['Max speed', withUnit(v.max_speed_kn, 'kn')], ['Eco speed', withUnit(v.eco_speed_kn, 'kn')],
              ]} /></Section>
              <Section title="Offshore capability & capacity"><KeyValueGrid columns={4} items={[
                ['DP class', v.dp_class], ['Bollard pull', withUnit(v.bollard_pull_t, 't')], ['Crane SWL', withUnit(v.crane_swl_t, 't')], ['Clear deck', withUnit(v.deck_area_m2, 'm²')],
                ['Deck strength', withUnit(v.deck_strength_t_m2, 't/m²')], ['Crew capacity', v.crew_capacity], ['Passengers', v.passenger_capacity],
                ...custom.map((a): [string, string | null] => {
                  const val = v.custom_attributes?.[a.key];
                  return [a.label, val === undefined || val === null ? null : typeof val === 'boolean' ? (val ? 'Yes' : 'No') : `${val}${a.unit ? ` ${a.unit}` : ''}`];
                }),
              ]} /></Section>
              {(v.former_names?.length || v.remarks) && (
                <Section title="Other">
                  {v.former_names?.length ? <Typography fontSize={14}>Former names: {v.former_names.map((n) => `${n.name} (until ${formatDate(n.valid_to)})`).join(', ')}</Typography> : null}
                  {v.remarks && <Typography fontSize={14} whiteSpace="pre-wrap" sx={{ mt: 1 }}>{v.remarks}</Typography>}
                </Section>
              )}
            </Stack>
          </CardContent>
        )}

        {tab === 'consumption' && <ConsumptionProfilesPanel vesselId={id} canEdit={canEdit} />}

        {tab === 'status' && (
          <Box>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ p: 2 }} justifyContent="space-between">
              <TextField select label="Track" value={historyTrack} onChange={(e) => { setHistoryTrack(e.target.value as StatusTrack | ''); setHistoryPage(1); }} sx={{ maxWidth: 220 }}>
                <MenuItem value="">Both tracks</MenuItem><MenuItem value="commercial">Commercial</MenuItem><MenuItem value="operational">Operational</MenuItem>
              </TextField>
              {can(P.VesselStatusUpdate) && (
                <Stack direction="row" spacing={1}>
                  <Button size="small" startIcon={<UndoIcon />} onClick={() => setUndoTrack('commercial')}>Undo last commercial</Button>
                  <Button size="small" startIcon={<UndoIcon />} onClick={() => setUndoTrack('operational')}>Undo last operational</Button>
                </Stack>
              )}
            </Stack>
            <DataTable columns={historyCols} rows={history.data?.data ?? []} rowKey={(h) => h.id} loading={history.isFetching} meta={history.data?.meta} onPageChange={setHistoryPage}
              emptyTitle="No status history yet" />
          </Box>
        )}

        {tab === 'documents' && <DocumentsPanel parentType="vessels" parentId={id} canEdit={canEdit} />}
      </Card>

      <ChangeStatusDialog open={statusOpen} vessel={v} onClose={() => setStatusOpen(false)} />
      <ConfirmDialog open={!!undoTrack} title="Undo latest status change" message={`Remove the latest ${undoTrack} status entry and re-open the previous one? This is audited.`}
        confirmLabel="Undo" loading={undo.isPending} onConfirm={() => undoTrack && undo.mutate(undoTrack)} onClose={() => setUndoTrack(null)} />
      <ConfirmDialog open={confirmDelete} title="Delete vessel" message={`Delete ${v.name}? Use record status "Sold" or "Scrapped" to keep it in history instead.`}
        confirmLabel="Delete" danger loading={remove.isPending} onConfirm={() => remove.mutate()} onClose={() => setConfirmDelete(false)} />
    </>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Box>
      <Typography variant="subtitle2" sx={{ mb: 1.5, pb: 0.75, borderBottom: 1, borderColor: 'divider' }}>{title}</Typography>
      {children}
    </Box>
  );
}
