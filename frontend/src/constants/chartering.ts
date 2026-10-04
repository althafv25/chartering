import type { EnquiryStatus, EstimationStatus, RevisionStatus } from '../types/chartering';

export const BUSINESS_TYPES = [
  { value: 'voyage_charter', label: 'Voyage charter' },
  { value: 'time_charter', label: 'Time charter' },
  { value: 'offshore_charter', label: 'Offshore charter' },
  { value: 'cargo_relet', label: 'Cargo relet' },
  { value: 'service', label: 'Service' },
] as const;

export const ESTIMATION_TYPES = [
  { value: 'voyage_charter', label: 'Voyage charter' },
  { value: 'time_charter', label: 'Time charter' },
  { value: 'offshore_day_rate', label: 'Offshore day-rate' },
  { value: 'cargo_relet', label: 'Cargo relet' },
] as const;

export const ENQUIRY_STATUSES: EnquiryStatus[] = ['open', 'evaluating', 'offered', 'fixed', 'lost', 'cancelled'];
export const ESTIMATION_STATUSES: EstimationStatus[] = ['draft', 'submitted', 'approved', 'rejected'];

/** Manual transitions the API accepts (mirrors EnquiryWorkflow::MANUAL). */
export const ENQUIRY_TRANSITIONS: Record<EnquiryStatus, EnquiryStatus[]> = {
  open: ['evaluating', 'offered', 'lost', 'cancelled'],
  evaluating: ['open', 'offered', 'lost', 'cancelled'],
  offered: ['evaluating', 'lost', 'cancelled'],
  fixed: [],
  lost: ['open'],
  cancelled: ['open'],
};

export const RATE_BASES = [
  { value: 'per_mt', label: 'per MT' },
  { value: 'per_day', label: 'per day' },
  { value: 'per_hour', label: 'per hour' },
  { value: 'lump_sum', label: 'lump sum' },
] as const;

export const COST_BASES = [
  { value: 'lump_sum', label: 'Lump sum' },
  { value: 'per_day', label: 'Per day' },
  { value: 'per_mt', label: 'Per MT' },
  { value: 'pct_of_revenue', label: '% of gross revenue' },
] as const;

export const PORT_PURPOSES = ['load', 'discharge', 'bunker', 'supply_base', 'offshore_ops', 'delivery', 'redelivery', 'other'] as const;

export const REVISION_IMMUTABLE: RevisionStatus[] = ['sent', 'received', 'superseded', 'accepted', 'rejected'];

export const labelOf = (list: readonly { value: string; label: string }[], v?: string | null) => list.find((x) => x.value === v)?.label ?? v ?? '—';
