import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { ActivityEntry, Fixture, Voyage } from '../types/chartering';
import type { Amendment, Contract, ContractClause, ContractRate } from '../types/contracts';

type Body = Record<string, unknown>;
const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export const fixtureLifecycleApi = {
  update: (id: number, body: Body) => env<Fixture>(api.put(`/fixtures/${id}`, body)),
  transition: (id: number, action: 'submit' | 'approve' | 'reject' | 'fail' | 'cancel', body: Body = {}) => env<Fixture>(api.post(`/fixtures/${id}/${action}`, body)),
  toContract: (id: number) => env<Contract>(api.post(`/fixtures/${id}/convert-to-contract`)),
  toVoyage: (id: number) => env<Voyage>(api.post(`/fixtures/${id}/convert-to-voyage`)),
  activity: (id: number, params: ListParams) => page<ActivityEntry>(api.get(`/fixtures/${id}/activity`, { params })),
};

export const contractsApi = {
  list: (params: ListParams) => page<Contract>(api.get('/contracts', { params })),
  get: (id: number) => data<Contract>(api.get(`/contracts/${id}`)),
  create: (body: Body) => env<Contract>(api.post('/contracts', body)),
  update: (id: number, body: Body) => env<Contract>(api.put(`/contracts/${id}`, body)),
  saveRates: (id: number, lock_version: number, rates: ContractRate[]) => env<Contract>(api.put(`/contracts/${id}/rates`, { lock_version, rates })),
  saveClauses: (id: number, lock_version: number, clauses: ContractClause[]) => env<Contract>(api.put(`/contracts/${id}/clauses`, { lock_version, clauses })),
  action: (id: number, action: 'submit' | 'approve' | 'reject' | 'activate' | 'complete' | 'cancel', body: Body = {}) =>
    env<Contract>(api.post(`/contracts/${id}/${action}`, body)),
  effectiveRates: (id: number, date: string) => data<{ date: string; version_no: number | null; rates: ContractRate[] }>(api.get(`/contracts/${id}/effective-rates`, { params: { date } })),
  activity: (id: number, params: ListParams) => page<ActivityEntry>(api.get(`/contracts/${id}/activity`, { params })),
  createAmendment: (id: number, body: Body) => env<Amendment>(api.post(`/contracts/${id}/amendments`, body)),
  updateAmendment: (id: number, aid: number, body: Body) => env<Amendment>(api.put(`/contracts/${id}/amendments/${aid}`, body)),
  amendmentAction: (id: number, aid: number, action: 'submit' | 'approve' | 'reject' | 'withdraw', body: Body = {}) =>
    env<Amendment>(api.post(`/contracts/${id}/amendments/${aid}/${action}`, body)),
};
