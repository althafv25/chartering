import { useQuery } from '@tanstack/react-query';
import { Box, Stack, Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';
import { voyageFinancialsApi } from '../../api/finance';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { isNegative, money, pct } from '../../utils/decimal';

/** Voyage P&L: estimate (initial snapshot) vs actual (confirmed ledger lines), base currency. */
export function FinancialsPanel({ voyageId }: { voyageId: number }) {
  const q = useQuery({ queryKey: ['voyages', voyageId, 'financials'], queryFn: () => voyageFinancialsApi.get(voyageId) });
  if (q.isLoading) return <SectionLoader />;
  if (q.isError || !q.data) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const f = q.data;
  const cur = f.base_currency;

  return (
    <Box sx={{ p: 2 }}>
      <Table size="small">
        <TableHead><TableRow><TableCell>Line</TableCell><TableCell align="right">Estimate</TableCell><TableCell align="right">Actual</TableCell><TableCell align="right">Variance</TableCell><TableCell align="right">%</TableCell></TableRow></TableHead>
        <TableBody>
          {f.rows.map((r) => (
            <TableRow key={r.metric} sx={r.metric === 'net_profit' ? { '& td': { fontWeight: 700 } } : undefined}>
              <TableCell>{r.label}</TableCell>
              <TableCell align="right">{money(r.estimate)}</TableCell>
              <TableCell align="right">{money(r.actual)}</TableCell>
              <TableCell align="right"><Typography component="span" fontSize={14} color={isNegative(r.variance) ? 'error.main' : undefined}>{money(r.variance)}</Typography></TableCell>
              <TableCell align="right">{pct(r.variance_pct)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <Box sx={{ mt: 2 }}>
        <KeyValueGrid columns={4} items={[
          ['Gross profit (before commission)', money(f.gross_profit, cur)], ['Net margin', pct(f.margin_pct)],
          ['Voyage days', f.total_days], ['Net profit / day', money(f.profit_per_day, cur)],
        ]} />
      </Box>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={4} sx={{ mt: 3 }}>
        {([['Revenue by category', f.revenue_by_category], ['Expenses by category', f.expense_by_category]] as const).map(([title, rows]) => (
          <Box key={title} sx={{ flex: 1 }}>
            <Typography variant="subtitle2" sx={{ mb: 0.5 }}>{title}</Typography>
            {rows.length === 0 ? <Typography variant="body2" color="text.secondary">None yet.</Typography> : rows.map((r) => (
              <Stack key={r.category} direction="row" justifyContent="space-between"><Typography fontSize={14}>{r.category}</Typography><Typography fontSize={14}>{money(r.amount, cur)}</Typography></Stack>
            ))}
          </Box>
        ))}
      </Stack>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 2 }}>
        {f.notes.join(' ')}{!f.estimate_available ? ' No initial snapshot estimate is available for this voyage.' : ''}
      </Typography>
    </Box>
  );
}
