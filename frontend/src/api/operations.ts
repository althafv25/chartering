import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { ActivityEntry } from '../types/chartering';
import type { CaptainReport, Comparison, FinanceGates, LaytimeCalculation, Milestone, OffHire, OpsVoyage, PortCall, PortDa, PortDaItem } from '../types/operations';

type Body = Record<string, unknown>;
const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export type VoyageAction = 'complete' | 'finalize' | 'reopen' | 'cancel';

export const voyagesApi = {
  list: (params: ListParams) => page<OpsVoyage>(api.get('/voyages', { params })),
  get: (id: number) => data<OpsVoyage>(api.get(`/voyages/${id}`)),
  update: (id: number, body: Body) => env<OpsVoyage>(api.put(`/voyages/${id}`, body)),
  transition: (id: number, body: { status: string; at?: string | null; note?: string | null }) => env<OpsVoyage>(api.post(`/voyages/${id}/transition`, body)),
  action: (id: number, action: VoyageAction, body: Body = {}) => env<OpsVoyage>(api.post(`/voyages/${id}/${action}`, body)),
  financeGates: (id: number) => data<FinanceGates>(api.get(`/voyages/${id}/finance-gates`)),
  snapshot: (id: number, name: string) => env<unknown>(api.post(`/voyages/${id}/snapshots`, { name })),
  comparison: (id: number) => data<Comparison>(api.get(`/voyages/${id}/comparison`)),
  activity: (id: number, params: ListParams) => page<ActivityEntry>(api.get(`/voyages/${id}/activity`, { params })),

  savePortCall: (id: number, callId: number | null, body: Body) =>
    env<PortCall>(callId ? api.put(`/voyages/${id}/port-calls/${callId}`, body) : api.post(`/voyages/${id}/port-calls`, body)),
  cancelPortCall: (id: number, callId: number, reason: string) => env<PortCall>(api.post(`/voyages/${id}/port-calls/${callId}/cancel`, { reason })),
  deletePortCall: (id: number, callId: number) => env<null>(api.delete(`/voyages/${id}/port-calls/${callId}`)),

  saveMilestone: (id: number, mid: number | null, body: Body) =>
    env<Milestone>(mid ? api.put(`/voyages/${id}/milestones/${mid}`, body) : api.post(`/voyages/${id}/milestones`, body)),
  verifyMilestone: (id: number, mid: number) => env<Milestone>(api.post(`/voyages/${id}/milestones/${mid}/verify`)),
  deleteMilestone: (id: number, mid: number) => env<null>(api.delete(`/voyages/${id}/milestones/${mid}`)),

  saveOffHire: (id: number, eid: number | null, body: Body) =>
    env<OffHire>(eid ? api.put(`/voyages/${id}/off-hire/${eid}`, body) : api.post(`/voyages/${id}/off-hire`, body)),
  decideOffHire: (id: number, eid: number, decision: 'agree' | 'dispute', comment: string | null) =>
    env<OffHire>(api.post(`/voyages/${id}/off-hire/${eid}/${decision}`, { comment })),
  deleteOffHire: (id: number, eid: number) => env<null>(api.delete(`/voyages/${id}/off-hire/${eid}`)),
};

export const captainReportsApi = {
  list: (params: ListParams) => page<CaptainReport>(api.get('/captain-reports', { params })),
  get: (id: number) => data<CaptainReport>(api.get(`/captain-reports/${id}`)),
  save: (id: number | null, body: Body) => env<CaptainReport>(id ? api.put(`/captain-reports/${id}`, body) : api.post('/captain-reports', body)),
  submit: (id: number) => env<CaptainReport>(api.post(`/captain-reports/${id}/submit`)),
  verify: (id: number, body: { comment?: string | null; apply_to_port_call?: boolean }) => env<CaptainReport>(api.post(`/captain-reports/${id}/verify`, body)),
  reject: (id: number, reason: string) => env<CaptainReport>(api.post(`/captain-reports/${id}/reject`, { reason })),
  remove: (id: number) => env<null>(api.delete(`/captain-reports/${id}`)),
};


export const portDasApi = {
  list: (params: ListParams) => page<PortDa>(api.get('/port-das', { params })),
  get: (id: number) => data<PortDa>(api.get(`/port-das/${id}`)),
  create: (body: Body) => env<PortDa>(api.post('/port-das', body)),
  update: (id: number, body: Body) => env<PortDa>(api.put(`/port-das/${id}`, body)),
  saveItems: (id: number, items: Pick<PortDaItem, 'da_cost_category_id' | 'description' | 'estimated_amount' | 'actual_amount' | 'remarks'>[]) => env<PortDa>(api.post(`/port-das/${id}/items`, { items })),
  action: (id: number, action: 'submit' | 'approve' | 'reject') => env<PortDa>(api.post(`/port-das/${id}/${action}`)),
};

export const laytimeApi = {
  list: (params: ListParams) => page<LaytimeCalculation>(api.get('/laytime-calculations', { params })),
  get: (id: number) => data<LaytimeCalculation>(api.get(`/laytime-calculations/${id}`)),
  create: (body: Body) => env<LaytimeCalculation>(api.post('/laytime-calculations', body)),
  update: (id: number, body: Body) => env<LaytimeCalculation>(api.put(`/laytime-calculations/${id}`, body)),
  calculate: (id: number) => env<LaytimeCalculation>(api.post(`/laytime-calculations/${id}/calculate`)),
  action: (id: number, action: 'submit' | 'agree') => env<LaytimeCalculation>(api.post(`/laytime-calculations/${id}/${action}`)),
  dispute: (id: number, reason: string) => env<LaytimeCalculation>(api.post(`/laytime-calculations/${id}/dispute`, { reason })),
  addSofEvent: (id: number, body: Body) => env<LaytimeCalculation>(api.post(`/laytime-calculations/${id}/sof-events`, body)),
  removeSofEvent: (id: number, eventId: number) => env<LaytimeCalculation>(api.delete(`/laytime-calculations/${id}/sof-events/${eventId}`)),
  addException: (id: number, body: Body) => env<LaytimeCalculation>(api.post(`/laytime-calculations/${id}/exceptions`, body)),
  removeException: (id: number, exceptionId: number) => env<LaytimeCalculation>(api.delete(`/laytime-calculations/${id}/exceptions/${exceptionId}`)),
};
