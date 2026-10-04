import { Box, Button, Grid, IconButton, MenuItem, Stack, Switch, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { useQuery } from '@tanstack/react-query';
import { referenceApi } from '../../api/masters';
import { CurrencySelect } from '../../components/MasterPickers';
import { CONSUMPTION_MODES } from '../../constants/masters';
import { COST_BASES, RATE_BASES } from '../../constants/chartering';
import { isDecimal } from '../../utils/decimal';
import { humanize } from '../../utils/format';
import type { Account, CallInput, CostItem, Money, RevenueItem, ScenarioInputs } from '../../types/chartering';
import type { ReferenceItem } from '../../types/masters';

type Patch = (fn: (draft: ScenarioInputs) => void) => void;

/** Small decimal input: value is kept as string; shows an error for malformed values (API validates authoritatively). */
function Dec({ label, value, onChange, scale = 4, disabled, width, required }: {
  label: string; value: string | null | undefined; onChange: (v: string | null) => void; scale?: number; disabled?: boolean; width?: number; required?: boolean;
}) {
  const v = value ?? '';
  const bad = v !== '' && !isDecimal(v, scale);
  return (
    <TextField size="small" label={label} value={v} disabled={disabled} required={required} inputMode="decimal" error={bad || (required && v === '')}
      helperText={bad ? `max ${scale} decimals` : undefined} onChange={(e) => onChange(e.target.value.trim() === '' ? null : e.target.value.trim())}
      sx={width ? { width } : undefined} />
  );
}

function AccountSelect({ value, onChange, disabled }: { value: Account; onChange: (a: Account) => void; disabled?: boolean }) {
  return (
    <TextField select size="small" label="Account" value={value} disabled={disabled} onChange={(e) => onChange(e.target.value as Account)} sx={{ minWidth: 120 }}>
      <MenuItem value="owner">Owner</MenuItem><MenuItem value="charterer">Charterer</MenuItem>
    </TextField>
  );
}

function Section({ title, hint, action, children }: { title: string; hint?: string; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <Box sx={{ mb: 3 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1 }}>
        <Box>
          <Typography variant="subtitle2">{title}</Typography>
          {hint && <Typography variant="caption" color="text.secondary">{hint}</Typography>}
        </Box>
        {action}
      </Stack>
      {children}
    </Box>
  );
}

function MoneyFields({ label, money, currency, onChange, disabled }: { label: string; money: Money | null; currency: string; onChange: (m: Money) => void; disabled?: boolean }) {
  const m: Money = money ?? { amount: '0', currency, fx_rate: '1', account: 'owner' };
  return (
    <Stack direction="row" spacing={1} alignItems="flex-start" flexWrap="wrap" useFlexGap>
      <Dec label={label} value={m.amount} scale={2} disabled={disabled} width={140} onChange={(v) => onChange({ ...m, amount: v ?? '0' })} />
      <Box sx={{ width: 110 }}><CurrencySelect label="Ccy" value={m.currency} disabled={disabled} required onChange={(c) => onChange({ ...m, currency: c ?? currency, fx_rate: c === currency ? '1' : null })} /></Box>
      {m.currency !== currency && <Dec label={`FX → ${currency}`} value={m.fx_rate} scale={8} disabled={disabled} width={130} onChange={(v) => onChange({ ...m, fx_rate: v })} />}
      <AccountSelect value={m.account} disabled={disabled} onChange={(a) => onChange({ ...m, account: a })} />
    </Stack>
  );
}

export function ScenarioInputsEditor({ inputs, currency, readOnly, patch }: { inputs: ScenarioInputs; currency: string; readOnly: boolean; patch: Patch }) {
  const fuels = useQuery({ queryKey: ['reference', 'fuel-types', 'active'], queryFn: () => referenceApi.list('fuel-types', true), staleTime: 300_000 });
  const revCats = useQuery({ queryKey: ['reference', 'revenue-categories', 'active'], queryFn: () => referenceApi.list('revenue-categories', true), staleTime: 300_000 });
  const expCats = useQuery({ queryKey: ['reference', 'expense-categories', 'active'], queryFn: () => referenceApi.list('expense-categories', true), staleTime: 300_000 });
  const fuelName = (id: number) => fuels.data?.find((f) => f.id === id)?.code ?? `#${id}`;
  const d = readOnly;

  const addRevenue = () => patch((x) => {
    const cat = revCats.data?.[0] as (ReferenceItem & { is_commissionable?: boolean }) | undefined;
    if (!cat) return;
    x.revenue_items.push({ revenue_category_id: cat.id, description: cat.name, basis: 'lump_sum', quantity: null, rate: null, currency, fx_rate: '1',
      commissionable: !!cat.is_commissionable, address_pct: '0', brokerage_pct: '0', other_pct: '0', primary: x.revenue_items.length === 0 });
  });
  const addCost = () => patch((x) => {
    const cat = expCats.data?.find((c) => c.code === 'OTHER') ?? expCats.data?.[0];
    if (!cat) return;
    x.cost_items.push({ expense_category_id: cat.id, description: cat.name, basis: 'lump_sum', quantity: null, rate: null, currency, fx_rate: '1', account: 'owner' });
  });
  const blankCall = (): CallInput => ({ label: '', kind: 'port', working_days: '0', idle_days: '0', waiting_days: '0', dp_days: '0', standby_days: '0',
    port_cost: { amount: '0', currency, fx_rate: '1', account: 'owner' }, agency_cost: { amount: '0', currency, fx_rate: '1', account: 'owner' } });

  return (
    <Box>
      <Section title="General">
        <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap>
          <Dec label="Sea margin % (applies to sea time)" value={inputs.sea_margin_pct} scale={4} disabled={d} width={250} onChange={(v) => patch((x) => { x.sea_margin_pct = v ?? '0'; })} />
          <TextField select size="small" label="ECA fuel" value={inputs.eca_fuel_type_id ?? ''} disabled={d} sx={{ minWidth: 200 }}
            onChange={(e) => patch((x) => { x.eca_fuel_type_id = e.target.value === '' ? null : Number(e.target.value); })}>
            <MenuItem value="">None</MenuItem>
            {fuels.data?.filter((f) => f.is_eca_compliant).map((f) => <MenuItem key={f.id} value={f.id}>{f.code} — {f.name}</MenuItem>)}
          </TextField>
        </Stack>
      </Section>

      <Section title="Sea legs" hint="Distance from the stored distance table at creation; edit to override. Speed must match a consumption row."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => { x.legs.push({ label: '', condition: 'ballast', distance_nm: null, eca_distance_nm: '0', speed_kn: x.legs[0]?.speed_kn ?? null, sea_margin_pct: null }); })}>Add leg</Button>}>
        <Table size="small">
          <TableHead><TableRow><TableCell>Leg</TableCell><TableCell>Condition</TableCell><TableCell>Distance NM</TableCell><TableCell>of which ECA</TableCell><TableCell>Speed kn</TableCell><TableCell>Margin % override</TableCell><TableCell /></TableRow></TableHead>
          <TableBody>
            {inputs.legs.map((l, i) => (
              <TableRow key={i}>
                <TableCell sx={{ minWidth: 180 }}><TextField size="small" value={l.label} disabled={d} onChange={(e) => patch((x) => { x.legs[i].label = e.target.value; })} placeholder="From → To" />
                  {l.distance_source && <Typography variant="caption" color="text.secondary">{humanize(l.distance_source.replace(':', ' '))}</Typography>}</TableCell>
                <TableCell>
                  <TextField select size="small" value={l.condition} disabled={d} onChange={(e) => patch((x) => { x.legs[i].condition = e.target.value as 'laden' | 'ballast'; })}>
                    <MenuItem value="laden">Laden</MenuItem><MenuItem value="ballast">Ballast</MenuItem>
                  </TextField>
                </TableCell>
                <TableCell><Dec label="NM" value={l.distance_nm} scale={2} disabled={d} width={110} required onChange={(v) => patch((x) => { x.legs[i].distance_nm = v; })} /></TableCell>
                <TableCell><Dec label="ECA NM" value={l.eca_distance_nm} scale={2} disabled={d} width={100} onChange={(v) => patch((x) => { x.legs[i].eca_distance_nm = v; })} /></TableCell>
                <TableCell><Dec label="kn" value={l.speed_kn} scale={2} disabled={d} width={80} required onChange={(v) => patch((x) => { x.legs[i].speed_kn = v; })} /></TableCell>
                <TableCell><Dec label="%" value={l.sea_margin_pct} scale={4} disabled={d} width={90} onChange={(v) => patch((x) => { x.legs[i].sea_margin_pct = v; })} /></TableCell>
                <TableCell>{!d && <IconButton size="small" color="error" aria-label="Remove leg" onClick={() => patch((x) => { x.legs.splice(i, 1); })}><DeleteOutline fontSize="small" /></IconButton>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Section>

      <Section title="Port calls & offshore operations" hint="Days by consumption mode. Waiting days use idle consumption; offshore DP days use DP consumption."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => { x.calls.push(blankCall()); })}>Add call</Button>}>
        <Stack spacing={1.5}>
          {inputs.calls.map((c, i) => (
            <Box key={i} sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="flex-start">
                <TextField size="small" label="Call" value={c.label} disabled={d} sx={{ minWidth: 200 }} onChange={(e) => patch((x) => { x.calls[i].label = e.target.value; })} />
                <TextField select size="small" label="Kind" value={c.kind} disabled={d} onChange={(e) => patch((x) => { x.calls[i].kind = e.target.value as 'port' | 'offshore'; })}>
                  <MenuItem value="port">Port</MenuItem><MenuItem value="offshore">Offshore</MenuItem>
                </TextField>
                {(['working_days', 'idle_days', 'waiting_days', 'dp_days', 'standby_days'] as const).map((k) => (
                  <Dec key={k} label={humanize(k.replace('_days', ''))} value={c[k]} scale={6} disabled={d} width={95} onChange={(v) => patch((x) => { x.calls[i][k] = v ?? '0'; })} />
                ))}
                {!d && <IconButton size="small" color="error" aria-label="Remove call" onClick={() => patch((x) => { x.calls.splice(i, 1); })}><DeleteOutline fontSize="small" /></IconButton>}
              </Stack>
              <Grid container spacing={1} sx={{ mt: 0.5 }}>
                <Grid size={{ xs: 12, lg: 6 }}><MoneyFields label="Port cost" money={c.port_cost} currency={currency} disabled={d} onChange={(m) => patch((x) => { x.calls[i].port_cost = m; })} /></Grid>
                <Grid size={{ xs: 12, lg: 6 }}><MoneyFields label="Agency cost" money={c.agency_cost} currency={currency} disabled={d} onChange={(m) => patch((x) => { x.calls[i].agency_cost = m; })} /></Grid>
              </Grid>
            </Box>
          ))}
        </Stack>
      </Section>

      <Section title="Consumption (snapshot)" hint="Copied from the vessel profile when the scenario was created. Use “Refresh defaults” to re-read the profile."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => { x.consumption.push({ mode: 'sea_laden', speed_kn: x.legs[0]?.speed_kn ?? '0', fuel_type_id: x.consumption[0]?.fuel_type_id ?? fuels.data?.[0]?.id ?? 0, mt_per_day: '0' }); })}>Add row</Button>}>
        <Table size="small">
          <TableHead><TableRow><TableCell>Mode</TableCell><TableCell>Speed kn</TableCell><TableCell>Fuel</TableCell><TableCell>MT/day</TableCell><TableCell /></TableRow></TableHead>
          <TableBody>
            {inputs.consumption.map((r, i) => {
              const speed = CONSUMPTION_MODES.find((m) => m.value === r.mode)?.speed;
              return (
                <TableRow key={i}>
                  <TableCell>
                    <TextField select size="small" value={r.mode} disabled={d} onChange={(e) => patch((x) => { x.consumption[i].mode = e.target.value; })}>
                      {CONSUMPTION_MODES.map((m) => <MenuItem key={m.value} value={m.value}>{m.label}</MenuItem>)}
                    </TextField>
                  </TableCell>
                  <TableCell>{speed ? <Dec label="kn" value={r.speed_kn} scale={2} disabled={d} width={80} onChange={(v) => patch((x) => { x.consumption[i].speed_kn = v ?? '0'; })} /> : '—'}</TableCell>
                  <TableCell>
                    <TextField select size="small" value={r.fuel_type_id} disabled={d} onChange={(e) => patch((x) => { x.consumption[i].fuel_type_id = Number(e.target.value); })}>
                      {(fuels.data ?? []).map((f) => <MenuItem key={f.id} value={f.id}>{f.code}</MenuItem>)}
                    </TextField>
                  </TableCell>
                  <TableCell><Dec label="MT/day" value={r.mt_per_day} scale={3} disabled={d} width={100} required onChange={(v) => patch((x) => { x.consumption[i].mt_per_day = v ?? '0'; })} /></TableCell>
                  <TableCell>{!d && <IconButton size="small" color="error" aria-label="Remove row" onClick={() => patch((x) => { x.consumption.splice(i, 1); })}><DeleteOutline fontSize="small" /></IconButton>}</TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </Section>

      <Section title="Bunker prices" hint="Per fuel. Charterer-account bunkers are shown but excluded from the owner P&L."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => {
          const used = new Set(x.fuel_prices.map((p) => p.fuel_type_id));
          const next = x.consumption.find((c) => !used.has(c.fuel_type_id))?.fuel_type_id ?? fuels.data?.find((f) => !used.has(f.id))?.id;
          if (next) x.fuel_prices.push({ fuel_type_id: next, price_per_mt: null, currency, fx_rate: '1', account: x.fuel_prices[0]?.account ?? 'owner' });
        })}>Add fuel</Button>}>
        <Stack spacing={1}>
          {inputs.fuel_prices.map((p, i) => (
            <Stack key={i} direction="row" spacing={1} alignItems="flex-start" flexWrap="wrap" useFlexGap>
              <TextField size="small" label="Fuel" value={p.fuel_code ?? fuelName(p.fuel_type_id)} disabled sx={{ width: 100 }} />
              <Dec label="Price / MT" value={p.price_per_mt} scale={4} disabled={d} width={130} required onChange={(v) => patch((x) => { x.fuel_prices[i].price_per_mt = v; })} />
              <Box sx={{ width: 110 }}><CurrencySelect label="Ccy" required value={p.currency} disabled={d} onChange={(c) => patch((x) => { x.fuel_prices[i].currency = c ?? currency; x.fuel_prices[i].fx_rate = c === currency ? '1' : null; })} /></Box>
              {p.currency !== currency && <Dec label={`FX → ${currency}`} value={p.fx_rate} scale={8} disabled={d} width={130} onChange={(v) => patch((x) => { x.fuel_prices[i].fx_rate = v; })} />}
              <AccountSelect value={p.account} disabled={d} onChange={(a) => patch((x) => { x.fuel_prices[i].account = a; })} />
              {!d && <IconButton size="small" color="error" aria-label="Remove price" onClick={() => patch((x) => { x.fuel_prices.splice(i, 1); })}><DeleteOutline fontSize="small" /></IconButton>}
            </Stack>
          ))}
        </Stack>
      </Section>

      <Section title="Revenue items" hint="Commission is calculated per item. ★ marks the break-even item (one only)." action={!d && <Button size="small" startIcon={<AddIcon />} onClick={addRevenue}>Add revenue</Button>}>
        <Stack spacing={1.5}>
          {inputs.revenue_items.map((r, i) => (
            <RevenueRow key={r.key ?? i} item={r} currency={currency} disabled={d} categories={revCats.data ?? []}
              onChange={(fn) => patch((x) => fn(x.revenue_items[i]))}
              onPrimary={() => patch((x) => { x.revenue_items.forEach((it, j) => { it.primary = j === i; }); })}
              onRemove={() => patch((x) => { x.revenue_items.splice(i, 1); })} />
          ))}
        </Stack>
      </Section>

      <Section title="Cost items" hint="Group “tonnage” = head-charter cost (cargo relet). “Operational” costs are excluded from TCE." action={!d && <Button size="small" startIcon={<AddIcon />} onClick={addCost}>Add cost</Button>}>
        <Stack spacing={1}>
          {inputs.cost_items.map((c, i) => (
            <CostRow key={c.key ?? i} item={c} currency={currency} disabled={d} categories={expCats.data ?? []} onChange={(fn) => patch((x) => fn(x.cost_items[i]))} onRemove={() => patch((x) => { x.cost_items.splice(i, 1); })} />
          ))}
        </Stack>
      </Section>
    </Box>
  );
}

