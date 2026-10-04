import type { CompanyRef, Decimal, RoutePoint } from './masters';

export type BusinessType = 'voyage_charter' | 'time_charter' | 'offshore_charter' | 'cargo_relet' | 'service';
export type EnquiryStatus = 'open' | 'evaluating' | 'offered' | 'fixed' | 'lost' | 'cancelled';
export type EstimationType = 'voyage_charter' | 'time_charter' | 'offshore_day_rate' | 'cargo_relet';
export type EstimationStatus = 'draft' | 'submitted' | 'approved' | 'rejected';
export type CalcStatus = 'not_calculated' | 'calculated' | 'incomplete' | 'stale';
export type RevisionStatus = 'draft' | 'sent' | 'received' | 'superseded' | 'accepted' | 'rejected';
export type RateBasis = 'per_mt' | 'per_day' | 'per_hour' | 'lump_sum';
export type Account = 'owner' | 'charterer';

export interface EnquiryPortRow {
  id?: number;
  sequence?: number;
  purpose: string;
  notes?: string | null;
  port_id: number | null;
  offshore_location_id: number | null;
  point?: RoutePoint;
}

export interface Enquiry {
  id: number;
  enquiry_number: string;
  received_at: string;
  source: 'direct' | 'broker' | 'tender';
  business_type: BusinessType;
  charterer_company_id: number | null;
  broker_company_id: number | null;
  charterer?: CompanyRef | null;
  broker?: CompanyRef | null;
  cargo_type_id: number | null;
  cargo_description: string | null;
  quantity: Decimal | null;
  quantity_unit: string | null;
  quantity_tolerance_pct: Decimal | null;
  offshore_location_id: number | null;
  laycan_from: string | null;
  laycan_to: string | null;
  period_days: Decimal | null;
  rate_idea: Decimal | null;
  rate_basis: RateBasis | null;
  currency: string | null;
  commission_terms: string | null;
  terms: string | null;
  remarks: string | null;
  status: EnquiryStatus;
  lost_reason: string | null;
  is_closed: boolean;
  lock_version: number;
  ports?: EnquiryPortRow[];
  vessels?: { vessel_id: number; shortlist_status: 'candidate' | 'selected' | 'rejected'; notes: string | null;
    vessel: { id: number; code: string; name: string; imo_number: string | null; commercial_status: string | null; operational_status: string | null } }[];
  estimations_count?: number;
  offers_count?: number;
  created_at: string;
}

export interface Money { amount: Decimal; currency: string; fx_rate: Decimal | null; account: Account }

export interface LegInput {
  label: string;
  from?: RoutePoint | null;
  to?: RoutePoint | null;
  condition: 'laden' | 'ballast';
  distance_nm: Decimal | null;
  eca_distance_nm: Decimal | null;
  speed_kn: Decimal | null;
  sea_margin_pct: Decimal | null;
  distance_source?: string | null;
}

export interface CallInput {
  label: string;
  point?: RoutePoint | null;
  purpose?: string | null;
  kind: 'port' | 'offshore';
  working_days: Decimal;
  idle_days: Decimal;
  waiting_days: Decimal;
  dp_days: Decimal;
  standby_days: Decimal;
  port_cost: Money | null;
  agency_cost: Money | null;
}

export interface ConsumptionRow { mode: string; speed_kn: Decimal; fuel_type_id: number; fuel_code?: string; eca_compliant?: boolean; mt_per_day: Decimal }

export interface FuelPrice { fuel_type_id: number; fuel_code?: string; price_per_mt: Decimal | null; currency: string; fx_rate: Decimal | null; account: Account }

export interface RevenueItem {
  key?: string;
  revenue_category_id: number;
  category_code?: string;
  group?: string;
  description: string;
  basis: RateBasis;
  quantity: Decimal | null;
  rate: Decimal | null;
  currency: string;
  fx_rate: Decimal | null;
  commissionable?: boolean;
  address_pct: Decimal;
  brokerage_pct: Decimal;
  other_pct: Decimal;
  primary: boolean;
}

