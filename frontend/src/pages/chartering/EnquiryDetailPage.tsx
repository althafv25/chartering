import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, DialogActions, DialogContent, IconButton, MenuItem, Stack, Tab, Tabs, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import AddIcon from '@mui/icons-material/Add';
import { enquiriesApi, estimationsApi, offersApi } from '../../api/chartering';
import { vesselsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { DataTable, type Column } from '../../components/DataTable';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ActivityPanel } from '../../components/ActivityPanel';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { BUSINESS_TYPES, ENQUIRY_TRANSITIONS, ESTIMATION_TYPES, RATE_BASES, labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatDateTime, humanize } from '../../utils/format';
import { groupDigits, money } from '../../utils/decimal';
import type { Estimation, Offer } from '../../types/chartering';
import { EnquiryDialog } from './EnquiryDialog';
import { NewEstimationDialog } from './NewEstimationDialog';

export default function EnquiryDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('overview');
  const [edit, setEdit] = useState(false);
  const [status, setStatus] = useState<{ to: string; reason: string } | null>(null);
  const [newEst, setNewEst] = useState<number | null | false>(false);
  const [vesselToAdd, setVesselToAdd] = useState<number | ''>('');

  const enquiry = useQuery({ queryKey: ['enquiries', id], queryFn: () => enquiriesApi.get(id) });
  const estimations = useQuery({ queryKey: ['estimations', { enquiry_id: id }], queryFn: () => estimationsApi.list({ enquiry_id: id, per_page: 50 }), enabled: tab === 'estimations' });
  const offers = useQuery({ queryKey: ['offers', { enquiry_id: id }], queryFn: () => offersApi.list({ enquiry_id: id, per_page: 50 }), enabled: tab === 'offers' });
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }), enabled: tab === 'vessels' });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['enquiries'] });
  const changeStatus = useMutation({
    mutationFn: () => enquiriesApi.setStatus(id, status!.to, status!.reason || undefined),
    onSuccess: (r) => { notify.success(r.message); setStatus(null); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const shortlist = useMutation({
    mutationFn: (b: { vessel_id: number; shortlist_status?: string }) => enquiriesApi.shortlist(id, b),
    onSuccess: () => { setVesselToAdd(''); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const unshortlist = useMutation({ mutationFn: (vid: number) => enquiriesApi.unshortlist(id, vid), onSuccess: invalidate, onError: (e) => notify.error(e) });

  if (enquiry.isLoading) return <SectionLoader />;
  if (enquiry.isError || !enquiry.data) return <ErrorState error={enquiry.error} onRetry={() => enquiry.refetch()} />;
  const e = enquiry.data;
  const editable = can(P.EnquiriesUpdate) && !e.is_closed;

  const estCols: Column<Estimation>[] = [
    { key: 'no', header: 'Estimation', render: (x) => <Typography fontWeight={600} fontSize={14}>{x.estimation_number}</Typography> },
    { key: 'vessel', header: 'Vessel', render: (x) => x.vessel?.name },
    { key: 'type', header: 'Type', render: (x) => labelOf(ESTIMATION_TYPES, x.estimation_type) },
    { key: 'sel', header: 'Selected scenario', render: (x) => (x.selected_scenario ? `${x.selected_scenario.code} · ${money(x.selected_scenario.result?.profit, x.currency)}` : '—') },
    { key: 'tce', header: 'TCE/day', align: 'right', hideBelow: 'md', render: (x) => money(x.selected_scenario?.result?.tce_per_day, x.currency) },
    { key: 'status', header: 'Status', render: (x) => <StatusChip status={x.status} /> },
  ];
  const offerCols: Column<Offer>[] = [
    { key: 'no', header: 'Offer', render: (o) => <Typography fontWeight={600} fontSize={14}>{o.offer_number}</Typography> },
    { key: 'vessel', header: 'Vessel', render: (o) => o.vessel?.name },
    { key: 'rev', header: 'Latest revision', render: (o) => (o.latest_revision ? `Rev ${o.latest_revision.revision_no} · ${humanize(o.latest_revision.direction)}` : '—') },
    { key: 'rate', header: 'Rate', align: 'right', render: (o) => (o.latest_revision ? `${groupDigits(o.latest_revision.rate)} ${o.latest_revision.currency} ${labelOf(RATE_BASES, o.latest_revision.rate_basis)}` : '—') },
    { key: 'revstatus', header: 'Revision status', render: (o) => (o.latest_revision ? <StatusChip status={o.latest_revision.status} /> : '—') },
    { key: 'status', header: 'Offer', render: (o) => <StatusChip status={o.status} /> },
  ];

  return (
    <>
      <PageHeader
        title={e.enquiry_number}
        subtitle={[labelOf(BUSINESS_TYPES, e.business_type), e.charterer?.legal_name, e.cargo_description].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Chartering' }, { label: 'Enquiries', to: '/chartering/enquiries' }, { label: e.enquiry_number }]}
        actions={<>
          {can(P.EnquiriesUpdate) && ENQUIRY_TRANSITIONS[e.status].length > 0 && (
            <TextField select size="small" label="Change status" value="" onChange={(ev) => setStatus({ to: ev.target.value, reason: '' })} sx={{ minWidth: 170 }}>
              {ENQUIRY_TRANSITIONS[e.status].map((s) => <MenuItem key={s} value={s}>{s === 'open' ? 'Reopen' : `Mark ${humanize(s).toLowerCase()}`}</MenuItem>)}
            </TextField>
          )}
          {editable && <Button variant="contained" startIcon={<EditOutlined />} onClick={() => setEdit(true)}>Edit</Button>}
        </>}
      />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }}>
        <StatusChip status={e.status} />
        {e.lost_reason && <Typography variant="body2" color="error.main">Lost: {e.lost_reason}</Typography>}
      </Stack>

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} variant="scrollable" sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="overview" label="Overview" />
          <Tab value="vessels" label={`Vessels (${e.vessels?.length ?? 0})`} />
          <Tab value="estimations" label={`Estimations (${e.estimations_count ?? 0})`} />
          <Tab value="offers" label={`Offers (${e.offers_count ?? 0})`} />
          <Tab value="documents" label="Documents" />
          <Tab value="activity" label="Activity" />
        </Tabs>

        {tab === 'overview' && (
          <CardContent>
            <KeyValueGrid columns={4} items={[
              ['Received', formatDateTime(e.received_at)], ['Source', humanize(e.source)], ['Charterer', e.charterer?.legal_name], ['Broker', e.broker?.legal_name],
              ['Cargo / service', e.cargo_description], ['Quantity', e.quantity ? `${groupDigits(e.quantity)} ${e.quantity_unit ?? ''}` : null],
              ['Tolerance', e.quantity_tolerance_pct ? `${e.quantity_tolerance_pct} %` : null], ['Period', e.period_days ? `${e.period_days} days` : null],
              ['Laycan', e.laycan_from ? `${formatDate(e.laycan_from)} – ${formatDate(e.laycan_to)}` : null],
              ['Rate idea', e.rate_idea ? `${groupDigits(e.rate_idea)} ${e.currency ?? ''} ${labelOf(RATE_BASES, e.rate_basis)}` : null],
              ['Commission terms', e.commission_terms],
            ]} />
            <Typography variant="subtitle2" sx={{ mt: 3, mb: 1 }}>Itinerary</Typography>
            {e.ports?.length ? (
              <Stack direction="row" gap={1} flexWrap="wrap" alignItems="center">
                {e.ports.map((p, i) => <Typography key={p.id ?? i} fontSize={14}>{i > 0 && '→ '}<b>{p.point?.label}</b> <Typography component="span" variant="body2" color="text.secondary">({humanize(p.purpose)})</Typography></Typography>)}
              </Stack>
            ) : <Typography variant="body2" color="text.secondary">No ports entered.</Typography>}
            {e.terms && <Box sx={{ mt: 3 }}><Typography variant="subtitle2">Terms</Typography><Typography whiteSpace="pre-wrap" fontSize={14}>{e.terms}</Typography></Box>}
            {e.remarks && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Remarks</Typography><Typography whiteSpace="pre-wrap" fontSize={14}>{e.remarks}</Typography></Box>}
          </CardContent>
        )}

        {tab === 'vessels' && (
          <Box>
            {editable && (
              <Stack direction="row" spacing={1} sx={{ p: 2, maxWidth: 560 }}>
                <TextField select label="Add vessel to shortlist" value={vesselToAdd} onChange={(ev) => setVesselToAdd(Number(ev.target.value))}>
                  {(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={v.id}>{v.name} ({v.code}){v.commercial_status ? ` · ${humanize(v.commercial_status)}` : ''}</MenuItem>)}
                </TextField>
                <LoadingButton variant="outlined" disabled={!vesselToAdd} loading={shortlist.isPending} onClick={() => shortlist.mutate({ vessel_id: Number(vesselToAdd) })}>Add</LoadingButton>
              </Stack>
            )}
            <DataTable
              rows={e.vessels ?? []}
              rowKey={(v) => v.vessel_id}
              emptyTitle="No vessels shortlisted"
              columns={[
                { key: 'v', header: 'Vessel', render: (v) => <Typography fontWeight={600} fontSize={14}>{v.vessel.name} <Typography component="span" color="text.secondary" fontSize={13}>({v.vessel.code})</Typography></Typography> },
                { key: 'cs', header: 'Commercial status', render: (v) => (v.vessel.commercial_status ? <StatusChip status={v.vessel.commercial_status} /> : '—') },
                { key: 's', header: 'Shortlist', render: (v) => editable ? (
                  <TextField select size="small" value={v.shortlist_status} onChange={(ev) => shortlist.mutate({ vessel_id: v.vessel_id, shortlist_status: ev.target.value })} sx={{ width: 150 }}>
                    {['candidate', 'selected', 'rejected'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
                  </TextField>) : <StatusChip status={v.shortlist_status} /> },
                { key: 'a', header: '', align: 'right', render: (v) => (
                  <Stack direction="row" justifyContent="flex-end">
                    {can(P.EstimationsCreate) && !e.is_closed && <Button size="small" startIcon={<AddIcon />} onClick={() => setNewEst(v.vessel_id)}>Estimate</Button>}
                    {editable && <Tooltip title="Remove"><IconButton size="small" color="error" aria-label={`Remove ${v.vessel.name}`} onClick={() => unshortlist.mutate(v.vessel_id)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
                  </Stack>
                ) },
              ]}
            />
          </Box>
        )}

        {tab === 'estimations' && (
          <Box>
            {can(P.EstimationsCreate) && !e.is_closed && <Stack direction="row" justifyContent="flex-end" sx={{ p: 2, pb: 0 }}><Button variant="outlined" startIcon={<AddIcon />} onClick={() => setNewEst(null)}>New estimation</Button></Stack>}
            <DataTable columns={estCols} rows={estimations.data?.data ?? []} rowKey={(x) => x.id} loading={estimations.isFetching} onRowClick={(x) => navigate(`/chartering/estimations/${x.id}`)} emptyTitle="No estimations yet" />
          </Box>
        )}

        {tab === 'offers' && (
          <Box>
            {!e.is_closed && can(P.OffersCreate) && <Alert severity="info" sx={{ m: 2, mb: 0 }}>Create offers from an estimation scenario (Estimation → “Create offer”) so the pricing scenario is linked.</Alert>}
            <DataTable columns={offerCols} rows={offers.data?.data ?? []} rowKey={(o) => o.id} loading={offers.isFetching} onRowClick={(o) => navigate(`/chartering/offers/${o.id}`)} emptyTitle="No offers yet" />
          </Box>
        )}

        {tab === 'documents' && <DocumentsPanel parentType="enquiries" parentId={id} canEdit={can(P.EnquiriesUpdate)} />}
        {tab === 'activity' && <ActivityPanel queryKey={['enquiries', id]} fetcher={(p) => enquiriesApi.activity(id, p)} />}
      </Card>

      <EnquiryDialog open={edit} enquiry={e} onClose={() => setEdit(false)} />
      <NewEstimationDialog open={newEst !== false} enquiryId={id} defaultVesselId={newEst || null} onClose={() => setNewEst(false)}
        onCreated={(est) => navigate(`/chartering/estimations/${est.id}`)} />
      <Dialog open={!!status} onClose={() => setStatus(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{status?.to === 'open' ? 'Reopen enquiry' : `Mark enquiry ${humanize(status?.to ?? '').toLowerCase()}`}</DialogTitle>
        <DialogContent dividers>
          <TextField label="Reason" required={status?.to === 'lost'} multiline minRows={2} value={status?.reason ?? ''} onChange={(ev) => setStatus((s) => (s ? { ...s, reason: ev.target.value } : s))} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setStatus(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={changeStatus.isPending} disabled={status?.to === 'lost' && !status.reason.trim()} onClick={() => changeStatus.mutate()}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
