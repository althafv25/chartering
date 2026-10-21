/**
 * Display-only helpers for decimal strings returned by the API.
 * They never perform arithmetic — all money is calculated by the backend.
 */

/** Groups thousands of a decimal string without converting to a float: "1234567.50" → "1,234,567.50". */
export function groupDigits(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const m = /^(-?)(\d+)(\.\d+)?$/.exec(value);
  if (!m) return value;
  return `${m[1]}${m[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${m[3] ?? ''}`;
}

export const money = (value: string | null | undefined, currency?: string) =>
  value === null || value === undefined || value === '' ? '—' : `${groupDigits(value)}${currency ? ` ${currency}` : ''}`;

/** Trims trailing zeros for display: "12.500000" → "12.5". */
export function trimZeros(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  if (!value.includes('.')) return value;
  const t = value.replace(/0+$/, '').replace(/\.$/, '');
  return t === '-0' ? '0' : t;
}

/**
 * Rate / unit price for display: thousands grouped, at least 2 decimals, extra precision kept only when it is
 * significant. "55.0000" → "55.00", "4.6231" → "4.6231", "1200" → "1,200.00".
 */
export function rate(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const t = trimZeros(value);
  const [i, f = ''] = t.split('.');
  return groupDigits(`${i}.${f.padEnd(2, '0')}`);
}

/** Quantity for display: thousands grouped, trailing zeros dropped. "2000.000" → "2,000", "2500.500" → "2,500.5". */
export const quantity = (value: string | null | undefined) => groupDigits(trimZeros(value) === '—' ? null : trimZeros(value));

/** Days with at most `dp` decimals, by string truncation-free rounding is backend's job — here we only shorten long scales. */
export function days(value: string | null | undefined, dp = 2): string {
  if (value === null || value === undefined || value === '') return '—';
  const [i, f = ''] = value.split('.');
  if (f.length <= dp) return trimZeros(value);
  // Display rounding (half-up) on the decimal string; not used for any calculation.
  const scaled = BigInt(i.replace('-', '') + f.slice(0, dp)) + (Number(f[dp]) >= 5 ? 1n : 0n);
  const s = scaled.toString().padStart(dp + 1, '0');
  const out = `${value.startsWith('-') ? '-' : ''}${s.slice(0, -dp)}.${s.slice(-dp)}`;
  return trimZeros(out);
}

export const isNegative = (value: string | null | undefined) => !!value && value.trim().startsWith('-') && !/^-0*(\.0*)?$/.test(value.trim());

export const pct = (value: string | null | undefined) => (value === null || value === undefined ? '—' : `${days(value, 2)} %`);

/** True when the string is a non-negative decimal with at most `scale` decimals (UI pre-check only). */
export const isDecimal = (value: string, scale: number) => new RegExp(`^\\d+(\\.\\d{1,${scale}})?$`).test(value);
