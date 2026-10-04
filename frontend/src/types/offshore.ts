type Ref<T> = T | null;

export interface RevenueLine {
  kind: 'billable' | 'standby' | 'lump_sum';
  rate_type: string;
  contract_rate_id: number | null;
  unit: 'per_day' | 'per_hour' | 'lump_sum';
  hours?: string;
  proration?: string | null;
  quantity: string;
  rate: string;
  currency: string;
  amount: string;
}

export interface OffshoreActivity {
  id: number;
  activity_number: string;
  vessel_id: number;
  voyage_id: number | null;
  contract_id: number | null;
  offshore_project_id: number | null;
  client_company_id: number | null;
  offshore_location_id: number | null;
  offshore_activity_type_id: number;
  description: string | null;
  billable_hours: string;
  non_billable_hours: string;
  standby_hours: string;
  duration_hours: string;
  fuel_used: { fuel_type_id: number; mt: string }[] | null;
  status: 'draft' | 'submitted' | 'verified' | 'invoiced';
  decision_comment: string | null;
  remarks: string | null;
  lock_version: number;
  contract_version_no: number | null;
  calculation_basis: string | null;
  warnings: string[] | null;
  start_at: string;
  end_at: string;
  start_at_local: string;
  end_at_local: string;
  timezone: string;
  is_editable: boolean;
  rates_visible: boolean;
  currency: string | null;
  revenue_amount: string | null;
  rate_snapshot: RevenueLine[] | null;
  vessel: Ref<{ id: number; code: string; name: string }>;
  voyage: Ref<{ id: number; voyage_number: string; status: string }>;
  contract: Ref<{ id: number; contract_number: string; status: string }>;
  project: Ref<{ id: number; code: string; name: string }>;
  client: Ref<{ id: number; legal_name: string }>;
  location: Ref<{ id: number; code: string; name: string; timezone: string }>;
  type: Ref<{ id: number; code: string; name: string; is_billable_default: boolean }>;
  submitted_by: Ref<{ id: number; name: string }>;
  submitted_at: string | null;
  verified_by: Ref<{ id: number; name: string }>;
  verified_at: string | null;
  created_at: string;
}

export interface ActivitySummary {
  count: number;
  billable_hours: string;
  non_billable_hours: string;
  standby_hours: string;
  revenue: { currency: string; amount: string }[] | null;
}

export interface OffshoreProject {
  id: number;
  code: string;
  name: string;
  client_company_id: number;
  contract_id: number | null;
  offshore_location_id: number | null;
  field_name: string | null;
  status: 'planned' | 'active' | 'completed' | 'cancelled';
  remarks: string | null;
  lock_version: number;
  start_date: string | null;
  end_date: string | null;
  client: Ref<{ id: number; legal_name: string }>;
  contract: Ref<{ id: number; contract_number: string; status: string; currency: string }>;
  location: Ref<{ id: number; code: string; name: string }>;
  activities_count: number | null;
  summary?: Omit<ActivitySummary, 'count'>;
}
