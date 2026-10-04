import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { ActivitySummary, OffshoreActivity, OffshoreProject } from '../types/offshore';

type Body = Record<string, unknown>;
const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export const offshoreActivitiesApi = {
  list: (params: ListParams) => page<OffshoreActivity>(api.get('/offshore-activities', { params })) as Promise<Paginated<OffshoreActivity> & { summary: ActivitySummary }>,
  get: (id: number) => data<OffshoreActivity>(api.get(`/offshore-activities/${id}`)),
  save: (id: number | null, body: Body) => env<OffshoreActivity>(id ? api.put(`/offshore-activities/${id}`, body) : api.post('/offshore-activities', body)),
  action: (id: number, action: 'submit' | 'verify' | 'reject', body: Body = {}) => env<OffshoreActivity>(api.post(`/offshore-activities/${id}/${action}`, body)),
  remove: (id: number) => env<null>(api.delete(`/offshore-activities/${id}`)),
};

export const offshoreProjectsApi = {
  list: (params: ListParams) => page<OffshoreProject>(api.get('/offshore-projects', { params })),
  get: (id: number) => data<OffshoreProject>(api.get(`/offshore-projects/${id}`)),
  save: (id: number | null, body: Body) => env<OffshoreProject>(id ? api.put(`/offshore-projects/${id}`, body) : api.post('/offshore-projects', body)),
};
