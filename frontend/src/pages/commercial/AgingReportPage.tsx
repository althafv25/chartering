import { useState, Fragment } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Box, Card, Collapse, IconButton, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import ExpandMore from '@mui/icons-material/ExpandMore';
import ExpandLess from '@mui/icons-material/ExpandLess';
import { agingApi } from '../../api/finance';
import { PageHeader } from '../../components/PageHeader';
import { ErrorState, SectionLoader, EmptyState } from '../../components/Feedback';
import { money } from '../../utils/decimal';
import type { AgingCustomer } from '../../types/finance';

const BUCKETS: { key: keyof AgingCustomer['buckets']; label: string }[] = [
  { key: 'current', label: 'Current' },
  { key: '1_30', label: '1–30 days' },
  { key: '31_60', label: '31–60 days' },
  { key: '61_90', label: '61–90 days' },
  { key: 'over_90', label: '> 90 days' },
];

const MAX_DETAIL_ROWS = 500;

export default function AgingReportPage() {
  const [asOf, setAsOf] = useState('');
  const [expanded, setExpanded] = useState<number | null>(null);
  const report = useQuery({ queryKey: ['receivables-aging', asOf], queryFn: () => agingApi.report(asOf || undefined) });

  return (
    <>
      <PageHeader title="Receivables Aging" subtitle="Outstanding invoice balances grouped by customer and days overdue (on due date)." breadcrumbs={[{ label: 'Commercial' }, { label: 'Aging' }]} />
      <Card>
        <Stack direction="row" spacing={2} sx={{ p: 2, pb: 0 }} alignItems="center">
          <TextField type="date" size="small" label="As of" value={asOf} onChange={(e) => setAsOf(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: 200 }} />
          {report.data && <Typography variant="body2" color="text.secondary">Showing balances as of {report.data.as_of}, in {report.data.base_currency}</Typography>}
        </Stack>

        {report.isLoading ? <SectionLoader /> : report.isError ? <ErrorState error={report.error} onRetry={() => report.refetch()} /> : (
          report.data!.customers.length === 0 ? <EmptyState title="No outstanding receivables" description="Every issued invoice has been fully paid as of this date." /> : (
            <Box sx={{ overflowX: 'auto' }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell />
                    <TableCell>Customer</TableCell>
                    {BUCKETS.map((b) => <TableCell key={b.key} align="right">{b.label}</TableCell>)}
                    <TableCell align="right">Total</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {report.data!.customers.map((c) => (
                    <Fragment key={c.customer_company_id}>
                      <TableRow hover sx={{ cursor: 'pointer' }} onClick={() => setExpanded(expanded === c.customer_company_id ? null : c.customer_company_id)}>
                        <TableCell sx={{ width: 36 }}><IconButton size="small">{expanded === c.customer_company_id ? <ExpandLess fontSize="small" /> : <ExpandMore fontSize="small" />}</IconButton></TableCell>
                        <TableCell><Typography fontWeight={600} fontSize={14}>{c.customer_name}</Typography></TableCell>
                        {BUCKETS.map((b) => <TableCell key={b.key} align="right">{money(c.buckets[b.key])}</TableCell>)}
                        <TableCell align="right"><Typography fontWeight={600}>{money(c.total)}</Typography></TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell colSpan={BUCKETS.length + 3} sx={{ p: 0, border: expanded === c.customer_company_id ? undefined : 'none' }}>
                          <Collapse in={expanded === c.customer_company_id} unmountOnExit>
                            <AgingInvoices asOf={asOf} customerId={c.customer_company_id} baseCurrency={report.data!.base_currency} />
                          </Collapse>
                        </TableCell>
                      </TableRow>
                    </Fragment>
                  ))}
                </TableBody>
                <TableBody>
                  <TableRow sx={{ '& td': { fontWeight: 700, borderTop: 2, borderColor: 'divider' } }}>
                    <TableCell colSpan={2}>Total</TableCell>
                    {BUCKETS.map((b) => <TableCell key={b.key} align="right">{money(report.data!.totals[b.key])}</TableCell>)}
                    <TableCell align="right">{money(report.data!.totals.grand_total)}</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </Box>
          )
        )}
      </Card>
    </>
  );
}

/** Loaded only when a customer row is expanded, so the overview stays small however many invoices are open. */
function AgingInvoices({ asOf, customerId, baseCurrency }: { asOf: string; customerId: number; baseCurrency: string }) {
  const navigate = useNavigate();
  const detail = useQuery({ queryKey: ['receivables-aging', asOf, customerId], queryFn: () => agingApi.report(asOf || undefined, customerId) });
  if (detail.isLoading) return <SectionLoader />;
  if (detail.isError) return <ErrorState error={detail.error} onRetry={() => detail.refetch()} />;
  const invoices = detail.data?.customers[0]?.invoices ?? [];

  return (
    <Box sx={{ p: 2, bgcolor: 'action.hover' }}>
      <Table size="small">
        <TableHead><TableRow><TableCell>Invoice</TableCell><TableCell>Due date</TableCell><TableCell align="right">Days overdue</TableCell><TableCell>Bucket</TableCell><TableCell align="right">Balance</TableCell><TableCell align="right">Base balance</TableCell></TableRow></TableHead>
        <TableBody>
          {invoices.map((inv) => (
            <TableRow key={inv.id} hover sx={{ cursor: 'pointer' }} onClick={() => navigate(`/commercial/invoices/${inv.id}`)}>
              <TableCell>{inv.invoice_number ?? `#${inv.id}`}</TableCell>
              <TableCell>{inv.due_date}</TableCell>
              <TableCell align="right">{inv.days_overdue}</TableCell>
              <TableCell>{BUCKETS.find((b) => b.key === inv.bucket)?.label ?? inv.bucket}</TableCell>
              <TableCell align="right">{money(inv.balance, inv.currency)}</TableCell>
              <TableCell align="right">{money(inv.base_balance, baseCurrency)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {invoices.length >= MAX_DETAIL_ROWS && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>Showing the {MAX_DETAIL_ROWS} oldest invoices. Use the Outstanding invoices report for the full list.</Typography>}
    </Box>
  );
}
