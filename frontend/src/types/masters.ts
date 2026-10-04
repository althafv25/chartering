/** Decimal values are strings end-to-end (never JS numbers) — see docs/14 D-005. */
export type Decimal = string;

export interface CompanyRef {
  id: number;
  code: string;
  legal_name: string;
  country: string | null;
  roles: string[] | null;
}

export interface Contact {
  id: number;
  company_id: number;
  first_name: string;
  last_name: string | null;
  full_name: string;
  job_title: string | null;
  department: string | null;
  email: string | null;
  phone: string | null;
  mobile: string | null;
  is_primary: boolean;
  remarks: string | null;
}

export interface BankAccount {
  id: number;
  bank_name: string;
  account_name: string | null;
  account_number: string | null;
  iban: string | null;
  swift_bic: string | null;
  currency: string | null;
  is_primary: boolean;
  masked: boolean;
}

export interface Company {
  id: number;
  code: string;
  legal_name: string;
  trading_name: string | null;
  roles: string[];
  country: string | null;
  city: string | null;
  address_line1: string | null;
  address_line2: string | null;
  postal_code: string | null;
  email: string | null;
  phone: string | null;
  website: string | null;
  tax_number: string | null;
  vat_registered: boolean;
  default_currency: string | null;
  payment_terms_days: number | null;
  credit_limit: Decimal | null;
  status: 'active' | 'inactive' | 'blocked';
  remarks: string | null;
  lock_version: number;
  contacts_count?: number;
  contacts?: Contact[];
  aliases?: { id: number; alias: string; reason: string; valid_to: string | null }[];
  bank_accounts?: BankAccount[];
  created_at: string | null;
}

export interface PortAgent { company: CompanyRef; is_default: boolean; remarks: string | null }

export interface Port {
  id: number;
  name: string;
  label: string;
  unlocode: string | null;
  country: string;
  region: string | null;
  latitude: Decimal | null;
  longitude: Decimal | null;
  timezone: string;
  max_draft_m: Decimal | null;
  max_loa_m: Decimal | null;
  max_beam_m: Decimal | null;
  restrictions: string | null;
  notes: string | null;
  status: 'active' | 'inactive';
  agents_count?: number;
  agents?: PortAgent[];
}

export interface OffshoreLocation {
  id: number;
  code: string;
  name: string;
  field_name: string | null;
  block: string | null;
  operator: CompanyRef | null;
  operator_company_id: number | null;
  nearest_port: { id: number; label: string } | null;
  nearest_port_id: number | null;
  latitude: Decimal;
  longitude: Decimal;
  water_depth_m: Decimal | null;
  timezone: string;
  remarks: string | null;
  status: 'active' | 'inactive';
}

export type PointType = 'port' | 'location';
export interface RoutePoint { type: PointType; id: number; label: string }

export interface DistanceResult {
  distance_nm: Decimal;
  eca_distance_nm: Decimal;
  provider: string;
  calculated_at: string;
  is_estimate: boolean;
  reversed: boolean;
  notes: string | null;
  from: string;
  to: string;
}

export interface StoredDistance {
  id: number;
  from: RoutePoint;
  to: RoutePoint;
  route_key: string;
  distance_nm: Decimal;
  eca_distance_nm: Decimal;
  provider: string;
  notes: string | null;
  calculated_at: string;
  created_by: string | null;
}

export interface AttributeDef {
  key: string;
  label: string;
  data_type: 'decimal' | 'integer' | 'string' | 'bool' | 'select';
  unit?: string | null;
  options?: string[] | null;
}

export interface ReferenceItem {
  id: number;
  code: string;
  name: string;
  sort_order: number;
  status: 'active' | 'inactive';
  [extra: string]: unknown;
}

export interface VesselTypeItem extends ReferenceItem {
  category: string;
  attribute_schema: AttributeDef[] | null;
}

export interface ReferenceFieldDef {
  type: 'string' | 'bool' | 'select' | 'reference';
  label: string;
  required?: boolean;
  options?: string[];
  reference?: string;
}

export type ReferenceCatalogue = Record<string, { label: string; fields: Record<string, ReferenceFieldDef> }>;

