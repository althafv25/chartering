import { useQuery } from '@tanstack/react-query';
import { Alert, Box, Chip, Stack, Table, TableBody, TableCell, TableHead, TableRow, Tooltip, Typography } from '@mui/material';
import { bunkersApi } from '../../api/bunkers';
import { EmptyState, ErrorState, SectionLoader } from '../../components/Feedback';
import { formatDateTime, humanize } from '../../utils/format';
import { groupDigits } from '../../utils/decimal';

const v = (x: string | null) => (x === null ? '—' : groupDigits(x));

/** ROB ledger (BK-01…03). All figures and flags are computed by the API from verified captain reports. */
export function RobLedgerPanel({ voyageId }: { voyageId: number }) {
  const ledger = useQuery({ queryKey: ['rob-ledger', voyageId], queryFn: () => bunkersApi.robLedger(voyageId) });
  if (ledger.isLoading) return <SectionLoader />;
  if (ledger.isError || !ledger.data) return <ErrorState error={ledger.error} onRetry={() => ledger.refetch()} />;
  const { fuels, threshold_pct, estimate_basis } = ledger.data;
  if (fuels.length === 0) return <Box sx={{ p: 2 }}><EmptyState title="No ROB data" description="The ledger is built from verified captain reports with fuel lines." /></Box>;

  return (
    <Box sx={{ p: 2 }}>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
        Opening = previous reported ROB; closing = opening + received − consumed. Consumption is flagged above ±{threshold_pct} % of the estimate
        {estimate_basis ? ' (initial estimate fuel per day × period days)' : ' — no estimate available'}.
      </Typography>
      {fuels.map((f) => (
        <Box key={f.fuel_type_id} sx={{ mb: 3 }}>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }} flexWrap="wrap" useFlexGap>
            <Typography variant="subtitle1" fontWeight={700}>{f.fuel_code} — {f.fuel_name}</Typography>
            <Chip size="small" label={`consumed ${v(f.totals.consumed_mt)} mt`} />
            <Chip size="small" label={`received ${v(f.totals.received_mt)} mt`} />
            <Chip size="small" label={`stems ${v(f.totals.stems_mt)} mt`} />
            {f.totals.estimated_mt && <Chip size="small" variant="outlined" label={`estimate ${v(f.totals.estimated_mt)} mt`} />}
            {f.flags.rob_discontinuity > 0 && <Chip size="small" color="error" label={`${f.flags.rob_discontinuity} ROB discontinuit${f.flags.rob_discontinuity === 1 ? 'y' : 'ies'}`} />}
            {f.flags.consumption > 0 && <Chip size="small" color="warning" label={`${f.flags.consumption} consumption flag(s)`} />}
            {f.flags.received_mismatch > 0 && <Chip size="small" color="warning" label={`${f.flags.received_mismatch} received ≠ stems`} />}
          </Stack>
          {f.stems_outside_reports_mt !== '0.000' && <Alert severity="info" sx={{ mb: 1 }}>{v(f.stems_outside_reports_mt)} mt delivered by stems falls outside the reported periods.</Alert>}
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Period end (UTC)</TableCell><TableCell align="right">Opening</TableCell><TableCell align="right">Received</TableCell><TableCell align="right">Consumed</TableCell>
                  <TableCell align="right">Closing</TableCell><TableCell align="right">Reported ROB</TableCell><TableCell align="right">Estimate</TableCell><TableCell align="right">Variance</TableCell><TableCell align="right">Stems</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {f.rows.map((r) => (
                  <TableRow key={r.report_id}>
                    <TableCell>{formatDateTime(r.period_end)}<Typography variant="caption" display="block" color="text.secondary">{humanize(r.report_type)}{r.period_start ? '' : ' · baseline'}</Typography></TableCell>
                    <TableCell align="right">{v(r.opening_mt)}</TableCell>
                    <TableCell align="right">{r.period_start ? v(r.received_mt) : '—'}</TableCell>
                    <TableCell align="right">{r.period_start ? v(r.consumed_mt) : '—'}</TableCell>
                    <TableCell align="right">{v(r.closing_mt)}</TableCell>
                    <TableCell align="right" sx={{ color: r.rob_discontinuity ? 'error.main' : undefined, fontWeight: r.rob_discontinuity ? 700 : undefined }}>
                      {r.rob_discontinuity ? <Tooltip title={`Differs from computed closing by ${r.rob_difference_mt} mt`}><span>{v(r.reported_rob_mt)} ⚠</span></Tooltip> : v(r.reported_rob_mt)}
                    </TableCell>
                    <TableCell align="right">{v(r.estimated_consumed_mt)}</TableCell>
                    <TableCell align="right" sx={{ color: r.flagged ? 'warning.main' : undefined, fontWeight: r.flagged ? 700 : undefined }}>
                      {r.variance_mt === null ? '—' : `${v(r.variance_mt)}${r.variance_pct ? ` (${r.variance_pct} %)` : ''}`}
                    </TableCell>
                    <TableCell align="right" sx={{ color: r.received_mismatch ? 'warning.main' : undefined }}>{v(r.stems_delivered_mt)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </Box>
      ))}
    </Box>
  );
}