export interface CostItem {
  key?: string;
  expense_category_id: number;
  category_code?: string;
  group?: string;
  description: string;
  basis: 'lump_sum' | 'per_day' | 'per_mt' | 'pct_of_revenue';
  quantity: Decimal | null;
  rate: Decimal | null;
  currency: string;
  fx_rate: Decimal | null;
  account: Account;
}

export interface ScenarioInputs {
  sea_margin_pct: Decimal;
  eca_fuel_type_id: number | null;
  legs: LegInput[];
  calls: CallInput[];
  consumption: ConsumptionRow[];
  fuel_prices: FuelPrice[];
  revenue_items: RevenueItem[];
  cost_items: CostItem[];
}

export interface TraceStep { ref: string; label: string; formula: string; value: string | null }

export interface FuelLine { fuel_type_id: number; code: string; sea_mt: Decimal; port_mt: Decimal; dp_mt: Decimal; standby_mt: Decimal; total_mt: Decimal; cost: Decimal; account: Account }

export interface ScenarioResult {
  calculation_version: string;
  inputs_hash: string;
  sea_distance_nm: Decimal; eca_distance_nm: Decimal; sea_days: Decimal; eca_sea_days: Decimal; port_days: Decimal; total_days: Decimal;
  fuel_total_mt: Decimal; fuel_cost: Decimal; port_costs: Decimal; agency_costs: Decimal; canal_costs: Decimal; other_costs: Decimal;
  operational_costs: Decimal; tonnage_cost: Decimal; gross_revenue: Decimal; total_commission: Decimal; net_revenue: Decimal;
  voyage_costs: Decimal; total_costs: Decimal; profit: Decimal; profit_margin_pct: Decimal | null; profit_per_day: Decimal | null;
  tce_per_day: Decimal | null; breakeven_rate: Decimal | null; breakeven_basis: string | null; breakeven_item: string | null;
  warnings: string[];
  calculated_at: string;
  breakdown?: {
    fuel: FuelLine[];
    legs: Record<string, string>[];
    calls: Record<string, string>[];
    revenue: { key: string; description: string; basis: string; quantity: string; rate: string; currency: string; amount: string; commission_pct: string; commission: string; primary: boolean }[];
    costs: { key: string; description: string; bucket: string; basis: string; account: string; amount: string }[];
    charterer_account: Record<string, string>;
  };
  trace?: TraceStep[];
}

export interface Scenario {
  id: number;
  estimation_id: number;
  code: string;
  name: string;
  is_selected: boolean;
  calc_status: CalcStatus;
  calc_issues: string[];
  calculation_version: string | null;
  inputs_hash: string | null;
  calculated_at: string | null;
  defaults_refreshed_at: string | null;
  cloned_from_id: number | null;
  consumption_profile_id: number | null;
  notes: string | null;
  lock_version: number;
  vessel_snapshot?: Record<string, string | null>;
  inputs?: ScenarioInputs;
  result: ScenarioResult | null;
}

export interface Estimation {
  id: number;
  estimation_number: string;
  estimation_type: EstimationType;
  title: string;
  currency: string;
  status: EstimationStatus;
  is_editable: boolean;
  enquiry_id: number | null;
  enquiry?: { id: number; enquiry_number: string; status: EnquiryStatus; business_type: BusinessType; charterer: string | null } | null;
  vessel_id: number;
  vessel?: { id: number; code: string; name: string; imo_number: string | null };
  submitted_by?: { id: number; name: string } | null;
  submitted_at: string | null;
  decided_by?: { id: number; name: string } | null;
  decided_at: string | null;
  decision_comment: string | null;
  cloned_from?: { id: number; estimation_number: string } | null;
  remarks: string | null;
  lock_version: number;
  scenarios_count?: number;
  selected_scenario?: Scenario | null;
  scenarios?: Scenario[];
  created_at: string;
}

export interface Commissions { address_pct: Decimal; brokerage_pct: Decimal; other_pct: Decimal; broker_company_id: number | null }

