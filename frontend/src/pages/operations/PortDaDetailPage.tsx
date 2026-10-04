import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, Chip, IconButton, Stack, Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { portDasApi } from '../../api/operations';
import { ApiError, errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { StatusChip } from '../../components/StatusChip';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { ReferenceSelect } from '../../components/MasterPickers';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime, humanize } from '../../utils/format';
import { isDecimal, isNegative, money } from '../../utils/decimal';
import type { PortDa } from '../../types/operations';

interface Row { key: number; category: number | null; description: string; estimated: string; actual: string }

const toRows = (da: PortDa): Row[] => (da.items ?? []).map((i) => ({ key: i.id, category: i.da_cost_category_id, description: i.description ?? '', estimated: i.estimated_amount ?? '', actual: i.actual_amount ?? '' }));
const validAmount = (v: string) => v.trim() === '' || isDecimal(v.trim(), 2);

export default function PortDaDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('items');
  const [rows, setRows] = useState<Row[]>([]);
  const [dirty, setDirty] = useState(false);
  const [nextKey, setNextKey] = useState(-1);
  const [error, setError] = useState<string | null>(null);
  const da = useQuery({ queryKey: ['port-das', id], queryFn: () => portDasApi.get(id) });

  // Reset the editor whenever the server copy changes (after save, submit, or a stale-record reload).
  useEffect(() => { if (da.data) { setRows(toRows(da.data)); setDirty(false); } }, [da.data]);
  const invalidate = () => qc.invalidateQueries({ queryKey: ['port-das'] });

  const save = useMutation({
    mutationFn: () => portDasApi.saveItems(id, rows.filter((r) => r.category !== null).map((r) => ({
      da_cost_category_id: r.category!, description: r.description.trim() || null, estimated_amount: r.estimated.trim() || null, actual_amount: r.actual.trim() || null, remarks: null,
    }))),
    onSuccess: (r) => { notify.success(r.message); setError(null); invalidate(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const act = useMutation({
    mutationFn: (a: 'submit' | 'approve' | 'reject') => portDasApi.action(id, a),
    onSuccess: (r) => { notify.success(r.message); invalidate(); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'stale_record') da.refetch(); notify.error(e); },
  });

  if (da.isLoading) return <SectionLoader />;
  if (da.isError || !da.data) return <ErrorState error={da.error} onRetry={() => da.refetch()} />;
  const d = da.data;
  const editing = d.is_editable && can(P.PortDaUpdate);
  const final = d.da_type === 'final';
  const invalid = rows.some((r) => r.category === null || !validAmount(r.estimated) || !validAmount(r.actual));
  const patch = (key: number, p: Partial<Row>) => { setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...p } : r))); setDirty(true); };

  return (
    <>
      <PageHeader title={d.da_number} subtitle={[d.voyage?.voyage_number, d.port?.name, humanize(d.da_type)].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Operations' }, { label: 'Port DA', to: '/operations/port-da' }, { label: d.da_number }]}
        actions={<>
          {d.status === 'draft' && can(P.PortDaUpdate) && <LoadingButton variant="contained" loading={act.isPending} disabled={dirty || rows.length === 0} onClick={() => act.mutate('submit')}>Submit for approval</LoadingButton>}
          {d.status === 'submitted' && can(P.PortDaApprove) && <Button color="error" onClick={() => act.mutate('reject')}>Return to draft</Button>}
          {d.status === 'submitted' && can(P.PortDaApprove) && <LoadingButton variant="contained" color="success" loading={act.isPending} onClick={() => act.mutate('approve')}>Approve</LoadingButton>}
        </>} />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <StatusChip status={d.status} />
        {d.voyage && <Chip size="small" variant="outlined" label={`Voyage ${d.voyage.voyage_number}`} onClick={() => navigate(`/operations/voyages/${d.voyage!.id}`)} />}
        {d.proforma && <Chip size="small" variant="outlined" label={`Proforma ${d.proforma.da_number}`} onClick={() => navigate(`/operations/port-da/${d.proforma!.id}`)} />}
      </Stack>
      {d.status === 'approved' && final && <Alert severity="success" sx={{ mb: 2 }}>Approved {formatDateTime(d.approved_at)}. The final DA has been booked as a voyage expense.</Alert>}
      {d.status === 'approved' && !final && <Alert severity="info" sx={{ mb: 2 }}>Approved proforma. It is an estimate and does not book an expense; create a final DA when the agent's account arrives.</Alert>}

      <Card>
        <Tabs value={tab} onChange={(_, t) => setTab(t)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="items" label={`Items (${d.items?.length ?? 0})`} /><Tab value="details" label="Details" /><Tab value="documents" label="Documents" />
        </Tabs>
        {tab === 'items' && (
          <CardContent>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
              The DA total is the {final ? 'actual' : 'estimated'} amount ({d.currency}); variance (actual − estimated) is calculated by the server when you save.
            </Typography>
            <Table size="small">
              <TableHead><TableRow>
                <TableCell sx={{ minWidth: 200 }}>Category</TableCell><TableCell>Description</TableCell>
                <TableCell align="right">Estimated</TableCell><TableCell align="right">Actual</TableCell>{!editing && <TableCell align="right">Variance</TableCell>}{editing && <TableCell />}
              </TableRow></TableHead>
              <TableBody>
                {editing ? rows.map((r) => (
                  <TableRow key={r.key}>
                    <TableCell><ReferenceSelect type="da-cost-categories" label="Category" required size="small" value={r.category} onChange={(c) => patch(r.key, { category: c })} /></TableCell>
                    <TableCell><TextField size="small" value={r.description} onChange={(e) => patch(r.key, { description: e.target.value })} slotProps={{ htmlInput: { 'aria-label': 'Item description' } }} /></TableCell>
                    <TableCell><TextField size="small" value={r.estimated} error={!validAmount(r.estimated)} onChange={(e) => patch(r.key, { estimated: e.target.value })} slotProps={{ htmlInput: { 'aria-label': 'Estimated amount', inputMode: 'decimal', style: { textAlign: 'right' } } }} /></TableCell>
                    <TableCell><TextField size="small" value={r.actual} error={!validAmount(r.actual)} onChange={(e) => patch(r.key, { actual: e.target.value })} slotProps={{ htmlInput: { 'aria-label': 'Actual amount', inputMode: 'decimal', style: { textAlign: 'right' } } }} /></TableCell>
                    <TableCell align="right"><Tooltip title="Remove line"><IconButton size="small" color="error" aria-label="Remove line" onClick={() => { setRows((rs) => rs.filter((x) => x.key !== r.key)); setDirty(true); }}><DeleteOutline fontSize="small" /></IconButton></Tooltip></TableCell>
                  </TableRow>
                )) : (d.items ?? []).map((i) => (
                  <TableRow key={i.id}>
                    <TableCell>{i.category?.name ?? '—'}</TableCell><TableCell>{i.description ?? '—'}</TableCell>
                    <TableCell align="right">{money(i.estimated_amount)}</TableCell><TableCell align="right">{money(i.actual_amount)}</TableCell>
                    <TableCell align="right"><Typography component="span" fontSize={14} color={isNegative(i.variance_amount) ? 'success.main' : i.variance_amount && i.variance_amount !== '0.00' ? 'error.main' : undefined}>{money(i.variance_amount)}</Typography></TableCell>
                  </TableRow>
                ))}
                {(editing ? rows.length : (d.items?.length ?? 0)) === 0 && <TableRow><TableCell colSpan={6}><Typography color="text.secondary" align="center" sx={{ py: 2 }}>No items yet.</Typography></TableCell></TableRow>}
                <TableRow sx={{ '& td': { fontWeight: 700, borderTop: 2, borderColor: 'divider' } }}>
                  <TableCell colSpan={editing ? 4 : 4}>Total ({final ? 'actual' : 'estimated'})</TableCell>
                  <TableCell align="right" colSpan={editing ? 2 : 1}>{money(d.total_amount, d.currency)}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
            {editing && (
              <Stack direction="row" spacing={1} sx={{ mt: 2 }} justifyContent="space-between">
                <Button startIcon={<AddIcon />} onClick={() => { setRows((rs) => [...rs, { key: nextKey, category: null, description: '', estimated: '', actual: '' }]); setNextKey((k) => k - 1); setDirty(true); }}>Add item</Button>
                <LoadingButton variant="contained" loading={save.isPending} disabled={!dirty || invalid} onClick={() => save.mutate()}>Save items</LoadingButton>
              </Stack>
            )}
          </CardContent>
        )}
        {tab === 'details' && (
          <CardContent>
            <KeyValueGrid columns={4} items={[
              ['Port call', d.port_call?.label], ['Agent', d.agent?.legal_name], ['Currency', d.currency], ['Base total', money(d.base_amount, d.base_currency)],
              ['FX rate', d.fx_rate], ['FX method', d.fx_method ? humanize(d.fx_method) : null], ['Submitted', d.submitted_at ? formatDateTime(d.submitted_at) : null], ['Approved', d.approved_at ? formatDateTime(d.approved_at) : null],
            ]} />
            {d.remarks && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Remarks</Typography><Typography fontSize={14} whiteSpace="pre-wrap">{d.remarks}</Typography></Box>}
          </CardContent>
        )}
        {tab === 'documents' && <DocumentsPanel parentType="port-das" parentId={id} canEdit={editing} />}
      </Card>
    </>
  );
}
