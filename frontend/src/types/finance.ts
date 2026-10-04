import type { CompanyRef, Decimal } from './masters';

export type VoyageRevenueStatus = 'draft' | 'confirmed' | 'invoiced' | 'cancelled';
export type VoyageExpenseStatus = 'draft' | 'confirmed' | 'approved' | 'paid' | 'cancelled';
export type InvoiceStatus = 'draft' | 'submitted' | 'approved' | 'issued' | 'partially_paid' | 'paid' | 'overdue' | 'cancelled';
export type PaymentStatus = 'recorded' | 'reversed';
export type PayableStatus = 'draft' | 'approved' | 'partially_paid' | 'paid' | 'cancelled';

export interface VoyageRevenue {
  id: number;
  voyage_id: number | null;
  contract_id: number | null;
  offshore_activity_id: number | null;
  laytime_calculation_id: number | null;
  revenue_category_id: number;
  description: string;
  is_estimate: boolean;
  quantity: Decimal | null;
  rate: Decimal | null;
  currency: string;
  fx_rate: Decimal;
  amount: Decimal;
  base_amount: Decimal;
  base_currency: string;
  commission_pct_total: Decimal;
  commission_amount: Decimal;
  net_amount: Decimal | null;
  status: VoyageRevenueStatus;
  is_editable: boolean;
  service_period_from: string | null;
  service_period_to: string | null;
  remarks: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
  voyage: { id: number; voyage_number: string; status: string } | null;
  contract: { id: number; contract_number: string; status: string } | null;
  offshore_activity: { id: number; activity_number: string; status: string } | null;
  laytime_calculation: { id: number; status: string } | null;
  category: { id: number; code: string; name: string } | null;
  invoice_line?: { id: number; invoice_id: number } | null;
}

export interface VoyageExpense {
  id: number;
  voyage_id: number | null;
  contract_id: number | null;
  expense_category_id: number;
  source_type: string | null;
  source_id: number | null;
  description: string;
  is_estimate: boolean;
  quantity: Decimal | null;
  rate: Decimal | null;
  currency: string;
  fx_rate: Decimal;
  amount: Decimal;
  base_amount: Decimal;
  base_currency: string;
  supplier_company_id: number | null;
  status: VoyageExpenseStatus;
  is_editable: boolean;
  incurred_at: string | null;
  remarks: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
  voyage: { id: number; voyage_number: string; status: string } | null;
  contract: { id: number; contract_number: string; status: string } | null;
  category: { id: number; code: string; name: string } | null;
  supplier: { id: number; legal_name: string } | null;
  source?: { type: string; id: number } | null;
}

export interface InvoiceLine {
  id: number;
  invoice_id: number;
  sequence: number;
  voyage_revenue_id: number | null;
  description: string;
  quantity: Decimal | null;
  unit: string | null;
  rate: Decimal;
  amount: Decimal;
  tax_code_id: number | null;
  tax_rate_pct: Decimal;
  tax_amount: Decimal;
  line_total: Decimal;
  tax_code: { id: number; code: string; name: string; rate_pct: Decimal } | null;
  voyage_revenue?: { id: number; description: string; status: VoyageRevenueStatus } | null;
}

export interface PaymentAllocation {
  id: number;
  payment_id: number;
  invoice_id: number | null;
  payable_id: number | null;
  allocated_amount: Decimal;
  invoice_ccy_amount: Decimal | null;
  fx_difference_base: Decimal | null;
  created_by: number | null;
  created_at: string;
  invoice?: { id: number; invoice_number: string | null; status: InvoiceStatus; currency: string; total: Decimal } | null;
  payable?: { id: number; payable_number: string; status: PayableStatus; currency: string; total: Decimal } | null;
  payment?: { id: number; payment_number: string; direction: 'received' | 'paid'; currency: string } | null;
}

export interface Invoice {
  id: number;
  invoice_number: string | null;
  invoice_type: 'freight' | 'hire' | 'offshore_service' | 'demurrage' | 'other' | 'credit_note';
  customer_company_id: number;
  billing_snapshot: { name: string; address: string; tax_no: string | null; country: string | null } | null;
  contract_id: number | null;
  voyage_id: number | null;
  currency: string;
  fx_rate: Decimal;
  subtotal: Decimal;
  tax_amount: Decimal;
  total: Decimal;
  base_total: Decimal;
  base_currency: string;
  amount_paid: Decimal;
  balance: Decimal;
  status: InvoiceStatus;
  is_editable: boolean;
  is_credit_note: boolean;
  line_count: number | null;
  issue_date: string | null;
  due_date: string | null;
  is_overdue: boolean;
  cancelled_reason: string | null;
  credit_note_for_id: number | null;
  pdf_document_id: number | null;
  remarks: string | null;
  lock_version: number;
  submitted_by: number | null;
  submitted_at: string | null;
  approved_by: number | null;
  approved_at: string | null;
  issued_by: number | null;
  issued_at: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
  customer: CompanyRef | null;
  contract: { id: number; contract_number: string; status: string } | null;
  voyage: { id: number; voyage_number: string; status: string } | null;
  credit_note_for: { id: number; invoice_number: string | null; status: InvoiceStatus } | null;
  credit_notes?: { id: number; invoice_number: string | null; status: InvoiceStatus; total: Decimal }[];
  lines?: InvoiceLine[];
  allocations?: PaymentAllocation[];
}

