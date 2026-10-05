import type { ReactNode } from 'react';
import { Box, Button, IconButton, MenuItem, Stack, Switch, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import { alpha } from '@mui/material/styles';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import TuneOutlined from '@mui/icons-material/TuneOutlined';
import RouteOutlined from '@mui/icons-material/RouteOutlined';
import AnchorOutlined from '@mui/icons-material/AnchorOutlined';
import SpeedOutlined from '@mui/icons-material/SpeedOutlined';
import LocalGasStationOutlined from '@mui/icons-material/LocalGasStationOutlined';
import TrendingUpOutlined from '@mui/icons-material/TrendingUpOutlined';
import ReceiptLongOutlined from '@mui/icons-material/ReceiptLongOutlined';
import { useQuery } from '@tanstack/react-query';
import { referenceApi } from '../../api/masters';
import { CurrencySelect } from '../../components/MasterPickers';
import { FormSection as Section, FormEmptyState as EmptyRows } from '../../components/FormSection';
import { CONSUMPTION_MODES } from '../../constants/masters';
import { COST_BASES, RATE_BASES } from '../../constants/chartering';
import { isDecimal } from '../../utils/decimal';
import { humanize } from '../../utils/format';
import type { Account, CallInput, CostItem, Money, RevenueItem, ScenarioInputs } from '../../types/chartering';
import type { ReferenceItem } from '../../types/masters';

type Patch = (fn: (draft: ScenarioInputs) => void) => void;
const wideRow = '@container scenario-inputs (min-width: 900px)';
const dayFields = [
  ['working_days', 'Working'], ['idle_days', 'Idle'], ['waiting_days', 'Waiting'], ['dp_days', 'DP'], ['standby_days', 'Standby'],
] as const;

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
    <TextField select size="small" label="Account" value={value} disabled={disabled} onChange={(e) => onChange(e.target.value as Account)}>
      <MenuItem value="owner">Owner</MenuItem><MenuItem value="charterer">Charterer</MenuItem>
    </TextField>
  );
}

/** Explicit columns keep full-width selects inside their own compact field. */
function Fields({ children, columns }: { children: ReactNode; columns: string }) {
  return <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 1, alignItems: 'start', minWidth: 0, '& > *': { minWidth: 0 }, [wideRow]: { gridTemplateColumns: columns } }}>{children}</Box>;
}

function FieldGroup({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ minHeight: 28, mb: 0.75 }}>
        <Typography variant="caption" fontWeight={600} color="text.secondary">{title}</Typography>
        {action}
      </Stack>
      {children}
    </Box>
  );
}

function InputRow({ label, columns, action, highlight, children }: { label: string; columns: string; action?: ReactNode; highlight?: boolean; children: ReactNode }) {
  return (
    <Box role="group" aria-label={label} sx={{
      position: 'relative', display: 'grid', gridTemplateColumns: 'minmax(0, 1fr)', gap: 1.5,
      p: 1.5, pr: action ? 6 : 1.5, border: 1, borderColor: highlight ? 'primary.light' : 'divider', borderRadius: 2,
      bgcolor: highlight ? (t) => alpha(t.palette.primary.main, 0.025) : 'background.paper',
      [wideRow]: { gridTemplateColumns: columns },
    }}>
      {children}
      {action && <Box sx={{ position: 'absolute', top: 14, right: 8 }}>{action}</Box>}
    </Box>
  );
}

function MoneyFields({ label, money, currency, onChange, disabled }: { label: string; money: Money | null; currency: string; onChange: (m: Money) => void; disabled?: boolean }) {
  const m: Money = money ?? { amount: '0', currency, fx_rate: '1', account: 'owner' };
  return (
    <Fields columns={`minmax(0, 1.1fr) minmax(0, 0.75fr) ${m.currency !== currency ? 'minmax(0, 0.9fr) ' : ''}minmax(0, 1fr)`}>
      <Dec label={label} value={m.amount} scale={2} disabled={disabled} onChange={(v) => onChange({ ...m, amount: v ?? '0' })} />
      <CurrencySelect compact label="Currency" value={m.currency} disabled={disabled} required onChange={(c) => onChange({ ...m, currency: c ?? currency, fx_rate: c === currency ? '1' : null })} />
      {m.currency !== currency && <Dec label={`FX → ${currency}`} value={m.fx_rate} scale={8} disabled={disabled} onChange={(v) => onChange({ ...m, fx_rate: v })} />}
      <AccountSelect value={m.account} disabled={disabled} onChange={(a) => onChange({ ...m, account: a })} />
    </Fields>
  );
}