export interface OfferRevision {
  id: number;
  offer_id: number;
  revision_no: number;
  direction: 'outbound' | 'inbound';
  status: RevisionStatus;
  is_immutable: boolean;
  is_open: boolean;
  estimation_scenario_id: number | null;
  scenario: { id: number; code: string; name: string; is_selected: boolean; estimation_id: number; estimation_number: string | null; estimation_status: EstimationStatus | null } | null;
  rate: Decimal;
  rate_basis: RateBasis;
  currency: string;
  quantity: Decimal | null;
  quantity_unit: string | null;
  laycan_from: string | null;
  laycan_to: string | null;
  period_days: Decimal | null;
  ports: (RoutePoint & { purpose?: string; sequence?: number })[];
  commissions: Commissions;
  terms: string | null;
  valid_until: string | null;
  remarks: string | null;
  sent_at: string | null;
  received_at: string | null;
  decided_at: string | null;
  decision_reason: string | null;
  created_by?: string | null;
  fixture?: { id: number; fixture_number: string } | null;
  created_at: string;
}

export interface Offer {
  id: number;
  offer_number: string;
  status: 'open' | 'accepted' | 'declined' | 'withdrawn';
  enquiry_id: number;
  enquiry?: { id: number; enquiry_number: string; status: EnquiryStatus; business_type: BusinessType; charterer: string | null };
  vessel_id: number;
  vessel?: { id: number; code: string; name: string };
  counterparty?: CompanyRef | null;
  revisions_count?: number;
  latest_revision: OfferRevision | null;
  revisions?: OfferRevision[];
  created_at: string;
}

export interface Fixture {
  id: number;
  fixture_number: string;
  offer_revision_id: number;
  estimation_scenario_id: number;
  enquiry_id: number;
  vessel_id: number;
  business_type: BusinessType;
  cargo_description: string | null;
  quantity: Decimal | null;
  quantity_unit: string | null;
  rate: Decimal;
  rate_basis: RateBasis;
  currency: string;
  ports: (RoutePoint & { purpose?: string })[];
  commissions: Commissions;
  terms: string | null;
  status: string;
  fixture_date: string;
  laycan_from: string | null;
  laycan_to: string | null;
  vessel?: { id: number; code: string; name: string };
  charterer?: { id: number; code: string; legal_name: string } | null;
  enquiry?: { id: number; enquiry_number: string; status: string };
  recap_snapshot?: Record<string, unknown>;
  remarks?: string | null;
  lock_version: number;
  is_editable: boolean;
  submitted_at: string | null;
  decided_at: string | null;
  decision_comment: string | null;
  submitted_by?: { id: number; name: string } | null;
  decided_by?: { id: number; name: string } | null;
  contract?: { id: number; contract_number: string; status: string } | null;
  voyage?: { id: number; voyage_number: string; status: string } | null;
  charterer_company_id?: number | null;
  created_at: string;
}

export interface Voyage {
  id: number;
  voyage_number: string;
  vessel_id: number;
  fixture_id: number | null;
  estimation_id: number | null;
  estimation_scenario_id: number | null;
  contract_id?: number | null;
  fixture?: { id: number; fixture_number: string } | null;
  contract?: { id: number; contract_number: string; status: string } | null;
  conversion_type: 'fixture' | 'direct_estimation';
  direct_reason: string | null;
  operation_type: string;
  currency: string;
  status: string;
  vessel?: { id: number; code: string; name: string };
  estimation?: { id: number; estimation_number: string; estimation_type: string; status: string } | null;
  scenario?: { id: number; code: string; name: string } | null;
  snapshots?: { id: number; type: string; name: string; calculation_version: string | null; inputs_hash: string | null; payload: Record<string, unknown>; created_at: string }[];
  created_at: string;
}

export interface CompareRow {
  id: number;
  code: string;
  name: string;
  is_selected: boolean;
  calc_status: CalcStatus;
  speed_kn: string[];
  fuel_prices: { code: string | null; price: string | null }[];
  result: ScenarioResult | null;
}

export interface ActivityEntry {
  id: number;
  event: string | null;
  description: string;
  subject_type: string | null;
  causer: { id: number; name: string } | null;
  old: Record<string, unknown> | null;
  new: Record<string, unknown> | null;
  created_at: string;
}
