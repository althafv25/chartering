import { describe, expect, it, vi } from 'vitest';
import { CHUNK_RELOAD_KEY, RELOAD_WINDOW_MS, onPreloadError } from './chunkReload';

function memoryStore(): Storage {
  const data = new Map<string, string>();
  return {
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => { data.set(k, v); },
  } as unknown as Storage;
}

const preloadError = () => new Event('vite:preloadError', { cancelable: true });

describe('onPreloadError', () => {
  it('reloads once to pick up the new build, and suppresses the error', () => {
    const store = memoryStore();
    const reload = vi.fn();
    const event = preloadError();
    expect(onPreloadError(event, store, reload, 1_000_000)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(event.defaultPrevented).toBe(true);
    expect(store.getItem(CHUNK_RELOAD_KEY)).toBe('1000000');
  });

  it('does not reload again within the window, so a real outage cannot loop', () => {
    const store = memoryStore();
    const reload = vi.fn();
    onPreloadError(preloadError(), store, reload, 1_000_000);
    const second = preloadError();
    expect(onPreloadError(second, store, reload, 1_000_000 + RELOAD_WINDOW_MS - 1)).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(second.defaultPrevented).toBe(false);
  });

  it('reloads again once the window has passed', () => {
    const store = memoryStore();
    const reload = vi.fn();
    onPreloadError(preloadError(), store, reload, 1_000_000);
    expect(onPreloadError(preloadError(), store, reload, 1_000_000 + RELOAD_WINDOW_MS)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(2);
  });
});
