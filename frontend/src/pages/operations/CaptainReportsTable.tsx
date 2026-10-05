import { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, Button, Checkbox, DialogActions, DialogContent, FormControlLabel, IconButton, Stack, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import AddIcon from '@mui/icons-material/Add';
import FactCheckOutlined from '@mui/icons-material/FactCheckOutlined';
import { captainReportsApi } from '../../api/operations';
import { DataTable, type Column } from '../../components/DataTable';
import { ErrorState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { FormLayout, FormSection } from '../../components/FormSection';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { REPORT_TYPES } from '../../constants/operations';
import { labelOf } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime } from '../../utils/format';
import { groupDigits } from '../../utils/decimal';
import type { CaptainReport } from '../../types/operations';
import { CaptainReportDialog } from './CaptainReportDialog';

interface Props {
  filters: Record<string, string | number | undefined>;
  /** When shown inside a voyage. */
  vesselId?: number;
  voyageId?: number;
  readOnly?: boolean;
  showVessel?: boolean;
  openReportRequest?: number;
}

export function CaptainReportsTable({ filters, vesselId, voyageId, readOnly, showVessel, openReportRequest }: Props) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<CaptainReport | null | undefined>(undefined);
  const [decision, setDecision] = useState<{ r: CaptainReport; kind: 'verify' | 'reject' } | null>(null);
  const [text, setText] = useState('');
  const [apply, setApply] = useState(true);
  const q = { page, per_page: 15, ...filters };
  const list = useQuery({ queryKey: ['captain-reports', q], queryFn: () => captainReportsApi.list(q), placeholderData: keepPreviousData });
  const refresh = () => { qc.invalidateQueries({ queryKey: ['captain-reports'] }); qc.invalidateQueries({ queryKey: ['voyages'] }); };

  const act = useMutation({
    mutationFn: ({ r, kind }: { r: CaptainReport; kind: 'submit' | 'verify' | 'reject' | 'delete' }): Promise<{ message: string }> =>
      kind === 'submit' ? captainReportsApi.submit(r.id)
        : kind === 'verify' ? captainReportsApi.verify(r.id, { comment: text || null, apply_to_port_call: apply })
          : kind === 'reject' ? captainReportsApi.reject(r.id, text) : captainReportsApi.remove(r.id),
    onSuccess: (r) => { notify.success(r.message); setDecision(null); setText(''); refresh(); },
    onError: (e) => notify.error(e),
  });

  const canEdit = !readOnly && can(P.CaptainReportsUpdate);
  const canVerify = !readOnly && can(P.CaptainReportsVerify);
  const canCreate = !readOnly && can(P.CaptainReportsCreate);
  useEffect(() => {
    if (openReportRequest && canCreate) setEditing(null);
  }, [canCreate, openReportRequest]);
  const columns: Column<CaptainReport>[] = [
    { key: 'at', header: 'Reported (UTC)', render: (r) => <Box><Typography fontSize={14} fontWeight={600}>{formatDateTime(r.reported_at)}</Typography><Typography variant="caption" color="text.secondary">{labelOf(REPORT_TYPES, r.report_type)}</Typography></Box> },
    ...(showVessel ? [{ key: 'vessel', header: 'Vessel / voyage', render: (r: CaptainReport) => <Box><Typography fontSize={14}>{r.vessel?.name}</Typography><Typography variant="caption" color="text.secondary">{r.voyage?.voyage_number ?? 'No voyage'}</Typography></Box> }] : []),
    { key: 'call', header: 'Port call', hideBelow: 'md', render: (r) => r.port_call?.label ?? '—' },
    { key: 'dist', header: 'Distance', align: 'right', render: (r) => (r.distance_since_last_nm ? `${groupDigits(r.distance_since_last_nm)} nm` : '—') },
    { key: 'speed', header: 'Speed', align: 'right', hideBelow: 'md', render: (r) => (r.speed_kn ? `${r.speed_kn} kn` : '—') },
    { key: 'fuel', header: 'Fuel consumed', hideBelow: 'md', render: (r) => r.fuel_lines?.filter((l) => Number(l.consumed_mt) > 0).map((l) => `${l.fuel_code} ${groupDigits(l.consumed_mt)}`).join(' · ') || '—' },
    { key: 'status', header: 'Status', render: (r) => <StatusChip status={r.status} /> },
    { key: 'actions', header: '', align: 'right', render: (r) => (
      <Stack direction="row" spacing={0.5} justifyContent="flex-end" onClick={(e) => e.stopPropagation()}>
        {canEdit && r.is_editable && <Button size="small" onClick={() => act.mutate({ r, kind: 'submit' })}>Submit</Button>}
        {canVerify && r.status === 'submitted' && <Button size="small" color="success" onClick={() => { setApply(true); setDecision({ r, kind: 'verify' }); }}>Verify</Button>}
        {canVerify && r.status === 'submitted' && <Button size="small" color="error" onClick={() => setDecision({ r, kind: 'reject' })}>Reject</Button>}
        {canEdit && r.is_editable && <Tooltip title="Edit"><IconButton size="small" aria-label="Edit report" onClick={() => setEditing(r)}><EditOutlined fontSize="small" /></IconButton></Tooltip>}
        {canEdit && r.status === 'draft' && <Tooltip title="Delete"><IconButton size="small" color="error" aria-label="Delete report" onClick={() => act.mutate({ r, kind: 'delete' })}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
      </Stack>
    ) },
  ];

  return (
    <>
      {vesselId && canCreate && <Stack direction="row" justifyContent="flex-end" sx={{ p: 2 }}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing(null)}>New report</Button></Stack>}
      {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
        <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(r) => r.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
          emptyTitle="No captain reports" emptyDescription="Only verified reports count towards actual distance and fuel." />
      )}
      <CaptainReportDialog open={editing !== undefined} report={editing ?? null} vesselId={vesselId} voyageId={voyageId} onClose={() => setEditing(undefined)} />

      <Dialog open={!!decision} onClose={() => setDecision(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{decision?.kind === 'verify' ? 'Verify report' : 'Reject report'}</DialogTitle>
        <DialogContent dividers>
          <FormLayout><FormSection title={decision?.kind === 'verify' ? 'Verification' : 'Rejection reason'} icon={<FactCheckOutlined />}>
            {decision?.kind === 'verify' && decision.r.port_call_id && ['arrival', 'departure'].includes(decision.r.report_type) && (
              <FormControlLabel sx={{ mb: 1 }} control={<Checkbox checked={apply} onChange={(e) => setApply(e.target.checked)} />}
                label={`Set the port call ${decision.r.report_type === 'arrival' ? 'ATA' : 'ATD'} from this report`} />
            )}
            <TextField label={decision?.kind === 'reject' ? 'Reason' : 'Comment (optional)'} required={decision?.kind === 'reject'} multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} />
          </FormSection></FormLayout>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button variant="outlined" onClick={() => setDecision(null)}>Cancel</Button>
          <LoadingButton variant="contained" color={decision?.kind === 'verify' ? 'success' : 'error'} loading={act.isPending}
            disabled={decision?.kind === 'reject' && text.trim().length < 3} onClick={() => decision && act.mutate({ r: decision.r, kind: decision.kind })}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>
    </>
  );
}
