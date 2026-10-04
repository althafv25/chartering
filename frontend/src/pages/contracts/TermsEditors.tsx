import { Box, Button, IconButton, MenuItem, Stack, TextField } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { CurrencySelect, ReferenceSelect } from '../../components/MasterPickers';
import { RATE_TYPES, RATE_UNITS, unitFor } from '../../constants/contracts';
import { isDecimal } from '../../utils/decimal';
import { blankRate } from './termsHelpers';
import { humanize } from '../../utils/format';
import type { ContractClause, ContractRate } from '../../types/contracts';

/** Editable full rate set (sent as a whole; the API validates units and overlaps). */
export function RatesEditor({ rates, currency, onChange }: { rates: ContractRate[]; currency: string; onChange: (r: ContractRate[]) => void }) {
  const set = (i: number, patch: Partial<ContractRate>) => onChange(rates.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  return (
    <Stack spacing={1.25}>
      {rates.map((r, i) => {
        const fixedUnit = unitFor(r.rate_type);
        return (
          <Stack key={i} direction="row" spacing={1} flexWrap="wrap" useFlexGap alignItems="flex-start" sx={{ p: 1.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
            <TextField select size="small" label="Rate type" value={r.rate_type} sx={{ minWidth: 180 }}
              onChange={(e) => set(i, { rate_type: e.target.value, unit: unitFor(e.target.value) ?? r.unit })}>
              {RATE_TYPES.map((t) => <MenuItem key={t.value} value={t.value}>{t.label}</MenuItem>)}
            </TextField>
            <TextField size="small" label="Amount" required value={r.amount} inputMode="decimal" sx={{ width: 130 }}
              error={r.amount !== '' && !isDecimal(r.amount, 4)} onChange={(e) => set(i, { amount: e.target.value.trim() })} />
            <Box sx={{ width: 110 }}><CurrencySelect label="Ccy" required value={r.currency} onChange={(c) => set(i, { currency: c ?? currency })} /></Box>
            <TextField select size="small" label="Unit" value={r.unit} disabled={!!fixedUnit} onChange={(e) => set(i, { unit: e.target.value })} sx={{ width: 120 }}>
              {RATE_UNITS.map((u) => <MenuItem key={u} value={u}>{humanize(u)}</MenuItem>)}
            </TextField>
            <Box sx={{ width: 190 }}>
              <ReferenceSelect type="offshore-activity-types" label="Activity (optional)" size="small" value={r.offshore_activity_type_id} allowEmpty
                onChange={(v) => set(i, { offshore_activity_type_id: v })} />
            </Box>
            <TextField size="small" type="date" label="From" value={r.effective_from ?? ''} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: 150 }}
              onChange={(e) => set(i, { effective_from: e.target.value || null })} />
            <TextField size="small" type="date" label="To" value={r.effective_to ?? ''} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: 150 }}
              onChange={(e) => set(i, { effective_to: e.target.value || null })} />
            <TextField size="small" label="Description" value={r.description ?? ''} sx={{ minWidth: 160 }} onChange={(e) => set(i, { description: e.target.value || null })} />
            <IconButton size="small" color="error" aria-label="Remove rate" onClick={() => onChange(rates.filter((_, j) => j !== i))}><DeleteOutline fontSize="small" /></IconButton>
          </Stack>
        );
      })}
      <Box><Button size="small" startIcon={<AddIcon />} onClick={() => onChange([...rates, blankRate(currency)])}>Add rate</Button></Box>
    </Stack>
  );
}

export function ClausesEditor({ clauses, onChange }: { clauses: ContractClause[]; onChange: (c: ContractClause[]) => void }) {
  const set = (i: number, patch: Partial<ContractClause>) => onChange(clauses.map((c, j) => (j === i ? { ...c, ...patch } : c)));
  return (
    <Stack spacing={1.25}>
      {clauses.map((c, i) => (
        <Box key={i} sx={{ p: 1.25, border: 1, borderColor: 'divider', borderRadius: 2 }}>
          <Stack direction="row" spacing={1} sx={{ mb: 1 }}>
            <TextField size="small" label="Ref" value={c.clause_ref ?? ''} sx={{ width: 90 }} onChange={(e) => set(i, { clause_ref: e.target.value || null })} />
            <TextField size="small" label="Title" required value={c.title} onChange={(e) => set(i, { title: e.target.value })} />
            <IconButton size="small" color="error" aria-label="Remove clause" onClick={() => onChange(clauses.filter((_, j) => j !== i))}><DeleteOutline fontSize="small" /></IconButton>
          </Stack>
          <TextField size="small" label="Clause text" required multiline minRows={2} value={c.body} onChange={(e) => set(i, { body: e.target.value })} />
        </Box>
      ))}
      <Box><Button size="small" startIcon={<AddIcon />} onClick={() => onChange([...clauses, { clause_ref: String(clauses.length + 1), title: '', body: '' }])}>Add clause</Button></Box>
    </Stack>
  );
}
