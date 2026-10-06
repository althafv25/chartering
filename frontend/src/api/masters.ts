import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type {
  AttributeDef, Company, CompanyRef, ConsumptionProfile, Contact, Currency, DistanceResult, DocumentItem, DocumentType,
  ExchangeRate, FxResolution, OffshoreLocation, PointType, Port, ReferenceCatalogue, ReferenceItem, RegisterDocument, RoutePoint,
  StatusBoardRow, StatusTrack, StoredDistance, Vessel, VesselStatusEntry,
} from '../types/masters';

type Body = Record<string, unknown>;
const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export const referenceApi = {
  catalogue: () => data<ReferenceCatalogue>(api.get('/reference')),
  list: <T extends ReferenceItem = ReferenceItem>(type: string, activeOnly = false) =>
    data<T[]>(api.get(`/reference/${type}`, { params: activeOnly ? { active: 1 } : {} })),
  create: (type: string, body: Body) => env<ReferenceItem>(api.post(`/reference/${type}`, body)),
  update: (type: string, id: number, body: Body) => env<ReferenceItem>(api.put(`/reference/${type}/${id}`, body)),
  remove: (type: string, id: number) => env<null>(api.delete(`/reference/${type}/${id}`)),
  saveVesselTypeAttributes: (id: number, attribute_schema: AttributeDef[]) =>
    env<ReferenceItem>(api.put(`/reference/vessel-types/${id}/attributes`, { attribute_schema })),
};

export const currenciesApi = {
  list: (activeOnly = false) => data<Currency[]>(api.get('/currencies', { params: activeOnly ? { active: 1 } : {} })),
  create: (body: Body) => env<Currency>(api.post('/currencies', body)),
  update: (id: number, body: Body) => env<Currency>(api.put(`/currencies/${id}`, body)),
};

export const fxApi = {
  list: (params: ListParams) => page<ExchangeRate>(api.get('/exchange-rates', { params })),
  create: (body: Body) => env<ExchangeRate>(api.post('/exchange-rates', body)),
  update: (id: number, body: Body) => env<ExchangeRate>(api.put(`/exchange-rates/${id}`, body)),
  remove: (id: number) => env<null>(api.delete(`/exchange-rates/${id}`)),
  convert: (params: { from: string; to: string; date: string; amount?: string }) =>
    data<FxResolution>(api.get('/exchange-rates/convert', { params })),
};

export const companiesApi = {
  list: (params: ListParams) => page<Company>(api.get('/companies', { params })),
  lookup: (search: string, role?: string | string[]) => data<CompanyRef[]>(api.get('/companies/lookup', { params: { search, role } })),
  get: (id: number) => data<Company>(api.get(`/companies/${id}`)),
  create: (body: Body) => env<Company>(api.post('/companies', body)),
  update: (id: number, body: Body) => env<Company>(api.put(`/companies/${id}`, body)),
  remove: (id: number) => env<null>(api.delete(`/companies/${id}`)),
  addContact: (id: number, body: Body) => env<Contact>(api.post(`/companies/${id}/contacts`, body)),
  updateContact: (id: number, contactId: number, body: Body) => env<Contact>(api.put(`/companies/${id}/contacts/${contactId}`, body)),
  removeContact: (id: number, contactId: number) => env<null>(api.delete(`/companies/${id}/contacts/${contactId}`)),
  addBank: (id: number, body: Body) => env<{ id: number }>(api.post(`/companies/${id}/bank-accounts`, body)),
  removeBank: (id: number, bankId: number) => env<null>(api.delete(`/companies/${id}/bank-accounts/${bankId}`)),
  addAlias: (id: number, body: Body) => env<unknown>(api.post(`/companies/${id}/aliases`, body)),
  removeAlias: (id: number, aliasId: number) => env<null>(api.delete(`/companies/${id}/aliases/${aliasId}`)),
};

