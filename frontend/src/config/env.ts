export const env = {
  apiUrl: import.meta.env.VITE_API_URL || '/api/v1',
  baseUrl: import.meta.env.VITE_BASE_URL || '/',
  /** Raster tile template for the fleet map (D-010: OpenStreetMap by default; swap for a licensed tile service in production). */
  mapTileUrl: import.meta.env.VITE_MAP_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  appName: import.meta.env.VITE_APP_NAME || 'Offshore Chartering',
};
