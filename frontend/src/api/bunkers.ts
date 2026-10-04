import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { BunkerStem, RobLedger } from '../types/bunkers';

type Body = Record<string, unknown>;
const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export const bunkersApi = {
  list: (params: ListParams) => page<BunkerStem>(api.get('/bunker-stems', { params })),
  save: (id: number | null, body: Body) => env<BunkerStem>(id ? api.put(`/bunker-stems/${id}`, body) : api.post('/bunker-stems', body)),
  deliver: (id: number, body: Body) => env<BunkerStem>(api.post(`/bunker-stems/${id}/deliver`, body)),
  cancel: (id: number, reason: string) => env<BunkerStem>(api.post(`/bunker-stems/${id}/cancel`, { reason })),
  robLedger: (voyageId: number) => data<RobLedger>(api.get(`/voyages/${voyageId}/rob-ledger`)),
};