export const VESSEL_DECIMAL_FIELDS = [
  'loa_m', 'lbp_m', 'beam_m', 'depth_m', 'summer_draft_m', 'air_draft_m', 'dwt_mt', 'gt', 'nt',
  'main_engine_power_kw', 'aux_engine_power_kw', 'service_speed_kn', 'max_speed_kn', 'eco_speed_kn',
  'deck_area_m2', 'deck_strength_t_m2', 'bollard_pull_t', 'crane_swl_t',
] as const;
export type VesselDecimalField = (typeof VESSEL_DECIMAL_FIELDS)[number];

export interface Vessel extends Record<VesselDecimalField, Decimal | null> {
  id: number;
  code: string;
  name: string;
  imo_number: string | null;
  mmsi: string | null;
  call_sign: string | null;
  official_number: string | null;
  vessel_type_id: number;
  vessel_type?: { id: number; code: string; name: string; attribute_schema: AttributeDef[] };
  subtype: string | null;
  flag_country: string | null;
  port_of_registry: string | null;
  year_built: number | null;
  builder: string | null;
  class_society: string | null;
  class_notation: string | null;
  ownership_type: 'owned' | 'managed' | 'chartered_in' | 'third_party';
  owner_company_id: number | null;
  manager_company_id: number | null;
  commercial_manager_company_id: number | null;
  technical_manager_company_id: number | null;
  owner?: CompanyRef | null;
  manager?: CompanyRef | null;
  commercial_manager?: CompanyRef | null;
  technical_manager?: CompanyRef | null;
  main_engine: string | null;
  aux_engines: string | null;
  propulsion: string | null;
  dp_class: string | null;
  crew_capacity: number | null;
  passenger_capacity: number | null;
  custom_attributes: Record<string, string | number | boolean>;
  commercial_status: string | null;
  operational_status: string | null;
  crew_management_vessel_id: string | null;
  status: 'active' | 'inactive' | 'sold' | 'scrapped';
  remarks: string | null;
  lock_version: number;
  former_names?: { name: string; valid_to: string }[];
}

export interface ConsumptionRate {
  id?: number;
  mode: string;
  speed_kn: Decimal;
  fuel_type_id: number;
  fuel_type?: { code: string; name: string } | null;
  consumption_mt_per_day: Decimal;
}

export interface ConsumptionProfile {
  id: number;
  vessel_id: number;
  name: string;
  source: 'design' | 'charter_party' | 'observed';
  effective_from: string;
  effective_to: string | null;
  is_default: boolean;
  remarks: string | null;
  rates: ConsumptionRate[];
}

export type StatusTrack = 'commercial' | 'operational';

export interface VesselStatusEntry {
  id: number;
  vessel_id: number;
  track: StatusTrack;
  status: string;
  effective_from: string;
  effective_to: string | null;
  port_id: number | null;
  offshore_location_id: number | null;
  location: string | null;
  reason: string | null;
  remarks: string | null;
  changed_by: { id: number; name: string } | null;
}

export interface StatusBoardRow {
  vessel: { id: number; code: string; name: string; type: string };
  commercial: VesselStatusEntry | null;
  operational: VesselStatusEntry | null;
}

export interface Currency {
  id: number;
  code: string;
  name: string;
  symbol: string | null;
  decimals: number;
  status: 'active' | 'inactive';
  is_base: boolean;
}

export interface ExchangeRate {
  id: number;
  rate_date: string;
  base_currency: string;
  quote_currency: string;
  rate: Decimal;
  source: string;
  remarks: string | null;
  created_by: string | null;
}

export interface FxResolution {
  rate: Decimal;
  method: string;
  rate_date: string | null;
  source: string | null;
  converted_amount?: Decimal;
}

export interface DocumentItem {
  id: number;
  document_type: { id: number; code: string; name: string } | null;
  title: string;
  document_number: string | null;
  issue_date: string | null;
  expiry_date: string | null;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  remarks: string | null;
  uploaded_by: { id: number; name: string } | null;
  created_at: string;
}

export interface RegisterDocument extends DocumentItem { parent: { type: string; id: number; label: string } }

export interface DocumentType { id: number; code: string; name: string; requires_expiry: boolean }
