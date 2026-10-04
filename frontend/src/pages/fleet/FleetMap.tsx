import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Box } from '@mui/material';
import { env } from '../../config/env';
import { formatDateTime } from '../../utils/format';
import type { AisTrack, FleetPosition } from '../../types/ais';

/** Popup content is built from DOM text nodes (never HTML strings) so vessel names / destinations cannot inject markup. */
function popup(p: FleetPosition): HTMLElement {
  const root = document.createElement('div');
  const title = document.createElement('strong');
  title.textContent = p.vessel.name;
  root.append(title);
  const lines = [
    p.voyage ? `Voyage ${p.voyage.voyage_number}` : 'No active voyage',
    `${p.sog_kn ?? '—'} kn · ${p.destination ?? 'no destination'}`,
    `${formatDateTime(p.observed_at)} (${p.provider})${p.is_stale ? ' — STALE' : ''}`,
  ];
  for (const line of lines) {
    const div = document.createElement('div');
    div.textContent = line;
    root.append(div);
  }

  return root;
}

/** Imperative Leaflet wrapper: circle markers (no image assets), one optional track polyline. */
export function FleetMap({ positions, track, selectedVesselId, onSelect }: {
  positions: FleetPosition[];
  track: AisTrack | null;
  selectedVesselId: number | null;
  onSelect: (vesselId: number) => void;
}) {
  const el = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const markers = useRef<L.LayerGroup | null>(null);
  const trackLayer = useRef<L.LayerGroup | null>(null);

  useEffect(() => {
    if (!el.current || map.current) return;
    const m = L.map(el.current, { worldCopyJump: true }).setView([25, 55], 4);
    L.tileLayer(env.mapTileUrl, { maxZoom: 18, attribution: '&copy; OpenStreetMap contributors' }).addTo(m);
    markers.current = L.layerGroup().addTo(m);
    trackLayer.current = L.layerGroup().addTo(m);
    map.current = m;
    return () => { m.remove(); map.current = null; };
  }, []);

  useEffect(() => {
    const group = markers.current;
    if (!group || !map.current) return;
    group.clearLayers();
    const coords: L.LatLngExpression[] = [];
    for (const p of positions) {
      const ll: L.LatLngExpression = [Number(p.latitude), Number(p.longitude)];
      coords.push(ll);
      const selected = p.vessel.id === selectedVesselId;
      L.circleMarker(ll, {
        radius: selected ? 10 : 7, weight: selected ? 3 : 1.5, color: '#0b3c5d',
        fillColor: p.is_stale ? '#9e9e9e' : '#2e9b5a', fillOpacity: 0.9,
      }).bindPopup(popup(p)).on('click', () => onSelect(p.vessel.id)).addTo(group);
    }
    if (coords.length > 0 && selectedVesselId === null) map.current.fitBounds(L.latLngBounds(coords), { padding: [40, 40], maxZoom: 8 });
  }, [positions, selectedVesselId, onSelect]);

  useEffect(() => {
    const group = trackLayer.current;
    if (!group || !map.current) return;
    group.clearLayers();
    if (!track || track.points.length === 0) return;
    const line = track.points.map((pt) => [Number(pt.latitude), Number(pt.longitude)] as L.LatLngTuple);
    L.polyline(line, { color: '#1976d2', weight: 3 }).addTo(group);
    map.current.fitBounds(L.latLngBounds(line), { padding: [40, 40], maxZoom: 10 });
  }, [track]);

  return <Box ref={el} role="application" aria-label="Fleet map" sx={{ height: { xs: 360, md: 520 }, borderRadius: 1, overflow: 'hidden' }} />;
}