export interface Payment {
  id: number;
  payment_number: string;
  company_id: number;
  amount: Decimal;
  currency: string;
  fx_rate: Decimal;
  base_amount: Decimal;
  base_currency: string;
  unallocated_amount: Decimal;
  allocated_amount: Decimal;
  bank_account_ref: string | null;
  bank_reference: string | null;
  method: 'wire' | 'check' | 'credit_card' | 'cash' | 'other' | null;
  remarks: string | null;
  direction: 'received' | 'paid';
  status: PaymentStatus;
  is_reversed: boolean;
  payment_date: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
  company: CompanyRef | null;
  allocations?: PaymentAllocation[];
}

export interface Payable {
  id: number;
  payable_number: string;
  supplier_company_id: number;
  supplier_invoice_ref: string;
  voyage_id: number | null;
  currency: string;
  fx_rate: Decimal;
  subtotal: Decimal;
  tax: Decimal;
  total: Decimal;
  base_total: Decimal;
  base_currency: string;
  amount_paid: Decimal;
  balance: Decimal;
  status: PayableStatus;
  is_editable: boolean;
  issue_date: string | null;
  due_date: string | null;
  is_overdue: boolean;
  remarks: string | null;
  lock_version: number;
  approved_by: number | null;
  approved_at: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
  supplier: CompanyRef | null;
  voyage: { id: number; voyage_number: string; status: string } | null;
  allocations?: PaymentAllocation[];
  voyage_expenses?: { id: number; status: VoyageExpenseStatus; amount: Decimal; base_amount: Decimal }[];
}

export interface AgingInvoiceRow {
  id: number;
  invoice_number: string | null;
  due_date: string;
  currency: string;
  balance: Decimal;
  base_balance: Decimal;
  days_overdue: number;
  bucket: 'current' | '1_30' | '31_60' | '61_90' | 'over_90';
}

export interface AgingBuckets {
  current: Decimal;
  '1_30': Decimal;
  '31_60': Decimal;
  '61_90': Decimal;
  over_90: Decimal;
}

export interface AgingCustomer {
  customer_company_id: number;
  customer_name: string;
  buckets: AgingBuckets;
  total: Decimal;
  invoices: AgingInvoiceRow[];
}

export interface AgingReport {
  as_of: string;
  base_currency: string;
  customers: AgingCustomer[];
  totals: AgingBuckets & { grand_total: Decimal };
}

export interface BalancingAccount {
  company_id: number;
  company_name: string | null;
  receivable: Decimal;
  payable: Decimal;
  net: Decimal;
  receivable_count: number;
  payable_count: number;
}

export interface BalancingAccounts {
  base_currency: string;
  accounts: BalancingAccount[];
  totals: { receivable: Decimal; payable: Decimal; net: Decimal };
}

export interface CashFlowPeriod {
  period: string;
  label: string;
  receivable: Decimal;
  payable: Decimal;
  net: Decimal;
}

export interface CashFlow {
  base_currency: string;
  from: string;
  to: string;
  periods: CashFlowPeriod[];
  totals: { receivable: Decimal; payable: Decimal; net: Decimal };
}

export interface VoyageFinancials {
  voyage_id: number;
  base_currency: string;
  rows: { metric: string; label: string; estimate: Decimal | null; actual: Decimal; variance: Decimal | null; variance_pct: Decimal | null }[];
  gross_profit: Decimal;
  margin_pct: Decimal | null;
  profit_per_day: Decimal | null;
  total_days: Decimal | null;
  revenue_by_category: { category: string; amount: Decimal }[];
  expense_by_category: { category: string; amount: Decimal }[];
  estimate_available: boolean;
  notes: string[];
}

export interface ReportCatalogueItem { slug: string; title: string; filters: string[]; formats: ('json' | 'csv' | 'xlsx' | 'pdf')[] }

export interface ReportResult {
  slug: string;
  title: string;
  base_currency: string;
  columns: { key: string; label: string; align: 'left' | 'right' }[];
  rows: Record<string, string | null>[];
  truncated: boolean;
  row_limit: number;
}

export interface StatisticMetric { metric: string; label: string; unit: 'money' | 'hours'; groups: string[] }

export interface StatisticResult {
  metric: string;
  label: string;
  unit: 'money' | 'hours';
  group_by: string;
  base_currency: string | null;
  rows: { label: string; total: Decimal; line_count: number }[];
  total: Decimal;
}

export interface DashboardSummary {
  base_currency: string;
  active_vessels: number;
  active_voyages: number;
  pending_approvals: Record<string, number>;
  outstanding_invoices?: { count: number; base_total: Decimal; overdue_count: number; overdue_base_total: Decimal };
  open_payables?: { count: number; base_total: Decimal; overdue_count: number };
}
