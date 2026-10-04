import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Autocomplete, MenuItem, TextField, type TextFieldProps } from '@mui/material';
import { companiesApi, currenciesApi, distancesApi, portsApi, referenceApi } from '../api/masters';
import { COUNTRIES } from '../constants/countries';
import { useDebounce } from '../hooks/useDebounce';
import type { CompanyRef, RoutePoint } from '../types/masters';

type FieldProps = { label: string; error?: string; required?: boolean; disabled?: boolean };

/** ISO country picker storing the 2-letter code. */
export function CountrySelect({ value, onChange, label, error, required, disabled }: FieldProps & { value: string | null; onChange: (v: string | null) => void }) {
  const selected = COUNTRIES.find((c) => c.code === value) ?? null;
  return (
    <Autocomplete
      size="small"
      options={COUNTRIES}
      value={selected}
      disabled={disabled}
      onChange={(_, v) => onChange(v?.code ?? null)}
      getOptionLabel={(o) => `${o.name} (${o.code})`}
      isOptionEqualToValue={(a, b) => a.code === b.code}
      renderInput={(p) => <TextField {...p} label={label} required={required} error={!!error} helperText={error} />}
    />
  );
}

/** Async company search filtered by address-book role. Value is the company id. */
export function CompanyAutocomplete({ value, initial, onChange, role, label, error, required, disabled }: FieldProps & {
  value: number | null;
  initial?: CompanyRef | null;
  onChange: (id: number | null, company: CompanyRef | null) => void;
  role?: string;
}) {
  const [input, setInput] = useState('');
  const [picked, setPicked] = useState<CompanyRef | null>(initial ?? null);
  const search = useDebounce(input, 300);
  const { data = [], isFetching } = useQuery({ queryKey: ['companies', 'lookup', role, search], queryFn: () => companiesApi.lookup(search, role) });

  const current = value ? (picked?.id === value ? picked : data.find((c) => c.id === value) ?? initial ?? null) : null;
  const options = current && !data.some((c) => c.id === current.id) ? [current, ...data] : data;

  return (
    <Autocomplete
      size="small"
      options={options}
      value={current}
      loading={isFetching}
      disabled={disabled}
      filterOptions={(x) => x}
      onInputChange={(_, v, reason) => reason === 'input' && setInput(v)}
      onChange={(_, v) => { setPicked(v); onChange(v?.id ?? null, v); }}
      getOptionLabel={(o) => `${o.legal_name} · ${o.code}`}
      isOptionEqualToValue={(a, b) => a.id === b.id}
      noOptionsText={role ? `No ${role.replace('_', ' ')} found in the address book` : 'No companies found'}
      renderInput={(p) => <TextField {...p} label={label} required={required} error={!!error} helperText={error} />}
    />
  );
}

/** Async picker for ports + offshore locations. */
export function RoutePointAutocomplete({ value, onChange, label, error }: FieldProps & { value: RoutePoint | null; onChange: (v: RoutePoint | null) => void }) {
  const [input, setInput] = useState('');
  const search = useDebounce(input, 300);
  const { data = [], isFetching } = useQuery({ queryKey: ['route-points', search], queryFn: () => distancesApi.points(search) });
  const options = value && !data.some((p) => p.type === value.type && p.id === value.id) ? [value, ...data] : data;

  return (
    <Autocomplete
      size="small"
      options={options}
      value={value}
      loading={isFetching}
      filterOptions={(x) => x}
      onInputChange={(_, v, reason) => reason === 'input' && setInput(v)}
      onChange={(_, v) => onChange(v)}
      getOptionLabel={(o) => o.label}
      groupBy={(o) => (o.type === 'port' ? 'Ports' : 'Offshore locations')}
      isOptionEqualToValue={(a, b) => a.type === b.type && a.id === b.id}
      renderInput={(p) => <TextField {...p} label={label} error={!!error} helperText={error} />}
    />
  );
}

/** Port picker (value = port id). */
export function PortAutocomplete({ value, initialLabel, onChange, label, error }: FieldProps & { value: number | null; initialLabel?: string | null; onChange: (id: number | null) => void }) {
  const [input, setInput] = useState('');
  const search = useDebounce(input, 300);
  const { data = [], isFetching } = useQuery({ queryKey: ['ports', 'lookup', search], queryFn: () => portsApi.lookup(search) });
  const known = data.find((p) => p.id === value);
  const current = value ? known ?? { id: value, label: initialLabel ?? `Port #${value}`, country: '', timezone: '' } : null;
  const options = current && !known ? [current, ...data] : data;

  return (
    <Autocomplete
      size="small"
      options={options}
      value={current}
      loading={isFetching}
      filterOptions={(x) => x}
      onInputChange={(_, v, reason) => reason === 'input' && setInput(v)}
      onChange={(_, v) => onChange(v?.id ?? null)}
      getOptionLabel={(o) => o.label}
      isOptionEqualToValue={(a, b) => a.id === b.id}
      renderInput={(p) => <TextField {...p} label={label} error={!!error} helperText={error} />}
    />
  );
}

/** Select bound to an active reference list (fuel types, vessel types, ...). */
export function ReferenceSelect({ type, value, onChange, label, error, required, disabled, allowEmpty, ...rest }: FieldProps & {
  type: string;
  value: number | '' | null;
  onChange: (id: number | null) => void;
  allowEmpty?: boolean;
} & Omit<TextFieldProps, 'onChange' | 'value' | 'error'>) {
  const { data = [] } = useQuery({ queryKey: ['reference', type, 'active'], queryFn: () => referenceApi.list(type, true), staleTime: 5 * 60_000 });
  return (
    <TextField
      select
      {...rest}
      label={label}
      required={required}
      disabled={disabled}
      value={value ?? ''}
      error={!!error}
      helperText={error}
      onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
    >
      {(allowEmpty || !required) && <MenuItem value="">—</MenuItem>}
      {data.map((r) => <MenuItem key={r.id} value={r.id}>{r.name}</MenuItem>)}
    </TextField>
  );
}

export function CurrencySelect({ value, onChange, label, error, required, disabled }: FieldProps & { value: string | null; onChange: (v: string | null) => void }) {
  const { data = [] } = useQuery({ queryKey: ['currencies', 'active'], queryFn: () => currenciesApi.list(true), staleTime: 5 * 60_000 });
  return (
    <TextField select label={label} required={required} disabled={disabled} value={value ?? ''} error={!!error} helperText={error}
      onChange={(e) => onChange(e.target.value || null)}>
      {!required && <MenuItem value="">—</MenuItem>}
      {data.map((c) => <MenuItem key={c.code} value={c.code}>{c.code} — {c.name}</MenuItem>)}
    </TextField>
  );
}
