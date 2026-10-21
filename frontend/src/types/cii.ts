/** CII (Carbon Intensity Indicator) — IMO MARPOL Annex VI. */

export type CiiStatus = 'draft' | 'calculated' | 'verified';
export type CiiRating = 'A' | 'B' | 'C' | 'D' | 'E';
export type CiiScopeStatus = 'in_scope' | 'exempt';
export type CiiFormulaSetStatus = 'draft' | 'verified' | 'retired';

export interface CiiFormulaSetParameters {
  reference_lines: Record<string, { a: number; c: number }>;
  reduction_factors?: Record<string, number>;
  rating_boundaries: Record<string, number>;
  emission_factors: Record<string, number>;
}

export interface CiiFormulaSet {
  id: number;
  code: string;
  name: string;
  status: CiiFormulaSetStatus;
  parameters: CiiFormulaSetParameters;
  effective_date: string;
  expiration_date: string | null;
  remarks: string | null;
  created_at: string;
  updated_at: string;
}

export interface CiiVesselYear {
  id: number;
  vessel_id: number;
  reporting_year: number;
  formula_set_id: number;
  capacity_metric: 'DWT' | 'GT';
  capacity_value: string;
  fuel_consumption: Record<string, string>;
  distance_sailed_nm: string;
  attained_cii: string | null;
  required_cii: string | null;
  rating: CiiRating | null;
  source: 'calculated' | 'manual';
  scope_status: CiiScopeStatus;
  status: CiiStatus;
  calculation_version: string | null;
  calculation_trace: Record<string, unknown> | null;
  remarks: string | null;
  lock_version: number;
  vessel: { id: number; code: string; name: string; imo_number: string | null; dwt_mt: string | null; gt: string | null } | null;
  formula_set: { id: number; code: string; name: string; status: CiiFormulaSetStatus } | null;
  verified_by: { id: number; name: string } | null;
  verified_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface CiiFleetSummary {
  reporting_year: number;
  vessel_count: number;
  by_rating: Record<CiiRating, number>;
  fleet_average_ratio: string | null;
  at_risk_count: number;
}
