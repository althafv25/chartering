import { Alert, Box, Card, CardContent, Divider, Grid, Stack, Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';
import { days, isNegative, money, pct, trimZeros } from '../../utils/decimal';
import { humanize } from '../../utils/format';
import type { ScenarioResult, TraceStep } from '../../types/chartering';

function Kpi({ label, value, tone }: { label: string; value: string; tone?: 'neg' | 'pos' }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary" fontWeight={600} sx={{ textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</Typography>
      <Typography variant="h6" color={tone === 'neg' ? 'error.main' : tone === 'pos' ? 'success.main' : undefined}>{value}</Typography>
    </Box>
  );
}

function Line({ label, value, strong, indent }: { label: string; value: string; strong?: boolean; indent?: boolean }) {
  return (
    <Stack direction="row" justifyContent="space-between" sx={{ py: 0.4, pl: indent ? 2 : 0 }}>
      <Typography fontSize={14} fontWeight={strong ? 700 : 400} color={indent ? 'text.secondary' : undefined}>{label}</Typography>
      <Typography fontSize={14} fontWeight={strong ? 700 : 500} sx={{ fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
    </Stack>
  );
}

/** Read-only presentation of backend results. No arithmetic is performed here. */
export function ScenarioResultsPanel({ result, currency, type }: { result: ScenarioResult; currency: string; type: string }) {
  const m = (v: string | null) => money(v, currency);
  const relet = type === 'cargo_relet';

  return (
    <Stack spacing={2}>
      {result.warnings.length > 0 && <Alert severity="warning">{result.warnings.map((w) => <div key={w}>{w}</div>)}</Alert>}
      <Card variant="outlined">
        <CardContent>
          <Grid container spacing={2}>
            <Grid size={{ xs: 6, md: 3 }}><Kpi label={relet ? 'Net relet margin' : 'Profit'} value={m(result.profit)} tone={isNegative(result.profit) ? 'neg' : 'pos'} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><Kpi label="TCE / day" value={m(result.tce_per_day)} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><Kpi label="Total days" value={days(result.total_days, 2)} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><Kpi label={`Break-even${result.breakeven_item ? ` (${result.breakeven_item})` : ''}`} value={result.breakeven_rate ? `${trimZeros(result.breakeven_rate)} ${currency}` : '—'} /></Grid>
          </Grid>
        </CardContent>
      </Card>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 6 }}>
          <Card variant="outlined"><CardContent>
            <Typography variant="subtitle2" gutterBottom>Profit & loss ({currency})</Typography>
            <Line label="Gross revenue" value={m(result.gross_revenue)} />
            <Line label="Commission" value={m(result.total_commission)} indent />
            <Line label="Net revenue" value={m(result.net_revenue)} strong />
            <Divider sx={{ my: 1 }} />
            <Line label="Fuel" value={m(result.fuel_cost)} indent />
            <Line label="Port" value={m(result.port_costs)} indent />
            <Line label="Agency" value={m(result.agency_costs)} indent />
            <Line label="Canal" value={m(result.canal_costs)} indent />
            <Line label="Other" value={m(result.other_costs)} indent />
            <Line label="Voyage costs (in TCE)" value={m(result.voyage_costs)} strong />
            {relet && <Line label="Tonnage / head charter" value={m(result.tonnage_cost)} indent />}
            <Line label="Operational (excl. from TCE)" value={m(result.operational_costs)} indent />
            <Line label="Total costs" value={m(result.total_costs)} strong />
            <Divider sx={{ my: 1 }} />
            <Line label={relet ? 'Net relet margin' : 'Profit'} value={m(result.profit)} strong />
            <Line label="Margin" value={pct(result.profit_margin_pct)} indent />
            <Line label="Profit / day" value={m(result.profit_per_day)} indent />
          </CardContent></Card>
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}>
          <Card variant="outlined"><CardContent>
            <Typography variant="subtitle2" gutterBottom>Time & distance</Typography>
            <Line label="Sea distance" value={`${trimZeros(result.sea_distance_nm)} NM`} />
            <Line label="of which ECA" value={`${trimZeros(result.eca_distance_nm)} NM`} indent />
            <Line label="Sea days (incl. margin)" value={days(result.sea_days, 3)} />
            <Line label="Port / offshore days" value={days(result.port_days, 3)} />
            <Line label="Total elapsed days" value={days(result.total_days, 3)} strong />
            <Divider sx={{ my: 1 }} />
            <Typography variant="subtitle2" gutterBottom>Fuel (MT)</Typography>
            <Table size="small">
              <TableHead><TableRow><TableCell>Fuel</TableCell><TableCell align="right">Sea</TableCell><TableCell align="right">Port</TableCell><TableCell align="right">DP</TableCell><TableCell align="right">Standby</TableCell><TableCell align="right">Total</TableCell><TableCell align="right">Cost</TableCell></TableRow></TableHead>
              <TableBody>
                {result.breakdown?.fuel.map((f) => (
                  <TableRow key={f.fuel_type_id}>
                    <TableCell>{f.code}{f.account === 'charterer' && <Typography component="span" variant="caption" color="text.secondary"> (chrt)</Typography>}</TableCell>
                    {[f.sea_mt, f.port_mt, f.dp_mt, f.standby_mt, f.total_mt].map((v, i) => <TableCell key={i} align="right">{trimZeros(v)}</TableCell>)}
                    <TableCell align="right">{money(f.cost)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent></Card>
        </Grid>
      </Grid>

      <Card variant="outlined"><CardContent>
        <Typography variant="subtitle2" gutterBottom>Revenue items</Typography>
        <Table size="small">
          <TableHead><TableRow><TableCell>Item</TableCell><TableCell>Basis</TableCell><TableCell align="right">Qty</TableCell><TableCell align="right">Rate</TableCell><TableCell align="right">Amount</TableCell><TableCell align="right">Comm. %</TableCell><TableCell align="right">Commission</TableCell></TableRow></TableHead>
          <TableBody>
            {result.breakdown?.revenue.map((r) => (
              <TableRow key={r.key}>
                <TableCell>{r.primary ? '★ ' : ''}{r.description}</TableCell><TableCell>{humanize(r.basis)}</TableCell>
                <TableCell align="right">{trimZeros(r.quantity)}</TableCell><TableCell align="right">{trimZeros(r.rate)} {r.currency}</TableCell>
                <TableCell align="right">{m(r.amount)}</TableCell><TableCell align="right">{trimZeros(r.commission_pct)}</TableCell><TableCell align="right">{m(r.commission)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {Object.values(result.breakdown?.charterer_account ?? {}).some((v) => v !== '0.00') && (
          <Typography variant="body2" color="text.secondary" sx={{ mt: 1.5 }}>
            Charterer's account (not in P&L): {Object.entries(result.breakdown!.charterer_account).filter(([, v]) => v !== '0.00').map(([k, v]) => `${k} ${m(v)}`).join(' · ')}
          </Typography>
        )}
      </CardContent></Card>

      <Typography variant="caption" color="text.secondary">
        Calculated {new Date(result.calculated_at).toLocaleString()} · engine v{result.calculation_version} · inputs {result.inputs_hash.slice(0, 12)}…
      </Typography>
    </Stack>
  );
}

export function TraceTable({ steps }: { steps: TraceStep[] }) {
  return (
    <Table size="small">
      <TableHead><TableRow><TableCell width={140}>Ref</TableCell><TableCell>Step</TableCell><TableCell>Formula (exact values)</TableCell><TableCell align="right">Result</TableCell></TableRow></TableHead>
      <TableBody>
        {steps.map((s, i) => (
          <TableRow key={`${s.ref}-${i}`}>
            <TableCell><Typography fontFamily="monospace" fontSize={12}>{s.ref}</Typography></TableCell>
            <TableCell>{s.label}</TableCell>
            <TableCell><Typography fontFamily="monospace" fontSize={12} sx={{ wordBreak: 'break-word' }}>{s.formula}</Typography></TableCell>
            <TableCell align="right"><Typography fontFamily="monospace" fontSize={12}>{s.value ?? '—'}</Typography></TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