function RevenueRow({ item, currency, disabled, categories, onChange, onPrimary, onRemove }: {
  item: RevenueItem; currency: string; disabled: boolean; categories: ReferenceItem[];
  onChange: (fn: (r: RevenueItem) => void) => void; onPrimary: () => void; onRemove: () => void;
}) {
  return (
    <Box sx={{ p: 1.5, border: 1, borderColor: item.primary ? 'primary.main' : 'divider', borderRadius: 2 }}>
      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="flex-start">
        <Tooltip title={item.primary ? 'Break-even item' : 'Use for break-even'}>
          <span><IconButton size="small" color={item.primary ? 'primary' : 'default'} disabled={disabled} aria-label="Set break-even item" onClick={onPrimary}>{item.primary ? '★' : '☆'}</IconButton></span>
        </Tooltip>
        <TextField select size="small" label="Category" value={item.revenue_category_id} disabled={disabled} sx={{ minWidth: 170 }}
          onChange={(e) => { const cat = categories.find((c) => c.id === Number(e.target.value)) as (ReferenceItem & { is_commissionable?: boolean }) | undefined;
            onChange((r) => { r.revenue_category_id = Number(e.target.value); r.commissionable = !!cat?.is_commissionable; if (cat) r.description = cat.name; }); }}>
          {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
        </TextField>
        <TextField size="small" label="Description" value={item.description} disabled={disabled} sx={{ minWidth: 160 }} onChange={(e) => onChange((r) => { r.description = e.target.value; })} />
        <TextField select size="small" label="Basis" value={item.basis} disabled={disabled} onChange={(e) => onChange((r) => { r.basis = e.target.value as RevenueItem['basis']; })}>
          {RATE_BASES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>)}
        </TextField>
        {item.basis !== 'lump_sum' && <Dec label={item.basis === 'per_mt' ? 'Quantity MT' : item.basis === 'per_day' ? 'Days (empty = elapsed)' : 'Hours (empty = elapsed)'}
          value={item.quantity} scale={4} disabled={disabled} width={170} required={item.basis === 'per_mt'} onChange={(v) => onChange((r) => { r.quantity = v; })} />}
        <Dec label={item.basis === 'lump_sum' ? 'Amount' : 'Rate'} value={item.rate} scale={4} disabled={disabled} width={130} required onChange={(v) => onChange((r) => { r.rate = v; })} />
        <Box sx={{ width: 110 }}><CurrencySelect label="Ccy" required value={item.currency} disabled={disabled} onChange={(c) => onChange((r) => { r.currency = c ?? currency; r.fx_rate = c === currency ? '1' : null; })} /></Box>
        {item.currency !== currency && <Dec label={`FX → ${currency}`} value={item.fx_rate} scale={8} disabled={disabled} width={130} onChange={(v) => onChange((r) => { r.fx_rate = v; })} />}
        {!disabled && <IconButton size="small" color="error" aria-label="Remove revenue item" onClick={onRemove}><DeleteOutline fontSize="small" /></IconButton>}
      </Stack>
      <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 1 }} flexWrap="wrap" useFlexGap>
        <Typography variant="body2">Commissionable</Typography>
        <Switch size="small" checked={!!item.commissionable} disabled={disabled} onChange={(e) => onChange((r) => { r.commissionable = e.target.checked; })} />
        {item.commissionable && <>
          <Dec label="Address %" value={item.address_pct} scale={4} disabled={disabled} width={100} onChange={(v) => onChange((r) => { r.address_pct = v ?? '0'; })} />
          <Dec label="Brokerage %" value={item.brokerage_pct} scale={4} disabled={disabled} width={110} onChange={(v) => onChange((r) => { r.brokerage_pct = v ?? '0'; })} />
          <Dec label="Other %" value={item.other_pct} scale={4} disabled={disabled} width={100} onChange={(v) => onChange((r) => { r.other_pct = v ?? '0'; })} />
        </>}
      </Stack>
    </Box>
  );
}