export const portsApi = {
  list: (params: ListParams) => page<Port>(api.get('/ports', { params })),
  lookup: (search: string) => data<{ id: number; label: string; country: string; timezone: string }[]>(api.get('/ports/lookup', { params: { search } })),
  get: (id: number) => data<Port>(api.get(`/ports/${id}`)),
  create: (body: Body) => env<Port>(api.post('/ports', body)),
  update: (id: number, body: Body) => env<Port>(api.put(`/ports/${id}`, body)),
  remove: (id: number) => env<null>(api.delete(`/ports/${id}`)),
  addAgent: (id: number, body: Body) => env<Port>(api.post(`/ports/${id}/agents`, body)),
  removeAgent: (id: number, companyId: number) => env<null>(api.delete(`/ports/${id}/agents/${companyId}`)),
};

export const locationsApi = {
  list: (params: ListParams) => page<OffshoreLocation>(api.get('/offshore-locations', { params })),
  create: (body: Body) => env<OffshoreLocation>(api.post('/offshore-locations', body)),
  update: (id: number, body: Body) => env<OffshoreLocation>(api.put(`/offshore-locations/${id}`, body)),
  remove: (id: number) => env<null>(api.delete(`/offshore-locations/${id}`)),
};

export interface PointPair { from_type: PointType; from_id: number; to_type: PointType; to_id: number }

export const distancesApi = {
  points: (search: string) => data<RoutePoint[]>(api.get('/route-points', { params: { search } })),
  calculate: (body: PointPair) => data<DistanceResult>(api.post('/distances/calculate', body)),
  list: (params: ListParams) => page<StoredDistance>(api.get('/distances', { params })),
  save: (body: PointPair & { distance_nm: string; eca_distance_nm?: string; notes?: string }) => env<unknown>(api.post('/distances', body)),
  remove: (id: number) => env<null>(api.delete(`/distances/${id}`)),
};

export const vesselsApi = {
  list: (params: ListParams) => page<Vessel>(api.get('/vessels', { params })),
  get: (id: number) => data<Vessel>(api.get(`/vessels/${id}`)),
  create: (body: Body) => env<Vessel>(api.post('/vessels', body)),
  update: (id: number, body: Body) => env<Vessel>(api.put(`/vessels/${id}`, body)),
  remove: (id: number) => env<null>(api.delete(`/vessels/${id}`)),
  profiles: (id: number) => data<ConsumptionProfile[]>(api.get(`/vessels/${id}/consumption-profiles`)),
  createProfile: (id: number, body: Body) => env<ConsumptionProfile>(api.post(`/vessels/${id}/consumption-profiles`, body)),
  updateProfile: (id: number, profileId: number, body: Body) =>
    env<ConsumptionProfile>(api.put(`/vessels/${id}/consumption-profiles/${profileId}`, body)),
  removeProfile: (id: number, profileId: number) => env<null>(api.delete(`/vessels/${id}/consumption-profiles/${profileId}`)),
  statusCatalogue: () => data<Record<StatusTrack, string[]>>(api.get('/vessel-status/catalogue')),
  statusBoard: (params: ListParams) => data<StatusBoardRow[]>(api.get('/vessel-status/board', { params })),
  statusHistory: (id: number, params: ListParams) => page<VesselStatusEntry>(api.get(`/vessels/${id}/status-history`, { params })),
  changeStatus: (id: number, body: Body) => env<VesselStatusEntry>(api.post(`/vessels/${id}/status`, body)),
  undoStatus: (id: number, track: StatusTrack) => env<VesselStatusEntry | null>(api.post(`/vessels/${id}/status/undo`, { track })),
};

export const documentsApi = {
  types: () => data<DocumentType[]>(api.get('/document-types')),
  list: (parentType: string, parentId: number, params: ListParams) =>
    page<DocumentItem>(api.get(`/${parentType}/${parentId}/documents`, { params })),
  register: (params: ListParams) => page<RegisterDocument>(api.get('/documents', { params })),
  upload: (parentType: string, parentId: number, form: FormData) => env<DocumentItem>(api.post(`/${parentType}/${parentId}/documents`, form)),
  remove: (id: number) => env<null>(api.delete(`/documents/${id}`)),
  file: (id: number) => api.get<Blob>(`/documents/${id}/download`, { responseType: 'blob' }).then((res) => res.data),
  /** Downloads through the authenticated API (no public file URLs). */
  download: async (doc: DocumentItem) => {
    const res = await api.get(`/documents/${doc.id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = doc.original_filename;
    a.click();
    URL.revokeObjectURL(url);
  },
};
