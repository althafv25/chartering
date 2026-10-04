import type { Decimal } from './masters';

export interface AisStatus {
  enabled: boolean;
  provider: string;
  health: { ok: boolean; message: string; last_error: string | null };
  stale_hours: number;
  vessels_with_position: number;
  last_received_at: string | null;
}

export interface FleetPosition {
  vessel: { id: number; code: string; name: string };
  latitude: Decimal;
  longitude: Decimal;
  sog_kn: Decimal | null;
  cog_deg: Decimal | null;
  heading_deg: number | null;
  nav_status: string | null;
  destination: string | null;
  eta_reported: string | null;
  observed_at: string;
  provider: string;
  is_stale: boolean;
  voyage: { id: number; voyage_number: string; status: string } | null;
}

export interface AisTrack {
  vessel_id: number;
  from: string;
  to: string;
  point_count: number;
  returned_count: number;
  distance_nm: Decimal;
  distance_basis: string;
  points: { latitude: Decimal; longitude: Decimal; sog_kn: Decimal | null; observed_at: string }[];
}
