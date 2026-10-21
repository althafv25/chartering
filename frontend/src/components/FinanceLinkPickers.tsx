import { useQuery } from '@tanstack/react-query';
import { Alert, Box, Checkbox, CircularProgress, FormControlLabel, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { voyagesApi } from '../api/operations';
import { voyageRevenuesApi } from '../api/finance';
import { money } from '../utils/decimal';
import type { OpsVoyage } from '../types/operations';

/**
 * Voyage picker for finance forms. A ledger line, invoice or payable is only part of a voyage's P&L when it is linked to the
 * voyage, so every finance form offers this. Only voyages that are not finalized or cancelled are listed.
 */
export function VoyageSelect({ value, onChange, label = 'Voyage', helperText, disabled, required }: {
  value: number | null; onChange: (voyage: OpsVoyage | null) => void; label?: string; helperText?: string; disabled?: boolean; required?: boolean;
}) {
  const voyages = useQuery({ queryKey: ['voyages', 'open-for-finance'], queryFn: () => voyagesApi.list({ per_page: 100, open: 1 }), staleTime: 60_000 });
  const rows = voyages.data?.data ?? [];
  const known = value !== null && rows.some((v) => v.id === value);

  return (
    <TextField select label={label} value={known ? value : ''} required={required} disabled={disabled || voyages.isLoading} helperText={helperText}
      onChange={(e) => onChange(e.target.value === '' ? null : rows.find((v) => v.id === Number(e.target.value)) ?? null)}>
      {!required && <MenuItem value="">— Not linked to a voyage —</MenuItem>}
      {rows.map((v) => (
        <MenuItem key={v.id} value={v.id}>{v.voyage_number}{v.vessel ? ` · ${v.vessel.name}` : ''}{v.contract ? ` · ${v.contract.contract_number}` : ''}</MenuItem>
      ))}
    </TextField>
  );
}

/**
 * Confirmed, not yet invoiced revenue of a voyage, as a checklist. Revenue in another currency than the invoice cannot be
 * attached (the API rejects it), so it is shown disabled.
 */
export function ConfirmedRevenuePicker({ voyageId, currency, selected, onChange }: {
  voyageId: number | null; currency: string | null; selected: number[]; onChange: (ids: number[]) => void;
}) {
  const revenue = useQuery({
    queryKey: ['voyage-revenues', 'billable', voyageId],
    queryFn: () => voyageRevenuesApi.list({ voyage_id: voyageId!, status: 'confirmed', per_page: 100 }),
    enabled: !!voyageId,
  });
  if (!voyageId) return null;
  if (revenue.isLoading) return <Box sx={{ py: 1.5 }}><CircularProgress size={18} /></Box>;
  const rows = revenue.data?.data ?? [];
  if (rows.length === 0) {
    return <Alert severity="info" sx={{ mt: 0.5 }}>This voyage has no confirmed revenue waiting to be invoiced. Confirm a revenue line in Revenue &amp; Expenses first.</Alert>;
  }
  const toggle = (id: number, on: boolean) => onChange(on ? [...selected, id] : selected.filter((x) => x !== id));

  return (
    <Box sx={{ border: 1, borderColor: 'divider', borderRadius: 1, p: 1.5 }}>
      <Typography variant="subtitle2" gutterBottom>Confirmed revenue to bill ({selected.length} selected)</Typography>
      <Stack>
        {rows.map((r) => {
          const mismatch = !!currency && r.currency !== currency;
          return (
            <FormControlLabel key={r.id} disabled={mismatch}
              control={<Checkbox size="small" checked={selected.includes(r.id)} onChange={(e) => toggle(r.id, e.target.checked)} />}
              label={<span>{r.description} · <strong>{money(r.amount, r.currency)}</strong>{r.category ? ` · ${r.category.name}` : ''}{mismatch ? ` (currency ${r.currency} differs from the invoice)` : ''}</span>} />
          );
        })}
      </Stack>
    </Box>
  );
}
