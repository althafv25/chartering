import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Box, Card, Stack, Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs, TextField, Typography } from '@mui/material';
import { balancingApi } from '../../api/finance';
import { PageHeader } from '../../components/PageHeader';
import { CompanyAutocomplete } from '../../components/MasterPickers';
import { ErrorState, SectionLoader, EmptyState } from '../../components/Feedback';
import { money } from '../../utils/decimal';
import type { CashFlowPeriod } from '../../types/finance';

export default function BalancingPage() {
  const [tab, setTab] = useState<'accounts' | 'cashflow'>('accounts');
  const [companyId, setCompanyId] = useState<number | null>(null);

  return (
    <>
      <PageHeader title="Balancing" subtitle="Receivable and payable positions per account, and a cash-flow projection by due period." breadcrumbs={[{ label: 'Commercial' }, { label: 'Balancing' }]} />
      <Card>
        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ pr: 2 }}>
          <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
            <Tab value="accounts" label="Account view" /><Tab value="cashflow" label="Cash-flow / calendar" />
          </Tabs>
          <Box sx={{ width: 260 }}><CompanyAutocomplete label="Filter by company" value={companyId} onChange={(id) => setCompanyId(id)} /></Box>
        </Stack>
        {tab === 'accounts' ? <AccountsView companyId={companyId} /> : <CashFlowView companyId={companyId} />}
      </Card>
    </>
  );
}

function AccountsView({ companyId }: { companyId: number | null }) {
  const q = useQuery({ queryKey: ['balancing-accounts', companyId], queryFn: () => balancingApi.accounts({ company_id: companyId ?? undefined }) });

  if (q.isLoading) return <SectionLoader />;
  if (q.isError || !q.data) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (q.data.accounts.length === 0) return <EmptyState title="No open balances" description="Every receivable and payable account is settled." />;

  return (
    <Box sx={{ overflowX: 'auto' }}>
      <Table size="small">
        <TableHead>
          <TableRow>
            <TableCell>Account</TableCell>
            <TableCell align="right">Receivable</TableCell>
            <TableCell align="right">Payable</TableCell>
            <TableCell align="right">Net</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {q.data.accounts.map((a) => (
            <TableRow key={a.company_id} hover>
              <TableCell>
                <Typography fontWeight={600} fontSize={14}>{a.company_name ?? `#${a.company_id}`}</Typography>
                <Typography variant="caption" color="text.secondary">{a.receivable_count} invoice(s) · {a.payable_count} payable(s)</Typography>
              </TableCell>
              <TableCell align="right">{money(a.receivable)}</TableCell>
              <TableCell align="right">{money(a.payable)}</TableCell>
              <TableCell align="right">
                <Typography fontWeight={600} color={a.net.startsWith('-') ? 'error.main' : 'success.main'}>{money(a.net)}</Typography>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
        <TableBody>
          <TableRow sx={{ '& td': { fontWeight: 700, borderTop: 2, borderColor: 'divider' } }}>
            <TableCell>Total ({q.data.base_currency})</TableCell>
            <TableCell align="right">{money(q.data.totals.receivable)}</TableCell>
            <TableCell align="right">{money(q.data.totals.payable)}</TableCell>
            <TableCell align="right">{money(q.data.totals.net)}</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Box>
  );
}

function CashFlowView({ companyId }: { companyId: number | null }) {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const q = useQuery({
    queryKey: ['balancing-cashflow', companyId, from, to],
    queryFn: () => balancingApi.cashFlow({ company_id: companyId ?? undefined, from: from || undefined, to: to || undefined }),
  });

  const maxAbs = useMemo(() => {
    if (!q.data) return 1;
    const vals = q.data.periods.flatMap((p) => [Number(p.receivable), Number(p.payable)]);
    return Math.max(1, ...vals);
  }, [q.data]);

  if (q.isLoading) return <SectionLoader />;
  if (q.isError || !q.data) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;

  const bar = (value: string, color: string) => {
    const pct = Math.min(100, (Math.abs(Number(value)) / maxAbs) * 100);
    return <Box sx={{ height: 10, borderRadius: 1, bgcolor: color, width: `${pct}%`, minWidth: Number(value) !== 0 ? 4 : 0 }} />;
  };

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction="row" spacing={2} sx={{ mb: 2 }}>
        <TextField type="date" size="small" label="From" value={from} onChange={(e) => setFrom(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
        <TextField type="date" size="small" label="To" value={to} onChange={(e) => setTo(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
        <Typography variant="body2" color="text.secondary" sx={{ alignSelf: 'center' }}>Amounts in {q.data.base_currency}</Typography>
      </Stack>

      <Stack spacing={1.5} sx={{ mb: 3 }}>
        {q.data.periods.map((p: CashFlowPeriod) => (
          <Box key={p.period}>
            <Stack direction="row" justifyContent="space-between" sx={{ mb: 0.5 }}>
              <Typography fontSize={13} fontWeight={600}>{p.label}</Typography>
              <Typography fontSize={13} color="text.secondary">
                Receivable {money(p.receivable)} · Payable {money(p.payable)} · Net <Box component="span" fontWeight={700} color={p.net.startsWith('-') ? 'error.main' : 'success.main'}>{money(p.net)}</Box>
              </Typography>
            </Stack>
            <Stack spacing={0.5}>
              {bar(p.receivable, 'success.main')}
              {bar(p.payable, 'error.main')}
            </Stack>
          </Box>
        ))}
      </Stack>

      <Table size="small">
        <TableHead><TableRow><TableCell>Period</TableCell><TableCell align="right">Receivable</TableCell><TableCell align="right">Payable</TableCell><TableCell align="right">Net</TableCell></TableRow></TableHead>
        <TableBody>
          {q.data.periods.map((p) => (
            <TableRow key={p.period}>
              <TableCell>{p.label}</TableCell>
              <TableCell align="right">{money(p.receivable)}</TableCell>
              <TableCell align="right">{money(p.payable)}</TableCell>
              <TableCell align="right">{money(p.net)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
        <TableBody>
          <TableRow sx={{ '& td': { fontWeight: 700, borderTop: 2, borderColor: 'divider' } }}>
            <TableCell>Total</TableCell>
            <TableCell align="right">{money(q.data.totals.receivable)}</TableCell>
            <TableCell align="right">{money(q.data.totals.payable)}</TableCell>
            <TableCell align="right">{money(q.data.totals.net)}</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Box>
  );
}
