import type { Voyage } from './chartering';

type Who = { id: number; name: string } | null;

export interface PortCall {
  id: number;
  voyage_id: number;
  sequence: number;
  port_id: number | null;
  offshore_location_id: number | null;
  agent_company_id: number | null;
  purpose: string;
  berth: string | null;
  status: string;
  remarks: string | null;
  lock_version: number;
  eta: string | null; etb: string | null; etd: string | null; ata: string | null; atb: string | null; atd: string | null;
  eta_local: string | null; etb_local: string | null; etd_local: string | null; ata_local: string | null; atb_local: string | null; atd_local: string | null;
  timezone: string;
  label: string;
  port: { id: number; name: string; unlocode: string; timezone: string } | null;
  location: { id: number; name: string; timezone: string } | null;
  agent: { id: number; legal_name: string } | null;
}

export interface Milestone {
  id: number;
  voyage_id: number;
  port_call_id: number | null;
  milestone_type_id: number;
  source: string;
  remarks: string | null;
  type: { id: number; code: string; name: string; applies_to: string; is_laytime_relevant: boolean } | null;
  port_call: { id: number; sequence: number; label: string } | null;
  planned_at: string | null;
  actual_at: string | null;
  planned_at_local: string | null;
  actual_at_local: string | null;
  timezone: string;
  verified_by: Who;
  verified_at: string | null;
}

export interface OffHire {
  id: number;
  voyage_id: number;
  reason_code: string;
  description: string | null;
  hours: string | null;
  days: string | null;
  fuel_consumed: { fuel_type_id: number; mt: string }[] | null;
  status: 'draft' | 'agreed' | 'disputed';
  decision_comment: string | null;
  lock_version: number;
  from_at: string;
  to_at: string | null;
  from_at_local: string;
  to_at_local: string | null;
  timezone: string;
  decided_by: Who;
  decided_at: string | null;
}

export interface OpsVoyage extends Voyage {
  remarks: string | null;
  lock_version: number;
  status_reason: string | null;
  reopened_count: number;
  commenced_at: string | null;
  completed_at: string | null;
  finalized_at: string | null;
  finalization_waivers: Record<string, { reason: string; waived_by: number; waived_at: string }> | null;
  cancelled_at: string | null;
  status_changed_at: string | null;
  allowed_transitions: string[];
  can_complete: boolean;
  is_open: boolean;
  charterer?: { id: number; legal_name: string } | null;
  next_port_call?: { id: number; label: string; eta: string | null; status: string } | null;
  port_calls?: PortCall[];
  milestones?: Milestone[];
  off_hires?: OffHire[];
}

export interface FuelLine {
  fuel_type_id: number;
  fuel_code?: string;
  rob_mt: string | null;
  consumed_mt: string;
  received_mt: string;
}

export interface CaptainReport {
  id: number;
  vessel_id: number;
  voyage_id: number | null;
  port_call_id: number | null;
  report_type: string;
  reported_at: string;
  status: 'draft' | 'submitted' | 'verified' | 'rejected';
  is_editable: boolean;
  source: string;
  decision_comment: string | null;
  lock_version: number;
  latitude: string | null;
  longitude: string | null;
  speed_kn: string | null;
  course_deg: number | null;
  distance_since_last_nm: string | null;
  distance_to_go_nm: string | null;
  wind_force_bft: number | null;
  wind_direction: string | null;
  sea_state: string | null;
  weather_text: string | null;
  main_engine_hours: string | null;
  aux_engine_hours: string | null;
  activity_text: string | null;
  delay_hours: string | null;
  delay_reason: string | null;
  remarks: string | null;
  vessel?: { id: number; code: string; name: string };
  voyage?: { id: number; voyage_number: string; status: string } | null;
  port_call?: { id: number; label: string } | null;
  fuel_lines?: FuelLine[];
  submitted_by?: Who;
  submitted_at: string | null;
  verified_by?: Who;
  verified_at: string | null;
  created_at: string;
}

