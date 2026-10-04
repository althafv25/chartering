export const OPERATIONAL_STATUSES = ['draft', 'nominated', 'mobilizing', 'loading', 'loaded', 'sailing', 'discharging', 'offshore_operation', 'standby',
  'demobilizing', 'completed', 'finalized', 'cancelled'] as const;

export const PORT_CALL_PURPOSES = [
  { value: 'load', label: 'Load' }, { value: 'discharge', label: 'Discharge' }, { value: 'bunker', label: 'Bunkering' },
  { value: 'supply', label: 'Supply' }, { value: 'crew_change', label: 'Crew change' }, { value: 'repair', label: 'Repair' },
  { value: 'offshore_ops', label: 'Offshore operations' }, { value: 'other', label: 'Other' },
];

export const OFF_HIRE_REASONS = [
  { value: 'breakdown', label: 'Machinery breakdown' }, { value: 'deficiency', label: 'Deficiency of crew/equipment' }, { value: 'dry_dock', label: 'Dry dock' },
  { value: 'repairs', label: 'Repairs' }, { value: 'crew', label: 'Crew matters' }, { value: 'weather', label: 'Weather' },
  { value: 'detention', label: 'Detention / arrest' }, { value: 'other', label: 'Other' },
];

export const REPORT_TYPES = [
  { value: 'noon', label: 'Noon' }, { value: 'arrival', label: 'Arrival' }, { value: 'departure', label: 'Departure' },
  { value: 'daily', label: 'Daily' }, { value: 'bunker', label: 'Bunker' }, { value: 'offshore_activity', label: 'Offshore activity' },
];

export const REPORT_STATUSES = ['draft', 'submitted', 'verified', 'rejected'] as const;

/** UTC offsets offered for ship's time (captain reports are sent in ship's time). */
export const SHIP_OFFSETS = Array.from({ length: 27 }, (_, i) => {
  const h = i - 12;
  return `${h < 0 ? '-' : '+'}${String(Math.abs(h)).padStart(2, '0')}:00`;
});

export const ACTIVITY_STATUSES = ['draft', 'submitted', 'verified', 'invoiced'] as const;
export const PROJECT_STATUSES = ['planned', 'active', 'completed', 'cancelled'] as const;

/** Server warning codes → user text. */
export const ACTIVITY_WARNINGS: Record<string, string> = {
  no_contract: 'No contract linked — billable or standby hours cannot be priced.',
  no_rates_in_force: 'The contract has no rates in force on the start date.',
  no_billable_rate: 'The contract has no day, hourly or hire rate for this activity.',
  no_standby_rate: 'The contract has no standby day rate.',
  mixed_currency: 'Rates are in different currencies.',
};

/** Suggestions only: the API accepts any code, since charter-party wording differs. */
export const LAYTIME_EXCEPTION_TYPES = ['weather', 'shifting', 'breakdown', 'holiday', 'sunday', 'strike', 'waiting_for_berth', 'other'];
export const SOF_EVENT_CODES = ['ARRIVED', 'NOR_TENDERED', 'NOR_ACCEPTED', 'ALL_FAST', 'COMMENCED_LOADING', 'COMPLETED_LOADING', 'COMMENCED_DISCHARGING', 'COMPLETED_DISCHARGING', 'DOCUMENTS_ONBOARD', 'HOSES_DISCONNECTED', 'SAILED'];
