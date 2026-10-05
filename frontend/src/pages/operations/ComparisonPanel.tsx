import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, DialogActions, DialogContent, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import CameraAltOutlined from '@mui/icons-material/CameraAltOutlined';
import { voyagesApi } from '../../api/operations';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime } from '../../utils/format';
import type { OpsVoyage } from '../../types/operations';
import { formatMetric } from './formatMetric';

export function ComparisonPanel({ voyage }: { voyage: OpsVoyage }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [dialog, setDialog] = useState(false);
  const [name, setName] = useState('');
  const cmp = useQuery({ queryKey: ['voyages', voyage.id, 'comparison'], queryFn: () => voyagesApi.comparison(voyage.id) });
  const snap = useMutation({
    mutationFn: () => voyagesApi.snapshot(voyage.id, name),
    onSuccess: (r) => { notify.success(r.message); setDialog(false); setName(''); qc.invalidateQueries({ queryKey: ['voyages', voyage.id] }); },
    onError: (e) => notify.error(e),
  });
  const canSnap = can(P.VoyagesUpdate) && !['draft', 'cancelled', 'finalized'].includes(voyage.status);

  if (cmp.isLoading) return <SectionLoader />;
  if (cmp.isError || !cmp.data) return <ErrorState error={cmp.error} onRetry={() => cmp.refetch()} />;
  const { columns, rows, currency } = cmp.data;

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={1} sx={{ mb: 1.5 }}>
        <Alert severity="info" sx={{ flex: 1 }}>
          Days, port time, distance and fuel are actuals (voyage dates, port call ATA/ATD, verified captain reports, off-hire). Financial lines repeat the estimate until actual revenue and expenses are recorded.
        </Alert>
        {canSnap && <Button variant="outlined" startIcon={<CameraAltOutlined />} sx={{ alignSelf: 'flex-start' }} onClick={() => setDialog(true)}>Save milestone snapshot</Button>}
      </Stack>
      <Box sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Metric</TableCell>
              {columns.map((c) => (
                <TableCell key={c.key} align="right" colSpan={c.key === 'initial' ? 1 : 2}>
                  <Typography fontSize={14} fontWeight={700}>{c.label}</Typography>
                  <Typography variant="caption" color="text.secondary">{c.type === 'current' ? 'live' : formatDateTime(c.created_at)}</Typography>
                </TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((r) => (
              <TableRow key={r.metric}>
                <TableCell>{r.label}{r.unit === 'money' ? ` (${currency})` : ''}{r.financial && <Typography component="span" variant="caption" color="text.secondary"> · est.</Typography>}</TableCell>
                {columns.map((c) => {
                  const variance = r.variance[c.key];
                  return [
                    <TableCell key={`${c.key}-v`} align="right" sx={{ fontVariantNumeric: 'tabular-nums' }}>{formatMetric(r, r.values[c.key])}</TableCell>,
                    c.key !== 'initial' && (
                      <TableCell key={`${c.key}-d`} align="right" sx={{ fontVariantNumeric: 'tabular-nums', color: 'text.secondary', fontSize: 12 }}>
                        {variance === null ? '' : formatMetric(r, variance, true)}
                      </TableCell>
                    ),
                  ];
                })}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Box>
      <Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 1 }}>Smaller figures to the right of each value show the difference from the initial estimate.</Typography>

      <Dialog open={dialog} onClose={() => setDialog(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Save milestone snapshot</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" sx={{ mb: 2 }}>Freezes the current figures under a name so they can be compared later. Snapshots cannot be edited.</Typography>
          <TextField label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. After loading" />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDialog(false)}>Cancel</Button>
          <LoadingButton variant="contained" loading={snap.isPending} disabled={name.trim().length < 2} onClick={() => snap.mutate()}>Save</LoadingButton>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
