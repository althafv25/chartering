import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { ActivityEntry, CompareRow, Enquiry, Estimation, Fixture, Offer, OfferRevision, Scenario, ScenarioInputs, Voyage } from '../types/chartering';
import type { DocumentItem } from '../types/masters';

type Body = Record<string, unknown>;
const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export const enquiriesApi = {
  list: (params: ListParams) => page<Enquiry>(api.get('/enquiries', { params })),
  get: (id: number) => data<Enquiry>(api.get(`/enquiries/${id}`)),
  create: (body: Body) => env<Enquiry>(api.post('/enquiries', body)),
  update: (id: number, body: Body) => env<Enquiry>(api.put(`/enquiries/${id}`, body)),
  remove: (id: number) => env<null>(api.delete(`/enquiries/${id}`)),
  setStatus: (id: number, status: string, reason?: string) => env<Enquiry>(api.post(`/enquiries/${id}/status`, { status, reason })),
  shortlist: (id: number, body: Body) => env<Enquiry>(api.post(`/enquiries/${id}/vessels`, body)),
  unshortlist: (id: number, vesselId: number) => env<Enquiry>(api.delete(`/enquiries/${id}/vessels/${vesselId}`)),
  activity: (id: number, params: ListParams) => page<ActivityEntry>(api.get(`/enquiries/${id}/activity`, { params })),
};

export const estimationsApi = {
  list: (params: ListParams) => page<Estimation>(api.get('/estimations', { params })),
  get: (id: number) => data<Estimation>(api.get(`/estimations/${id}`)),
  create: (body: Body) => env<Estimation>(api.post('/estimations', body)),
  update: (id: number, body: Body) => env<Estimation>(api.put(`/estimations/${id}`, body)),
  submit: (id: number) => env<Estimation>(api.post(`/estimations/${id}/submit`)),
  approve: (id: number, comment?: string) => env<Estimation>(api.post(`/estimations/${id}/approve`, { comment })),
  reject: (id: number, reason: string) => env<Estimation>(api.post(`/estimations/${id}/reject`, { reason })),
  reopen: (id: number) => env<Estimation>(api.post(`/estimations/${id}/reopen`)),
  clone: (id: number) => env<Estimation>(api.post(`/estimations/${id}/clone`)),
  compare: (id: number) => data<{ currency: string; scenarios: CompareRow[] }>(api.get(`/estimations/${id}/compare`)),
  activity: (id: number, params: ListParams) => page<ActivityEntry>(api.get(`/estimations/${id}/activity`, { params })),
  convertToVoyage: (id: number, reason: string) => env<Voyage>(api.post(`/estimations/${id}/convert-to-voyage`, { reason })),
  scenario: (id: number, sid: number) => data<Scenario>(api.get(`/estimations/${id}/scenarios/${sid}`)),
  addScenario: (id: number, body: { name?: string; clone_from_id?: number }) => env<Scenario>(api.post(`/estimations/${id}/scenarios`, body)),
  saveScenario: (id: number, sid: number, body: { lock_version: number; name?: string; notes?: string | null; inputs?: ScenarioInputs }) =>
    env<Scenario>(api.put(`/estimations/${id}/scenarios/${sid}`, body)),
  calculate: (id: number, sid: number) => env<Scenario>(api.post(`/estimations/${id}/scenarios/${sid}/calculate`)),
  select: (id: number, sid: number) => env<Scenario>(api.post(`/estimations/${id}/scenarios/${sid}/select`)),
  generatePdf: (id: number, sid: number) => env<DocumentItem>(api.post(`/estimations/${id}/scenarios/${sid}/pdf`)),
  refreshDefaults: (id: number, sid: number, body: { vessel?: boolean; consumption?: boolean }) =>
    env<Scenario>(api.post(`/estimations/${id}/scenarios/${sid}/refresh-defaults`, body)),
};

export const offersApi = {
  list: (params: ListParams) => page<Offer>(api.get('/offers', { params })),
  get: (id: number) => data<Offer>(api.get(`/offers/${id}`)),
  create: (body: Body) => env<Offer>(api.post('/offers', body)),
  addRevision: (id: number, body: Body) => env<OfferRevision>(api.post(`/offers/${id}/revisions`, body)),
  updateRevision: (id: number, rid: number, body: Body) => env<OfferRevision>(api.put(`/offers/${id}/revisions/${rid}`, body)),
  send: (id: number, rid: number) => env<OfferRevision>(api.post(`/offers/${id}/revisions/${rid}/send`)),
  generatePdf: (id: number, rid: number) => env<DocumentItem>(api.post(`/offers/${id}/revisions/${rid}/pdf`)),
  receive: (id: number, rid: number) => env<OfferRevision>(api.post(`/offers/${id}/revisions/${rid}/receive`)),
  accept: (id: number, rid: number, note?: string) => env<OfferRevision>(api.post(`/offers/${id}/revisions/${rid}/accept`, { note })),
  reject: (id: number, rid: number, reason: string) => env<OfferRevision>(api.post(`/offers/${id}/revisions/${rid}/reject`, { reason })),
  withdraw: (id: number, reason: string) => env<Offer>(api.post(`/offers/${id}/withdraw`, { reason })),
  diff: (id: number, a: number, b: number) => data<{ from: number; to: number; changes: { field: string; from: unknown; to: unknown }[] }>(api.get(`/offers/${id}/revisions/${a}/diff/${b}`)),
  toFixture: (id: number, rid: number) => env<Fixture>(api.post(`/offers/${id}/revisions/${rid}/convert-to-fixture`)),
  activity: (id: number, params: ListParams) => page<ActivityEntry>(api.get(`/offers/${id}/activity`, { params })),
};

export const fixturesApi = {
  list: (params: ListParams) => page<Fixture>(api.get('/fixtures', { params })),
  get: (id: number) => data<Fixture>(api.get(`/fixtures/${id}`)),
  generatePdf: (id: number) => env<DocumentItem>(api.post(`/fixtures/${id}/pdf`)),
};
