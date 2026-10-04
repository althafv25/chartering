import { groupDigits } from '../../utils/decimal';
import type { ComparisonRow } from '../../types/operations';

/** Display only — values and variances are calculated by the API. */
export function formatMetric(row: Pick<ComparisonRow, 'unit'>, v: string | null, signed = false): string {
  if (v === null) return '—';
  const isZero = /^-?0*(\.0*)?$/.test(v);
  const sign = signed && !v.startsWith('-') && !isZero ? '+' : '';
  return `${sign}${groupDigits(v)}${row.unit === 'money' ? '' : ` ${row.unit}`}`;
}
