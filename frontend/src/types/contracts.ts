import type { CompanyRef, Decimal } from './masters';
import type { Commissions } from './chartering';

export type ContractStatus = 'draft' | 'under_review' | 'approved' | 'active' | 'completed' | 'expired' | 'cancelled';
export type AmendmentStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | 'withdrawn';

export interface ContractRate {
  id?: number;
  version_no?: number;
  rate_type: string;
  offshore_activity_type_id: number | null;
  description: string | null;
  amount: Decimal;
  currency: string;
  unit: string;
  effective_from: string | null;
  effective_to: string | null;
  notes: string | null;
}

export interface ContractClause { id?: number; version_no?: number; sequence?: number; clause_ref: string | null; title: string; body: string }

export interface Amendment {
  id: number;
  contract_id: number;
  amendment_no: number;
  effective_date: string;
  summary: string;
  status: AmendmentStatus;
  proposal: { header?: Record<string, unknown>; rates?: ContractRate[]; clauses?: ContractClause[] };
  changes: Record<string, unknown> | null;
  resulting_version: number | null;
  submitted_at: string | null;
  decided_at: string | null;
  decision_comment: string | null;
  created_by: string | null;
  decided_by: string | null;
  lock_version: number;
  created_at: string;
}

export interface Contract {
  id: number;
  contract_number: string;
  contract_type: string;
  title: string;
  status: ContractStatus;
  is_editable: boolean;
  is_amendable: boolean;
  current_version: number;
  fixture_id: number | null;
  fixture?: { id: number; fixture_number: string } | null;
  customer_company_id: number;
  customer?: CompanyRef;
  vessel_id: number | null;
  vessel?: { id: number; code: string; name: string } | null;
  start_date: string | null;
  end_date: string | null;
  extension_options: string | null;
  currency: string;
  payment_terms_days: number | null;
  payment_terms_text: string | null;
  commissions: Commissions;
  terms: string | null;
  remarks: string | null;
  submitted_by?: { id: number; name: string } | null;
  submitted_at: string | null;
  decided_by?: { id: number; name: string } | null;
  decided_at: string | null;
  decision_comment: string | null;
  activated_at: string | null;
  closed_at: string | null;
  close_reason: string | null;
  lock_version: number;
  rates_visible: boolean;
  rates?: ContractRate[];
  clauses?: ContractClause[];
  versions?: { version_no: number; effective_from: string; amendment_id: number | null; header: Record<string, unknown>; created_at: string }[];
  amendments?: Amendment[];
  created_at: string;
}
