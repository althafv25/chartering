import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { CiiFleetSummary, CiiFormulaSet, CiiVesselYear } from '../types/cii';

type Body = Record<string, unknown>;
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);

export const ciiApi = {
  list: (params: ListParams) => page<CiiVesselYear>(api.get('/cii-vessel-years', { params })),
  save: (id: number | null, body: Body) => env<CiiVesselYear>(id ? api.put(`/cii-vessel-years/${id}`, body) : api.post('/cii-vessel-years', body)),
  calculate: (id: number) => env<CiiVesselYear>(api.post(`/cii-vessel-years/${id}/calculate`)),
  verify: (id: number) => env<CiiVesselYear>(api.post(`/cii-vessel-years/${id}/verify`)),
  reopen: (id: number, reason: string) => env<CiiVesselYear>(api.post(`/cii-vessel-years/${id}/reopen`, { reason })),
  fleetSummary: (reportingYear: number) => env<CiiFleetSummary>(api.get('/cii/fleet-summary', { params: { reporting_year: reportingYear } })),
  formulaSets: () => env<CiiFormulaSet[]>(api.get('/cii/formula-sets')),
  saveFormulaSet: (id: number | null, body: Body) => env<CiiFormulaSet>(id ? api.put(`/cii/formula-sets/${id}`, body) : api.post('/cii/formula-sets', body)),
  verifyFormulaSet: (id: number) => env<CiiFormulaSet>(api.post(`/cii/formula-sets/${id}/verify`)),
  retireFormulaSet: (id: number) => env<CiiFormulaSet>(api.post(`/cii/formula-sets/${id}/retire`)),
};
