import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { AgingReport, DashboardSummary, ReportCatalogueItem, ReportResult, StatisticMetric, StatisticResult, VoyageFinancials, BalancingAccounts, CashFlow, Invoice, InvoiceLine, Payable, Payment, PaymentAllocation, VoyageExpense, VoyageRevenue } from '../types/finance';

type Body = Record<string, unknown>;
const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export const voyageRevenuesApi = {
  list: (params: ListParams) => page<VoyageRevenue>(api.get('/voyage-revenues', { params })),
  get: (id: number) => data<VoyageRevenue>(api.get(`/voyage-revenues/${id}`)),
  create: (body: Body) => env<VoyageRevenue>(api.post('/voyage-revenues', body)),
  update: (id: number, body: Body) => env<VoyageRevenue>(api.put(`/voyage-revenues/${id}`, body)),
  confirm: (id: number) => env<VoyageRevenue>(api.post(`/voyage-revenues/${id}/confirm`)),
  cancel: (id: number, reason: string) => env<VoyageRevenue>(api.post(`/voyage-revenues/${id}/cancel`, { reason })),
  remove: (id: number) => env<null>(api.delete(`/voyage-revenues/${id}`)),
};

export const voyageExpensesApi = {
  list: (params: ListParams) => page<VoyageExpense>(api.get('/voyage-expenses', { params })),
  get: (id: number) => data<VoyageExpense>(api.get(`/voyage-expenses/${id}`)),
  create: (body: Body) => env<VoyageExpense>(api.post('/voyage-expenses', body)),
  update: (id: number, body: Body) => env<VoyageExpense>(api.put(`/voyage-expenses/${id}`, body)),
  confirm: (id: number) => env<VoyageExpense>(api.post(`/voyage-expenses/${id}/confirm`)),
  approve: (id: number) => env<VoyageExpense>(api.post(`/voyage-expenses/${id}/approve`)),
  cancel: (id: number, reason: string) => env<VoyageExpense>(api.post(`/voyage-expenses/${id}/cancel`, { reason })),
  remove: (id: number) => env<null>(api.delete(`/voyage-expenses/${id}`)),
};

export const invoicesApi = {
  list: (params: ListParams) => page<Invoice>(api.get('/invoices', { params })),
  get: (id: number) => data<Invoice>(api.get(`/invoices/${id}`)),
  create: (body: Body) => env<Invoice>(api.post('/invoices', body)),
  update: (id: number, body: Body) => env<Invoice>(api.put(`/invoices/${id}`, body)),
  saveLines: (id: number, lines: Partial<InvoiceLine>[]) => env<Invoice>(api.post(`/invoices/${id}/lines`, { lines })),
  attachRevenueLines: (id: number, revenueIds: number[]) => env<Invoice>(api.post(`/invoices/${id}/revenue-lines`, { revenue_ids: revenueIds })),
  submit: (id: number) => env<Invoice>(api.post(`/invoices/${id}/submit`)),
  approve: (id: number) => env<Invoice>(api.post(`/invoices/${id}/approve`)),
  reject: (id: number, reason: string) => env<Invoice>(api.post(`/invoices/${id}/reject`, { reason })),
  issue: (id: number) => env<Invoice>(api.post(`/invoices/${id}/issue`)),
  cancel: (id: number, reason: string) => env<Invoice>(api.post(`/invoices/${id}/cancel`, { reason })),
  creditNote: (id: number, reason: string) => env<Invoice>(api.post(`/invoices/${id}/credit-note`, { reason })),
  generatePdf: (id: number) => env<{ id: number; title: string }>(api.post(`/invoices/${id}/pdf`)),
  remove: (id: number) => env<null>(api.delete(`/invoices/${id}`)),
};

export const payablesApi = {
  list: (params: ListParams) => page<Payable>(api.get('/payables', { params })),
  get: (id: number) => data<Payable>(api.get(`/payables/${id}`)),
  create: (body: Body) => env<Payable>(api.post('/payables', body)),
  update: (id: number, body: Body) => env<Payable>(api.put(`/payables/${id}`, body)),
  approve: (id: number) => env<Payable>(api.post(`/payables/${id}/approve`)),
  reapprove: (id: number) => env<Payable>(api.post(`/payables/${id}/reapprove`)),
  cancel: (id: number, reason: string) => env<Payable>(api.post(`/payables/${id}/cancel`, { reason })),
  remove: (id: number) => env<null>(api.delete(`/payables/${id}`)),
};

export const paymentsApi = {
  list: (params: ListParams) => page<Payment>(api.get('/payments', { params })),
  get: (id: number) => data<Payment>(api.get(`/payments/${id}`)),
  create: (body: Body) => env<Payment>(api.post('/payments', body)),
  allocate: (id: number, allocations: { invoice_id?: number; payable_id?: number; amount: string }[]) =>
    env<Payment>(api.post(`/payments/${id}/allocate`, { allocations })),
  unallocate: (id: number, allocationId: number) => env<Payment>(api.delete(`/payments/${id}/allocations/${allocationId}`)),
  reverse: (id: number, reason: string) => env<Payment>(api.post(`/payments/${id}/reverse`, { reason })),
  remove: (id: number) => env<null>(api.delete(`/payments/${id}`)),
};

export const agingApi = {
  /** Without a customer the overview has no invoice rows; pass a customer to get that customer's invoices. */
  report: (asOf?: string, customerId?: number) => data<AgingReport>(api.get('/receivables/aging', { params: { as_of: asOf || undefined, customer_company_id: customerId } })),
};

export const balancingApi = {
  accounts: (params: { company_id?: number; voyage_id?: number } = {}) => data<BalancingAccounts>(api.get('/balancing/accounts', { params })),
  cashFlow: (params: { company_id?: number; voyage_id?: number; from?: string; to?: string } = {}) => data<CashFlow>(api.get('/balancing/cash-flow', { params })),
};

export type { PaymentAllocation };

export const voyageFinancialsApi = {
  get: (voyageId: number) => data<VoyageFinancials>(api.get(`/voyages/${voyageId}/financials`)),
};

export const dashboardApi = {
  summary: () => data<DashboardSummary>(api.get('/dashboard')),
};

export const reportsApi = {
  catalogue: () => data<ReportCatalogueItem[]>(api.get('/reports')),
  run: (slug: string, params: Record<string, string | undefined> = {}) => data<ReportResult>(api.get(`/reports/${slug}`, { params })),
  download: async (slug: string, format: 'csv' | 'xlsx' | 'pdf', params: Record<string, string | undefined> = {}) => {
    const res = await api.get(`/reports/${slug}`, { params: { ...params, format }, responseType: 'blob' });
    const url = URL.createObjectURL(res.data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${slug}.${format}`;
    a.click();
    URL.revokeObjectURL(url);
  },
  statisticMetrics: () => data<StatisticMetric[]>(api.get('/statistics')),
  statistic: (metric: string, params: { group_by?: string; from?: string; to?: string } = {}) => data<StatisticResult>(api.get(`/statistics/${metric}`, { params })),
};