export interface ComparisonColumn { key: string; label: string; type: 'initial' | 'milestone' | 'current' | 'final'; snapshot_id: number | null; created_at: string | null }
export interface ComparisonRow { metric: string; label: string; unit: string; financial: boolean; values: Record<string, string | null>; variance: Record<string, string | null> }
export interface Comparison { currency: string; columns: ComparisonColumn[]; rows: ComparisonRow[]; financials_source: string }

export interface FinanceGate { label: string; passed: boolean; message: string; detail: Record<string, unknown> }
export type FinanceGates = Record<'ledger' | 'revenue' | 'port_da' | 'laytime', FinanceGate>;

export type PortDaStatus = 'draft' | 'submitted' | 'approved' | 'settled';

export interface PortDaItem {
  id: number;
  port_da_id: number;
  da_cost_category_id: number;
  description: string | null;
  estimated_amount: string | null;
  actual_amount: string | null;
  variance_amount: string | null;
  remarks: string | null;
  sequence: number;
  category: { id: number; code: string; name: string } | null;
}

export interface PortDa {
  id: number;
  da_number: string;
  port_call_id: number;
  voyage_id: number;
  port_id: number;
  agent_company_id: number | null;
  da_type: 'proforma' | 'final';
  proforma_da_id: number | null;
  currency: string;
  fx_rate: string | null;
  fx_method: string | null;
  total_amount: string;
  base_amount: string;
  base_currency: string;
  status: PortDaStatus;
  remarks: string | null;
  lock_version: number;
  is_editable: boolean;
  submitted_at: string | null;
  approved_at: string | null;
  created_at: string;
  port: { id: number; name: string; unlocode: string } | null;
  port_call: { id: number; label: string } | null;
  voyage: { id: number; voyage_number: string; status: string } | null;
  agent: { id: number; legal_name: string } | null;
  proforma: { id: number; da_number: string; status: string } | null;
  items?: PortDaItem[];
}

export type LaytimeStatus = 'draft' | 'submitted' | 'agreed' | 'disputed';

export interface LaytimeSofEvent { id: number; event_code: string; description: string | null; source: string | null; event_at: string; event_at_local: string | null }

export interface LaytimeException {
  id: number;
  exception_type: string;
  pct_counted: string;
  remarks: string | null;
  from_at: string;
  to_at: string;
  from_at_local: string | null;
  to_at_local: string | null;
}

export interface LaytimeTraceStep { from: string; to: string; hours: string; pct_counted: string; counted_hours: string; exception_types: string[]; once_on_demurrage?: boolean }

export interface LaytimeCalculation {
  id: number;
  port_call_id: number;
  voyage_id: number;
  contract_id: number | null;
  calculation_type: 'load' | 'discharge' | 'reversible';
  fixed_hours: string | null;
  cargo_quantity: string | null;
  rate_per_day: string | null;
  rate_unit: string | null;
  terms_code: string | null;
  notice_time_hours: string | null;
  demurrage_rate_per_day: string | null;
  despatch_rate_per_day: string | null;
  currency: string | null;
  once_on_demurrage_rule: 'always_on_demurrage' | 'exceptions_apply';
  allowed_hours: string | null;
  used_hours: string | null;
  difference_hours: string | null;
  demurrage_amount: string | null;
  despatch_amount: string | null;
  calculation_version: string | null;
  trace: { used_hours_detail?: LaytimeTraceStep[]; once_on_demurrage_applied?: boolean; [key: string]: unknown } | null;
  status: LaytimeStatus;
  remarks: string | null;
  lock_version: number;
  is_editable: boolean;
  timezone: string;
  nor_tendered_at_local: string | null;
  nor_accepted_at_local: string | null;
  laytime_commenced_at_local: string | null;
  laytime_completed_at_local: string | null;
  calculated_at: string | null;
  agreed_at: string | null;
  port_call: { id: number; label: string } | null;
  voyage: { id: number; voyage_number: string; status: string } | null;
  contract: { id: number; contract_number: string; status: string } | null;
  sof_events?: LaytimeSofEvent[];
  exceptions?: LaytimeException[];
}
