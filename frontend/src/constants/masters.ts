export const COMPANY_ROLES = [
  'owner', 'charterer', 'broker', 'customer', 'agent', 'supplier', 'shipyard', 'surveyor', 'port_authority', 'insurer', 'operator', 'other',
] as const;

export const CONSUMPTION_MODES = [
  { value: 'sea_laden', label: 'At sea — laden', speed: true },
  { value: 'sea_ballast', label: 'At sea — ballast', speed: true },
  { value: 'port_working', label: 'In port — working', speed: false },
  { value: 'port_idle', label: 'In port — idle', speed: false },
  { value: 'standby', label: 'Standby', speed: false },
  { value: 'dp_operation', label: 'DP operation', speed: false },
  { value: 'manoeuvring', label: 'Manoeuvring', speed: false },
] as const;

export const OWNERSHIP_TYPES = [
  { value: 'owned', label: 'Owned' },
  { value: 'managed', label: 'Managed' },
  { value: 'chartered_in', label: 'Chartered in' },
  { value: 'third_party', label: 'Third party' },
];

export const VESSEL_RECORD_STATUSES = ['active', 'inactive', 'sold', 'scrapped'] as const;

/** Regex for a non-negative decimal with up to `scale` decimals (UI pre-check; the API validates authoritatively). */
export const decimalPattern = (scale: number) => new RegExp(`^\\d+(\\.\\d{1,${scale}})?$`);