function CostRow({ item, currency, disabled, categories, onChange, onRemove }: {
  item: CostItem; currency: string; disabled: boolean; categories: ReferenceItem[]; onChange: (fn: (c: CostItem) => void) => void; onRemove: () => void;
}) {
  return (
    <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="flex-start" sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
      <TextField select size="small" label="Category" value={item.expense_category_id} disabled={disabled} sx={{ minWidth: 190 }}
        onChange={(e) => { const cat = categories.find((c) => c.id === Number(e.target.value)); onChange((c) => { c.expense_category_id = Number(e.target.value); if (cat) c.description = cat.name; }); }}>
        {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
      </TextField>
      <TextField size="small" label="Description" value={item.description} disabled={disabled} sx={{ minWidth: 160 }} onChange={(e) => onChange((c) => { c.description = e.target.value; })} />
      <TextField select size="small" label="Basis" value={item.basis} disabled={disabled} onChange={(e) => onChange((c) => { c.basis = e.target.value as CostItem['basis']; })}>
        {COST_BASES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>)}
      </TextField>
      {(item.basis === 'per_day' || item.basis === 'per_mt') && <Dec label={item.basis === 'per_mt' ? 'Quantity MT' : 'Days (empty = elapsed)'} value={item.quantity} scale={4} disabled={disabled} width={160}
        required={item.basis === 'per_mt'} onChange={(v) => onChange((c) => { c.quantity = v; })} />}
      <Dec label={item.basis === 'pct_of_revenue' ? '% of revenue' : item.basis === 'lump_sum' ? 'Amount' : 'Rate'} value={item.rate} scale={4} disabled={disabled} width={130} required onChange={(v) => onChange((c) => { c.rate = v; })} />
      {item.basis !== 'pct_of_revenue' && <Box sx={{ width: 110 }}><CurrencySelect label="Ccy" required value={item.currency} disabled={disabled} onChange={(cc) => onChange((c) => { c.currency = cc ?? currency; c.fx_rate = cc === currency ? '1' : null; })} /></Box>}
      {item.basis !== 'pct_of_revenue' && item.currency !== currency && <Dec label={`FX → ${currency}`} value={item.fx_rate} scale={8} disabled={disabled} width={130} onChange={(v) => onChange((c) => { c.fx_rate = v; })} />}
      <AccountSelect value={item.account} disabled={disabled} onChange={(a) => onChange((c) => { c.account = a; })} />
      {!disabled && <IconButton size="small" color="error" aria-label="Remove cost item" onClick={onRemove}><DeleteOutline fontSize="small" /></IconButton>}
    </Stack>
  );
}
