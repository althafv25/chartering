import { api } from './client';
import type { ApiEnvelope } from '../types/api';
import type { AisStatus, AisTrack, FleetPosition } from '../types/ais';

const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const env = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

export interface ManualPositionBody {
  vessel_id: number;
  latitude: string;
  longitude: string;
  observed_at: string;
  sog_kn?: string;
  cog_deg?: string;
  destination?: string;
}

export const aisApi = {
  status: () => data<AisStatus>(api.get('/ais/status')),
  fleet: () => data<FleetPosition[]>(api.get('/ais/fleet')),
  track: (vesselId: number, from: string, to: string) => data<AisTrack>(api.get(`/ais/vessels/${vesselId}/track`, { params: { from, to } })),
  recordManual: (body: ManualPositionBody) => env<unknown>(api.post('/ais/positions/manual', body)),
};
