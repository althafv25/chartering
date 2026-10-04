export interface BunkerStem {
  id: number;
  stem_number: string;
  vessel_id: number;
  voyage_id: number | null;
  port_call_id: number | null;
  port_id: number | null;
  supplier_company_id: number | null;
  fuel_type_id: number;
  ordered_on: string;
  ordered_mt: string;
  delivered_at: string | null;
  delivered_at_local: string | null;
  timezone: string;
  delivered_mt: string | null;
  price_per_mt: string;
  currency: string;
  base_currency: string;
  fx_rate: string | null;
  fx_method: string | null;
  total_amount: string | null;
  base_amount: string | null;
  bdn_number: string | null;
  invoice_reference: string | null;
  status: 'ordered' | 'delivered' | 'invoiced' | 'cancelled';
  remarks: string | null;
  lock_version: number;
  vessel: { id: number; code: string; name: string } | null;
  voyage: { id: number; voyage_number: string; status: string } | null;
  port: { id: number; name: string; unlocode: string } | null;
  port_call: { id: number; label: string } | null;
  supplier: { id: number; legal_name: string } | null;
  fuel_type: { id: number; code: string; name: string } | null;
}

export interface RobRow {
  report_id: number;
  report_type: string;
  period_start: string | null;
  period_end: string;
  opening_mt: string | null;
  received_mt: string;
  consumed_mt: string;
  closing_mt: string | null;
  reported_rob_mt: string | null;
  rob_difference_mt: string | null;
  rob_discontinuity: boolean;
  estimated_consumed_mt: string | null;
  variance_mt: string | null;
  variance_pct: string | null;
  flagged: boolean;
  stems_delivered_mt: string | null;
  received_mismatch: boolean;
}

export interface RobLedger {
  voyage_id: number;
  threshold_pct: string;
  estimate_basis: string | null;
  fuels: {
    fuel_type_id: number;
    fuel_code: string;
    fuel_name: string;
    rows: RobRow[];
    totals: { received_mt: string; consumed_mt: string; stems_mt: string; estimated_mt: string | null };
    stems_outside_reports_mt: string;
    flags: { rob_discontinuity: number; consumption: number; received_mismatch: number };
  }[];
}
