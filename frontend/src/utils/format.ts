import { format, formatDistanceToNow, parseISO } from 'date-fns';

export const formatDateTime = (iso?: string | null) => (iso ? format(parseISO(iso), 'dd MMM yyyy, HH:mm') : '—');
export const formatDate = (iso?: string | null) => (iso ? format(parseISO(iso), 'dd MMM yyyy') : '—');
export const timeAgo = (iso?: string | null) => (iso ? formatDistanceToNow(parseISO(iso), { addSuffix: true }) : '—');

export const humanize = (value: string) =>
  value.replace(/[._-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export const initials = (name: string) =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('') || '?';

/** Formats a decimal string with a unit for display (no float conversion). */
export const withUnit = (v: string | null | undefined, unit: string) => (v === null || v === undefined || v === '' ? null : `${v} ${unit}`);

/** Port-local naive time from the API ("2026-10-15T08:00") → "15 Oct 2026, 08:00". No timezone conversion in the browser (G-06: the API converts). */
export const formatLocal = (local?: string | null) => (local ? format(parseISO(local), 'dd MMM yyyy, HH:mm') : '—');

/** Short IANA zone label, e.g. "Asia/Dubai" → "Dubai". */
export const zoneLabel = (tz: string) => tz.split('/').pop()?.replace(/_/g, ' ') ?? tz;
