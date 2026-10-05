import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Alert, Box, Button, DialogActions, DialogContent, IconButton, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography,
} from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { offshoreActivitiesApi } from '../../api/offshore';
import { DataTable, type Column } from '../../components/DataTable';
import { ErrorState } from '../../components/Feedback';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { ACTIVITY_WARNINGS } from '../../constants/operations';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime, formatLocal, humanize, zoneLabel } from '../../utils/format';
import { groupDigits, money, trimZeros } from '../../utils/decimal';
import type { OffshoreActivity } from '../../types/offshore';
import { ActivityDialog } from './ActivityDialog';

interface Props {
  filters: Record<string, string | number | undefined>;
  preset?: { vessel_id?: number; voyage_id?: number; offshore_project_id?: number };
  readOnly?: boolean;
}

export function ActivitiesTable({ filters, preset, readOnly }: Props) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<OffshoreActivity | null | undefined>(undefined);
  const [detail, setDetail] = useState<OffshoreActivity | null>(null);
  const [decision, setDecision] = useState<{ a: OffshoreActivity; kind: 'verify' | 'reject' } | null>(null);
  const [text, setText] = useState('');
  const q = { page, per_page: 15, ...filters };
  const list = useQuery({ queryKey: ['offshore-activities', q], queryFn: () => offshoreActivitiesApi.list(q), placeholderData: keepPreviousData });

  const act = useMutation({
    mutationFn: ({ a, kind }: { a: OffshoreActivity; kind: 'submit' | 'verify' | 'reject' | 'delete' }): Promise<{ message: string }> =>
      kind === 'delete' ? offshoreActivitiesApi.remove(a.id)
        : offshoreActivitiesApi.action(a.id, kind, kind === 'reject' ? { reason: text } : kind === 'verify' && text ? { comment: text } : {}),
    onSuccess: (r) => { notify.success(r.message); setDecision(null); setText(''); setDetail(null); qc.invalidateQueries({ queryKey: ['offshore-activities'] }); qc.invalidateQueries({ queryKey: ['offshore-projects'] }); },
    onError: (e) => notify.error(e),
  });

  const canEdit = !readOnly && can(P.OffshoreActivitiesUpdate);
  const canVerify = !readOnly && can(P.OffshoreActivitiesVerify);
  const summary = list.data?.summary;
  const columns: Column<OffshoreActivity>[] = [
    { key: 'no', header: 'Activity', render: (a) => <Box><Typography fontSize={14} fontWeight={600}>{a.activity_number}</Typography><Typography variant="caption" color="text.secondary">{a.type?.name}</Typography></Box> },
    { key: 'when', header: 'Period', render: (a) => <Box><Typography fontSize={13}>{formatLocal(a.start_at_local)}</Typography><Typography fontSize={13} color="text.secondary">{formatLocal(a.end_at_local)}</Typography></Box> },
    { key: 'where', header: 'Vessel / location', hideBelow: 'md', render: (a) => <Box><Typography fontSize={14}>{a.vessel?.name}</Typography><Typography variant="caption" color="text.secondary">{a.location?.name ?? a.project?.name ?? '—'}</Typography></Box> },
    { key: 'hours', header: 'Bill / non / stby h', align: 'right', render: (a) => `${trimZeros(a.billable_hours)} / ${trimZeros(a.non_billable_hours)} / ${trimZeros(a.standby_hours)}` },
    { key: 'rev', header: 'Revenue', align: 'right', render: (a) => (!a.rates_visible ? '—' : a.revenue_amount !== null ? money(a.revenue_amount, a.currency ?? '')
      : <Typography fontSize={13} color="warning.main">not priced</Typography>) },
    { key: 'status', header: 'Status', render: (a) => <StatusChip status={a.status} /> },
    { key: 'actions', header: '', align: 'right', render: (a) => (
      <Stack direction="row" spacing={0.5} justifyContent="flex-end" onClick={(e) => e.stopPropagation()}>
        {canEdit && a.is_editable && <Button size="small" onClick={() => act.mutate({ a, kind: 'submit' })}>Submit</Button>}
        {canVerify && a.status === 'submitted' && <Button size="small" color="success" onClick={() => setDecision({ a, kind: 'verify' })}>Verify</Button>}
        {canVerify && a.status === 'submitted' && <Button size="small" color="error" onClick={() => setDecision({ a, kind: 'reject' })}>Reject</Button>}
        {canEdit && a.is_editable && <Tooltip title="Edit"><IconButton size="small" aria-label={`Edit ${a.activity_number}`} onClick={() => setEditing(a)}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
        {canEdit && a.is_editable && <Tooltip title="Delete"><IconButton size="small" color="error" aria-label={`Delete ${a.activity_number}`} onClick={() => act.mutate({ a, kind: 'delete' })}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
      </Stack>
    ) },
  ];

  return (
    <>
      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ md: 'center' }} spacing={1} sx={{ px: 2, pt: 2 }}>
        {summary && (
          <Typography variant="body2" color="text.secondary">
            {summary.count} activit{summary.count === 1 ? 'y' : 'ies'} · billable {trimZeros(summary.billable_hours)} h · non-billable {trimZeros(summary.non_billable_hours)} h · standby {trimZeros(summary.standby_hours)} h
            {summary.revenue && summary.revenue.length > 0 && ` · verified revenue ${summary.revenue.map((r) => money(r.amount, r.currency)).join(', ')}`}
          </Typography>
        )}
        {!readOnly && can(P.OffshoreActivitiesCreate) && <Button variant="outlined" onClick={() => setEditing(null)}>New activity</Button>}
      </Stack>
      {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
        <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(a) => a.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
          onRowClick={setDetail} emptyTitle="No offshore activities" emptyDescription="Log supply runs, standby, ROV support and other activities with billable hours." />
      )}
      <ActivityDialog open={editing !== undefined} activity={editing ?? null} preset={preset} onClose={() => setEditing(undefined)} />

      <Dialog open={!!detail} onClose={() => setDetail(null)} maxWidth="md" fullWidth>
        {detail && (
          <>
            <DialogTitle>{detail.activity_number} · {detail.type?.name}</DialogTitle>
            <DialogContent dividers>
              {detail.warnings?.map((w) => <Alert key={w} severity="warning" sx={{ mb: 1 }}>{ACTIVITY_WARNINGS[w] ?? humanize(w)}</Alert>)}
              <KeyValueGrid columns={3} items={[
                ['Status', humanize(detail.status)], ['Vessel', detail.vessel?.name], ['Client', detail.client?.legal_name],
                ['Start', `${formatLocal(detail.start_at_local)} (${zoneLabel(detail.timezone)})`], ['End', formatLocal(detail.end_at_local)], ['Duration', `${trimZeros(detail.duration_hours)} h`],
                ['Contract', detail.contract ? `${detail.contract.contract_number}${detail.contract_version_no ? ` · v${detail.contract_version_no}` : ''}` : null],
                ['Project', detail.project ? `${detail.project.code} — ${detail.project.name}` : null], ['Voyage', detail.voyage?.voyage_number],
                ['Submitted', detail.submitted_by ? `${detail.submitted_by.name}, ${formatDateTime(detail.submitted_at)}` : null],
                ['Verified', detail.verified_by ? `${detail.verified_by.name}, ${formatDateTime(detail.verified_at)}` : null],
                ['Pricing rules', detail.calculation_basis ? detail.calculation_basis.split('|').map(humanize).join(' · ') : null],
              ]} />
              {detail.description && <Typography fontSize={14} sx={{ mt: 2 }} whiteSpace="pre-wrap">{detail.description}</Typography>}
              {detail.rates_visible && (detail.rate_snapshot?.length ?? 0) > 0 && (
                <Table size="small" sx={{ mt: 2 }}>
                  <TableHead><TableRow><TableCell>Line</TableCell><TableCell>Rate type</TableCell><TableCell align="right">Quantity</TableCell><TableCell align="right">Rate</TableCell><TableCell align="right">Amount</TableCell></TableRow></TableHead>
                  <TableBody>
                    {detail.rate_snapshot!.map((l, i) => (
                      <TableRow key={i}>
                        <TableCell>{humanize(l.kind)}{l.hours ? ` (${trimZeros(l.hours)} h)` : ''}</TableCell>
                        <TableCell>{humanize(l.rate_type)}{l.proration ? ` · ${humanize(l.proration)}` : ''}</TableCell>
                        <TableCell align="right">{trimZeros(l.quantity)} {l.unit === 'per_hour' ? 'h' : l.unit === 'per_day' ? 'd' : ''}</TableCell>
                        <TableCell align="right">{groupDigits(l.rate)} {l.currency}</TableCell>
                        <TableCell align="right">{groupDigits(l.amount)}</TableCell>
                      </TableRow>
                    ))}
                    <TableRow><TableCell colSpan={4} sx={{ fontWeight: 700 }}>Revenue</TableCell><TableCell align="right" sx={{ fontWeight: 700 }}>{money(detail.revenue_amount, detail.currency ?? '')}</TableCell></TableRow>
                  </TableBody>
                </Table>
              )}
              {!detail.rates_visible && <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>Rates and revenue are hidden for your role.</Typography>}
              {detail.decision_comment && <Alert severity="info" sx={{ mt: 2 }}>{detail.decision_comment}</Alert>}
            </DialogContent>
            <DialogActions sx={{ px: 3, py: 2 }}><Button onClick={() => setDetail(null)}>Close</Button></DialogActions>
          </>
        )}
      </Dialog>

      <Dialog open={!!decision} onClose={() => setDecision(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{decision?.kind === 'verify' ? `Verify ${decision.a.activity_number}` : `Return ${decision?.a.activity_number}`}</DialogTitle>
        <DialogContent dividers>
          {decision?.kind === 'verify' && <Typography variant="body2" sx={{ mb: 2 }}>Verification freezes the hours and the rate snapshot. You cannot verify an activity you submitted.</Typography>}
          <TextField label={decision?.kind === 'reject' ? 'Reason' : 'Comment (optional)'} required={decision?.kind === 'reject'} multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDecision(null)}>Cancel</Button>
          <LoadingButton variant="contained" color={decision?.kind === 'verify' ? 'success' : 'error'} loading={act.isPending}
            disabled={decision?.kind === 'reject' && text.trim().length < 3} onClick={() => decision && act.mutate({ a: decision.a, kind: decision.kind })}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