export function ScenarioInputsEditor({ inputs, currency, readOnly, patch }: { inputs: ScenarioInputs; currency: string; readOnly: boolean; patch: Patch }) {
  const fuels = useQuery({ queryKey: ['reference', 'fuel-types', 'active'], queryFn: () => referenceApi.list('fuel-types', true), staleTime: 300_000 });
  const revCats = useQuery({ queryKey: ['reference', 'revenue-categories', 'active'], queryFn: () => referenceApi.list('revenue-categories', true), staleTime: 300_000 });
  const expCats = useQuery({ queryKey: ['reference', 'expense-categories', 'active'], queryFn: () => referenceApi.list('expense-categories', true), staleTime: 300_000 });
  const fuelName = (id: number) => fuels.data?.find((f) => f.id === id)?.code ?? `#${id}`;
  const ecaFuels = fuels.data?.filter((f) => f.is_eca_compliant) ?? [];
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
    <Stack spacing={2.5} sx={{ containerType: 'inline-size', containerName: 'scenario-inputs', minWidth: 0, '& .MuiFormControl-root': { minWidth: 0 }, '& .MuiInputBase-root, & .MuiInputLabel-root': { fontSize: '0.8125rem' } }}>
      <Section title="General" icon={<TuneOutlined />} hint="Shared voyage assumptions. Sea margin applies to the sailing time of each leg.">
        <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 220px), 1fr))', gap: 1.5, maxWidth: 500 }}>
          <Dec label="Sea margin %" value={inputs.sea_margin_pct} scale={4} disabled={d} onChange={(v) => patch((x) => { x.sea_margin_pct = v ?? '0'; })} />
          <TextField select size="small" label="ECA fuel" value={inputs.eca_fuel_type_id ?? ''} disabled={d}
            onChange={(e) => patch((x) => { x.eca_fuel_type_id = e.target.value === '' ? null : Number(e.target.value); })}>
            <MenuItem value="">None</MenuItem>
            {inputs.eca_fuel_type_id && !ecaFuels.some((f) => f.id === inputs.eca_fuel_type_id) && <MenuItem value={inputs.eca_fuel_type_id} disabled>{fuelName(inputs.eca_fuel_type_id)}</MenuItem>}
            {ecaFuels.map((f) => <MenuItem key={f.id} value={f.id}>{f.code} — {f.name}</MenuItem>)}
          </TextField>
        </Box>
      </Section>

      <Section title="Sea legs" icon={<RouteOutlined />} count={inputs.legs.length} hint="Distances are copied from stored routes. Match the speed to a consumption row."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => { x.legs.push({ label: '', condition: 'ballast', distance_nm: null, eca_distance_nm: '0', speed_kn: x.legs[0]?.speed_kn ?? null, sea_margin_pct: null }); })}>Add leg</Button>}>
        <TableContainer sx={{ border: 1, borderColor: 'divider', borderRadius: 2 }}>
          <Table size="small" sx={{ minWidth: 780 }}>
            <TableHead><TableRow><TableCell>Leg</TableCell><TableCell>Condition</TableCell><TableCell>Distance NM</TableCell><TableCell>of which ECA</TableCell><TableCell>Speed kn</TableCell><TableCell>Margin % override</TableCell><TableCell /></TableRow></TableHead>
            <TableBody>
              {inputs.legs.map((l, i) => (
                <TableRow key={i}>
                  <TableCell sx={{ minWidth: 180 }}><TextField size="small" label="Route" value={l.label} disabled={d} onChange={(e) => patch((x) => { x.legs[i].label = e.target.value; })} placeholder="From → To" />
                    {l.distance_source && <Typography variant="caption" color="text.secondary">{humanize(l.distance_source.replace(':', ' '))}</Typography>}</TableCell>
                  <TableCell>
                    <TextField select size="small" label="Condition" value={l.condition} disabled={d} onChange={(e) => patch((x) => { x.legs[i].condition = e.target.value as 'laden' | 'ballast'; })}>
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
        </TableContainer>
        {inputs.legs.length === 0 && <EmptyRows>No sea legs yet. Add a leg to plan the route.</EmptyRows>}
      </Section>

      <Section title="Port calls & offshore operations" icon={<AnchorOutlined />} count={inputs.calls.length} hint="Keep the location, duration, and charges together. Waiting uses idle consumption; DP days use DP consumption."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => { x.calls.push(blankCall()); })}>Add call</Button>}>
        <Stack spacing={1.5}>
          {inputs.calls.map((c, i) => (
            <InputRow key={i} label={`Call ${i + 1}`} columns="minmax(0, 0.85fr) minmax(0, 1.2fr) minmax(0, 1.25fr)"
              action={!d && <IconButton size="small" color="error" aria-label="Remove call" onClick={() => patch((x) => { x.calls.splice(i, 1); })}><DeleteOutline fontSize="small" /></IconButton>}>
              <FieldGroup title={`Call ${String(i + 1).padStart(2, '0')} · Location & type`}>
                <Stack spacing={1}>
                  <TextField size="small" label="Call" value={c.label} disabled={d} onChange={(e) => patch((x) => { x.calls[i].label = e.target.value; })} />
                  <TextField select size="small" label="Kind" value={c.kind} disabled={d} onChange={(e) => patch((x) => { x.calls[i].kind = e.target.value as 'port' | 'offshore'; })}>
                    <MenuItem value="port">Port</MenuItem><MenuItem value="offshore">Offshore</MenuItem>
                  </TextField>
                </Stack>
              </FieldGroup>
              <FieldGroup title="Duration · days">
                <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: 1, [wideRow]: { gridTemplateColumns: 'repeat(5, minmax(0, 1fr))' } }}>
                  {dayFields.map(([k, label]) => <Dec key={k} label={label} value={c[k]} scale={6} disabled={d} onChange={(v) => patch((x) => { x.calls[i][k] = v ?? '0'; })} />)}
                </Box>
              </FieldGroup>
              <FieldGroup title="Port & agency charges">
                <Stack spacing={1}>
                  <MoneyFields label="Port cost" money={c.port_cost} currency={currency} disabled={d} onChange={(m) => patch((x) => { x.calls[i].port_cost = m; })} />
                  <MoneyFields label="Agency cost" money={c.agency_cost} currency={currency} disabled={d} onChange={(m) => patch((x) => { x.calls[i].agency_cost = m; })} />
                </Stack>
              </FieldGroup>
            </InputRow>
          ))}
          {inputs.calls.length === 0 && <EmptyRows>No calls yet. Add a port call or offshore operation.</EmptyRows>}
        </Stack>
      </Section>

      <Section title="Consumption (snapshot)" icon={<SpeedOutlined />} count={inputs.consumption.length} hint="Vessel consumption assumptions. Use “Refresh defaults” to update the profile snapshot."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => { x.consumption.push({ mode: 'sea_laden', speed_kn: x.legs[0]?.speed_kn ?? '0', fuel_type_id: x.consumption[0]?.fuel_type_id ?? fuels.data?.[0]?.id ?? 0, mt_per_day: '0' }); })}>Add row</Button>}>
        <TableContainer sx={{ border: 1, borderColor: 'divider', borderRadius: 2 }}>
          <Table size="small" sx={{ minWidth: 560 }}>
            <TableHead><TableRow><TableCell>Mode</TableCell><TableCell>Speed kn</TableCell><TableCell>Fuel</TableCell><TableCell>MT/day</TableCell><TableCell /></TableRow></TableHead>
            <TableBody>
              {inputs.consumption.map((r, i) => {
                const speed = CONSUMPTION_MODES.find((m) => m.value === r.mode)?.speed;
                return (
                  <TableRow key={i}>
                    <TableCell>
                      <TextField select size="small" label="Mode" value={r.mode} disabled={d} onChange={(e) => patch((x) => { x.consumption[i].mode = e.target.value; })}>
                        {!CONSUMPTION_MODES.some((m) => m.value === r.mode) && <MenuItem value={r.mode} disabled>{humanize(r.mode)}</MenuItem>}
                        {CONSUMPTION_MODES.map((m) => <MenuItem key={m.value} value={m.value}>{m.label}</MenuItem>)}
                      </TextField>
                    </TableCell>
                    <TableCell>{speed ? <Dec label="kn" value={r.speed_kn} scale={2} disabled={d} width={80} onChange={(v) => patch((x) => { x.consumption[i].speed_kn = v ?? '0'; })} /> : '—'}</TableCell>
                    <TableCell>
                      <TextField select size="small" label="Fuel" value={r.fuel_type_id} disabled={d} onChange={(e) => patch((x) => { x.consumption[i].fuel_type_id = Number(e.target.value); })}>
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
        </TableContainer>
        {inputs.consumption.length === 0 && <EmptyRows>No consumption rows yet. Add assumptions or refresh the vessel defaults.</EmptyRows>}
      </Section>

      <Section title="Bunker prices" icon={<LocalGasStationOutlined />} count={inputs.fuel_prices.length} hint="Price each fuel and choose who pays. Charterer-account bunkers are excluded from the owner P&L."
        action={!d && <Button size="small" startIcon={<AddIcon />} onClick={() => patch((x) => {
          const used = new Set(x.fuel_prices.map((p) => p.fuel_type_id));
          const next = x.consumption.find((c) => !used.has(c.fuel_type_id))?.fuel_type_id ?? fuels.data?.find((f) => !used.has(f.id))?.id;
          if (next) x.fuel_prices.push({ fuel_type_id: next, price_per_mt: null, currency, fx_rate: '1', account: x.fuel_prices[0]?.account ?? 'owner' });
        })}>Add fuel</Button>}>
        <Stack spacing={1}>
          {inputs.fuel_prices.map((p, i) => (
            <InputRow key={i} label={`Bunker price ${i + 1}`} columns="minmax(0, 1fr)"
              action={!d && <IconButton size="small" color="error" aria-label="Remove price" onClick={() => patch((x) => { x.fuel_prices.splice(i, 1); })}><DeleteOutline fontSize="small" /></IconButton>}>
              <Fields columns={`100px minmax(120px, 1fr) 100px ${p.currency !== currency ? 'minmax(120px, 0.75fr) ' : ''}minmax(120px, 0.75fr)`}>
                <TextField size="small" label="Fuel" value={p.fuel_code ?? fuelName(p.fuel_type_id)} disabled />
                <Dec label="Price / MT" value={p.price_per_mt} scale={4} disabled={d} required onChange={(v) => patch((x) => { x.fuel_prices[i].price_per_mt = v; })} />
                <CurrencySelect compact label="Currency" required value={p.currency} disabled={d} onChange={(c) => patch((x) => { x.fuel_prices[i].currency = c ?? currency; x.fuel_prices[i].fx_rate = c === currency ? '1' : null; })} />
                {p.currency !== currency && <Dec label={`FX → ${currency}`} value={p.fx_rate} scale={8} disabled={d} onChange={(v) => patch((x) => { x.fuel_prices[i].fx_rate = v; })} />}
                <AccountSelect value={p.account} disabled={d} onChange={(a) => patch((x) => { x.fuel_prices[i].account = a; })} />
              </Fields>
            </InputRow>
          ))}
          {inputs.fuel_prices.length === 0 && <EmptyRows>No bunker prices yet. Add a fuel to enter its price.</EmptyRows>}
        </Stack>
      </Section>

      <Section title="Revenue items" icon={<TrendingUpOutlined />} count={inputs.revenue_items.length} hint="Pricing and commissions per item. ★ identifies the single break-even item." action={!d && <Button size="small" startIcon={<AddIcon />} onClick={addRevenue}>Add revenue</Button>}>
        <Stack spacing={1.5}>
          {inputs.revenue_items.map((r, i) => (
            <RevenueRow key={r.key ?? i} index={i} item={r} currency={currency} disabled={d} categories={revCats.data ?? []}
              onChange={(fn) => patch((x) => fn(x.revenue_items[i]))}
              onPrimary={() => patch((x) => { x.revenue_items.forEach((it, j) => { it.primary = j === i; }); })}
              onRemove={() => patch((x) => { x.revenue_items.splice(i, 1); })} />
          ))}
          {inputs.revenue_items.length === 0 && <EmptyRows>No revenue items yet. Add freight, hire, or service income.</EmptyRows>}
        </Stack>
      </Section>

      <Section title="Cost items" icon={<ReceiptLongOutlined />} count={inputs.cost_items.length} hint="Additional costs grouped by item, pricing, and account. Tonnage = head-charter; operational costs are excluded from TCE." action={!d && <Button size="small" startIcon={<AddIcon />} onClick={addCost}>Add cost</Button>}>
        <Stack spacing={1}>
          {inputs.cost_items.map((c, i) => (
            <CostRow key={c.key ?? i} index={i} item={c} currency={currency} disabled={d} categories={expCats.data ?? []} onChange={(fn) => patch((x) => fn(x.cost_items[i]))} onRemove={() => patch((x) => { x.cost_items.splice(i, 1); })} />
          ))}
          {inputs.cost_items.length === 0 && <EmptyRows>No additional costs yet. Add a cost item when needed.</EmptyRows>}
        </Stack>
      </Section>
    </Stack>
  );
}

function RevenueRow({ index, item, currency, disabled, categories, onChange, onPrimary, onRemove }: {
  index: number; item: RevenueItem; currency: string; disabled: boolean; categories: ReferenceItem[];
  onChange: (fn: (r: RevenueItem) => void) => void; onPrimary: () => void; onRemove: () => void;
}) {
  return (
    <InputRow label={`Revenue item ${index + 1}`} columns="minmax(0, 1.15fr) minmax(0, 1.6fr) minmax(0, 1fr)" highlight={item.primary}
      action={!disabled && <IconButton size="small" color="error" aria-label="Remove revenue item" onClick={onRemove}><DeleteOutline fontSize="small" /></IconButton>}>
      <FieldGroup title={`Revenue item ${String(index + 1).padStart(2, '0')}`} action={
        <Tooltip title={item.primary ? 'Break-even item' : 'Use for break-even'}>
          <span><IconButton size="small" color={item.primary ? 'primary' : 'default'} disabled={disabled} aria-label="Set break-even item" onClick={onPrimary}
            sx={{ width: 28, height: 28, '&.Mui-disabled': { color: item.primary ? 'primary.main' : 'text.disabled' } }}>{item.primary ? '★' : '☆'}</IconButton></span>
        </Tooltip>
      }>
        <Fields columns="minmax(0, 1fr) minmax(0, 1.2fr)">
          <TextField select size="small" label="Category" value={item.revenue_category_id} disabled={disabled}
            onChange={(e) => { const cat = categories.find((c) => c.id === Number(e.target.value)) as (ReferenceItem & { is_commissionable?: boolean }) | undefined;
              onChange((r) => { r.revenue_category_id = Number(e.target.value); r.commissionable = !!cat?.is_commissionable; if (cat) r.description = cat.name; }); }}>
            {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
          </TextField>
          <TextField size="small" label="Description" value={item.description} disabled={disabled} onChange={(e) => onChange((r) => { r.description = e.target.value; })} />
        </Fields>
      </FieldGroup>
      <FieldGroup title="Pricing">
        <Fields columns={`minmax(0, 1.15fr) ${item.basis !== 'lump_sum' ? 'minmax(0, 1fr) ' : ''}minmax(0, 1.1fr) minmax(0, 0.85fr)${item.currency !== currency ? ' minmax(0, 1fr)' : ''}`}>
          <TextField select size="small" label="Basis" value={item.basis} disabled={disabled} onChange={(e) => onChange((r) => { r.basis = e.target.value as RevenueItem['basis']; })}>
            {RATE_BASES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>)}
          </TextField>
          {item.basis !== 'lump_sum' && <Tooltip title={item.basis === 'per_mt' ? 'Cargo quantity in metric tonnes' : 'Leave blank to use the elapsed voyage duration'}>
            <Box><Dec label={item.basis === 'per_mt' ? 'Qty MT' : item.basis === 'per_day' ? 'Days' : 'Hours'}
              value={item.quantity} scale={4} disabled={disabled} required={item.basis === 'per_mt'} onChange={(v) => onChange((r) => { r.quantity = v; })} /></Box>
          </Tooltip>}
          <Dec label={item.basis === 'lump_sum' ? 'Amount' : 'Rate'} value={item.rate} scale={4} disabled={disabled} required onChange={(v) => onChange((r) => { r.rate = v; })} />
          <CurrencySelect compact label="Currency" required value={item.currency} disabled={disabled} onChange={(c) => onChange((r) => { r.currency = c ?? currency; r.fx_rate = c === currency ? '1' : null; })} />
          {item.currency !== currency && <Dec label={`FX → ${currency}`} value={item.fx_rate} scale={8} disabled={disabled} onChange={(v) => onChange((r) => { r.fx_rate = v; })} />}
        </Fields>
      </FieldGroup>
      <FieldGroup title="Commissions" action={<Switch size="small" slotProps={{ input: { 'aria-label': 'Commissionable' } }} checked={!!item.commissionable} disabled={disabled} onChange={(e) => onChange((r) => { r.commissionable = e.target.checked; })} />}>
        {item.commissionable ? <Fields columns="repeat(3, minmax(0, 1fr))">
          <Dec label="Address %" value={item.address_pct} scale={4} disabled={disabled} onChange={(v) => onChange((r) => { r.address_pct = v ?? '0'; })} />
          <Dec label="Brokerage %" value={item.brokerage_pct} scale={4} disabled={disabled} onChange={(v) => onChange((r) => { r.brokerage_pct = v ?? '0'; })} />
          <Dec label="Other %" value={item.other_pct} scale={4} disabled={disabled} onChange={(v) => onChange((r) => { r.other_pct = v ?? '0'; })} />
        </Fields> : <Typography variant="caption" color="text.secondary">No commission applied</Typography>}
      </FieldGroup>
    </InputRow>
  );
}

function CostRow({ index, item, currency, disabled, categories, onChange, onRemove }: {
  index: number; item: CostItem; currency: string; disabled: boolean; categories: ReferenceItem[]; onChange: (fn: (c: CostItem) => void) => void; onRemove: () => void;
}) {
  return (
    <InputRow label={`Cost item ${index + 1}`} columns="minmax(0, 1.2fr) minmax(0, 1.8fr) 110px"
      action={!disabled && <IconButton size="small" color="error" aria-label="Remove cost item" onClick={onRemove}><DeleteOutline fontSize="small" /></IconButton>}>
      <FieldGroup title={`Cost item ${String(index + 1).padStart(2, '0')}`}>
        <Fields columns="minmax(0, 1fr) minmax(0, 1.2fr)">
          <TextField select size="small" label="Category" value={item.expense_category_id} disabled={disabled}
            onChange={(e) => { const cat = categories.find((c) => c.id === Number(e.target.value)); onChange((c) => { c.expense_category_id = Number(e.target.value); if (cat) c.description = cat.name; }); }}>
            {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
          </TextField>
          <TextField size="small" label="Description" value={item.description} disabled={disabled} onChange={(e) => onChange((c) => { c.description = e.target.value; })} />
        </Fields>
      </FieldGroup>
      <FieldGroup title="Pricing">
        <Fields columns={`minmax(0, 1.3fr) ${item.basis === 'per_day' || item.basis === 'per_mt' ? 'minmax(0, 1fr) ' : ''}minmax(0, 1.1fr)${item.basis !== 'pct_of_revenue' ? ' minmax(0, 0.85fr)' : ''}${item.basis !== 'pct_of_revenue' && item.currency !== currency ? ' minmax(0, 1fr)' : ''}`}>
          <TextField select size="small" label="Basis" value={item.basis} disabled={disabled} onChange={(e) => onChange((c) => { c.basis = e.target.value as CostItem['basis']; })}>
            {COST_BASES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>)}
          </TextField>
          {(item.basis === 'per_day' || item.basis === 'per_mt') && <Tooltip title={item.basis === 'per_mt' ? 'Cargo quantity in metric tonnes' : 'Leave blank to use the elapsed voyage duration'}>
            <Box><Dec label={item.basis === 'per_mt' ? 'Qty MT' : 'Days'} value={item.quantity} scale={4} disabled={disabled}
              required={item.basis === 'per_mt'} onChange={(v) => onChange((c) => { c.quantity = v; })} /></Box>
          </Tooltip>}
          <Dec label={item.basis === 'pct_of_revenue' ? '% of revenue' : item.basis === 'lump_sum' ? 'Amount' : 'Rate'} value={item.rate} scale={4} disabled={disabled} required onChange={(v) => onChange((c) => { c.rate = v; })} />
          {item.basis !== 'pct_of_revenue' && <CurrencySelect compact label="Currency" required value={item.currency} disabled={disabled} onChange={(cc) => onChange((c) => { c.currency = cc ?? currency; c.fx_rate = cc === currency ? '1' : null; })} />}
          {item.basis !== 'pct_of_revenue' && item.currency !== currency && <Dec label={`FX → ${currency}`} value={item.fx_rate} scale={8} disabled={disabled} onChange={(v) => onChange((c) => { c.fx_rate = v; })} />}
        </Fields>
      </FieldGroup>
      <FieldGroup title="Charged to">
        <AccountSelect value={item.account} disabled={disabled} onChange={(a) => onChange((c) => { c.account = a; })} />
      </FieldGroup>
    </InputRow>
  );
}
