import axios, { AxiosError } from 'axios';
import type { ApiErrorBody } from '../types/api';
import { env } from '../config/env';

const TOKEN_KEY = 'offshore_token';

export const tokenStore = {
  get: (): string | null => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

export const api = axios.create({
  baseURL: env.apiUrl,
  headers: { Accept: 'application/json' },
  timeout: 30_000,
});

api.interceptors.request.use((config) => {
  const token = tokenStore.get();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

/** Listeners notified when the API reports the session is no longer valid. */
const unauthorizedListeners = new Set<() => void>();
export const onUnauthorized = (fn: () => void) => {
  unauthorizedListeners.add(fn);
  return () => {
    unauthorizedListeners.delete(fn);
  };
};

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorBody>) => {
    const isLogin = error.config?.url?.includes('/auth/login');
    if (error.response?.status === 401 && !isLogin) {
      tokenStore.clear();
      unauthorizedListeners.forEach((fn) => fn());
    }
    return Promise.reject(toApiError(error));
  },
);

/** Normalised error used across the UI. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fieldErrors: Record<string, string[]>;
  readonly requestId?: string;

  constructor(message: string, status: number, code: string, fieldErrors: Record<string, string[]> = {}, requestId?: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fieldErrors = fieldErrors;
    this.requestId = requestId;
  }
}

function toApiError(error: AxiosError<ApiErrorBody>): ApiError {
  if (!error.response) {
    return new ApiError('Cannot reach the server. Check your connection and try again.', 0, 'network_error');
  }
  const body = error.response.data;
  return new ApiError(
    body?.message || 'Request failed.',
    error.response.status,
    body?.error_code || 'http_error',
    body?.errors ?? {},
    body?.request_id,
  );
}

export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.fieldErrors)[0]?.[0];
    if (error.code === 'validation_failed' && first) return first;
    return error.requestId && error.status >= 500 ? `${error.message} (Ref: ${error.requestId})` : error.message;
  }
  return error instanceof Error ? error.message : fallback;
}
