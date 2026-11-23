/**
 * After a new build is deployed, a tab that was already open still references the previous build's file names. Those
 * files no longer exist, so the lazy-loaded page fails with "Failed to fetch dynamically imported module".
 * Vite reports that failure as a `vite:preloadError` event. We reload the page once to get the new build. The stored
 * timestamp stops a reload loop when the failure is real (for example the server is down).
 */
export const CHUNK_RELOAD_KEY = 'offshore:chunk-reload-at';
export const RELOAD_WINDOW_MS = 10_000;

type Store = Pick<Storage, 'getItem' | 'setItem'>;

/** Returns true when it reloaded. When it does not, the error is left to the normal error screen. */
export function onPreloadError(event: Event, store: Store, reload: () => void, now: number = Date.now()): boolean {
  const last = Number(store.getItem(CHUNK_RELOAD_KEY) ?? 0);
  if (now - last < RELOAD_WINDOW_MS) return false;
  store.setItem(CHUNK_RELOAD_KEY, String(now));
  event.preventDefault();
  reload();
  return true;
}

export function installChunkReload(target: Window = window): void {
  target.addEventListener('vite:preloadError', (event) => {
    onPreloadError(event, target.sessionStorage, () => target.location.reload());
  });
}
