export const CONTRACT_TYPES = [
  { value: 'voyage_charter', label: 'Voyage charter' },
  { value: 'time_charter', label: 'Time charter' },
  { value: 'bareboat', label: 'Bareboat' },
  { value: 'offshore_charter', label: 'Offshore charter' },
  { value: 'service', label: 'Service contract' },
  { value: 'other', label: 'Other' },
] as const;

export const CONTRACT_STATUSES = ['draft', 'under_review', 'approved', 'active', 'completed', 'expired', 'cancelled'] as const;

/** Mirrors App\Models\ContractRate::TYPES / UNIT_OF. */
export const RATE_TYPES = [
  { value: 'day_rate', label: 'Day rate', unit: 'per_day' },
  { value: 'hire_per_day', label: 'Hire per day', unit: 'per_day' },
  { value: 'standby_day_rate', label: 'Standby day rate', unit: 'per_day' },
  { value: 'hourly', label: 'Hourly rate', unit: 'per_hour' },
  { value: 'freight_per_mt', label: 'Freight per MT', unit: 'per_mt' },
  { value: 'lump_sum', label: 'Lump sum', unit: 'lump_sum' },
  { value: 'mobilization_fee', label: 'Mobilization fee', unit: 'lump_sum' },
  { value: 'demobilization_fee', label: 'Demobilization fee', unit: 'lump_sum' },
  { value: 'other', label: 'Other', unit: null },
] as const;

export const RATE_UNITS = ['per_day', 'per_hour', 'per_mt', 'lump_sum'] as const;

export const unitFor = (rateType: string): string | null => RATE_TYPES.find((r) => r.value === rateType)?.unit ?? null;
