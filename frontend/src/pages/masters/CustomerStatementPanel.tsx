import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Box, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import { reportsApi } from '../../api/finance';
import { EmptyState, ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { groupDigits } from '../../utils/decimal';

const NUMERIC = /^-?\d+(\.\d+)?$/;

/** Statement of account for one customer, from the customer-statement report. Amounts are in base currency. */
export function CustomerStatementPanel({ companyId }: { companyId: number }) {
  const { can } = useAuth();
  const notify = useNotify();
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const params = { customer_company_id: String(companyId), from: from || undefined, to: to || undefined };
  const q = useQuery({ queryKey: ['reports', 'customer-statement', params], queryFn: () => reportsApi.run('customer-statement', params) });
  const exportAs = useMutation({
    mutationFn: (format: 'csv' | 'xlsx' | 'pdf') => reportsApi.download('customer-statement', format, params),
    onError: (e) => notify.error(e),
  });

  const data = q.data;
  const closing = data && data.rows.length > 0 ? data.rows[data.rows.length - 1].balance : undefined;

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ md: 'center' }} sx={{ mb: 2 }}>
        <TextField type="date" size="small" label="From" value={from} onChange={(e) => setFrom(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
        <TextField type="date" size="small" label="To" value={to} onChange={(e) => setTo(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
        <Box sx={{ flex: 1 }} />
        {data && <Typography variant="body2" color="text.secondary">Closing balance {closing !== undefined ? groupDigits(closing) : '—'} {data.base_currency}</Typography>}
        {can(P.ReportsExport) && (['csv', 'xlsx', 'pdf'] as const).map((format) => (
          <LoadingButton key={format} variant="outlined" startIcon={<DownloadOutlined />} loading={exportAs.isPending} onClick={() => exportAs.mutate(format)}>
            {format === 'xlsx' ? 'Excel' : format.toUpperCase()}
          </LoadingButton>
        ))}
      </Stack>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
        Invoices issued and receipts recorded for this customer. A positive balance is owed by the customer. Leave the dates empty for the full history.
      </Typography>
      {q.isLoading ? <SectionLoader /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : !data || data.rows.length === 0 ? (
        <EmptyState title="No statement lines" description="No invoices or receipts match these dates." />
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>{data.columns.map((c) => <TableCell key={c.key} align={c.align as 'left' | 'right'}>{c.label}</TableCell>)}</TableRow>
            </TableHead>
            <TableBody>
              {data.rows.map((row, i) => {
                const summary = row.type === 'Opening balance' || row.type === 'Closing balance';
                return (
                  <TableRow key={i} hover sx={summary ? { '& td': { fontWeight: 600 } } : undefined}>
                    {data.columns.map((c) => {
                      const v = row[c.key];
                      const shown = v === null || v === undefined || v === '' ? '—' : c.align === 'right' && NUMERIC.test(v) ? groupDigits(v) : v;
                      return <TableCell key={c.key} align={c.align as 'left' | 'right'}>{shown}</TableCell>;
                    })}
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Box>
      )}
      {data?.truncated && <Typography variant="body2" color="warning.main" sx={{ mt: 1 }}>Only the first {data.row_limit.toLocaleString()} lines are shown. Narrow the dates to see the rest.</Typography>}
    </Box>
  );
}
