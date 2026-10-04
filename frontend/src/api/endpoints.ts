import { api } from './client';
import type { ApiEnvelope, ListParams, Paginated } from '../types/api';
import type { AppNotification, AuditLog, AuthUser, PermissionGroup, Role, SettingsGroups, SettingValue, User, UserStatus } from '../types/models';

const data = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data);
const page = <T>(p: Promise<{ data: Paginated<T> }>) => p.then((r) => r.data);
const envelope = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data);

// ── Auth ───────────────────────────────────────────────────
export interface LoginResult { token: string; expires_at: string | null; user: AuthUser }

export const authApi = {
  login: (email: string, password: string) => data<LoginResult>(api.post('/auth/login', { email, password, device_name: 'web' })),
  me: () => data<AuthUser>(api.get('/auth/me')),
  logout: () => api.post('/auth/logout'),
  forgot: (email: string) => envelope<null>(api.post('/auth/forgot-password', { email })),
  reset: (body: { email: string; token: string; password: string; password_confirmation: string }) =>
    envelope<null>(api.post('/auth/reset-password', body)),
  changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
    envelope<null>(api.post('/auth/change-password', body)),
  updateProfile: (body: Partial<Pick<User, 'first_name' | 'last_name' | 'phone' | 'job_title' | 'timezone'>>) =>
    data<AuthUser>(api.put('/profile', body)),
};

// ── Users ──────────────────────────────────────────────────
export interface UserPayload {
  first_name: string;
  last_name?: string | null;
  email: string;
  phone?: string | null;
  job_title?: string | null;
  timezone?: string;
  roles: string[];
  password?: string;
  password_confirmation?: string;
}

export const usersApi = {
  list: (params: ListParams) => page<User>(api.get('/users', { params })),
  get: (id: number) => data<User>(api.get(`/users/${id}`)),
  create: (body: UserPayload) => envelope<User>(api.post('/users', body)),
  update: (id: number, body: Partial<UserPayload>) => envelope<User>(api.put(`/users/${id}`, body)),
  setStatus: (id: number, status: UserStatus) => envelope<User>(api.patch(`/users/${id}/status`, { status })),
  remove: (id: number) => envelope<null>(api.delete(`/users/${id}`)),
};

// ── Roles ──────────────────────────────────────────────────
export const rolesApi = {
  list: () => data<Role[]>(api.get('/roles')),
  catalogue: () => data<PermissionGroup[]>(api.get('/roles/permissions')),
  create: (body: { name: string; permissions: string[] }) => envelope<Role>(api.post('/roles', body)),
  update: (id: number, body: { name?: string; permissions: string[] }) => envelope<Role>(api.put(`/roles/${id}`, body)),
  remove: (id: number) => envelope<null>(api.delete(`/roles/${id}`)),
};

// ── Settings / audit / notifications ──────────────────────
export const settingsApi = {
  get: () => data<SettingsGroups>(api.get('/settings')),
  update: (settings: Record<string, SettingValue>) => envelope<SettingsGroups>(api.put('/settings', { settings })),
};

export const auditApi = {
  list: (params: ListParams) => page<AuditLog>(api.get('/audit-logs', { params })),
  filters: () => data<{ log_names: string[]; events: string[] }>(api.get('/audit-logs/filters')),
};

export const notificationsApi = {
  list: (params: ListParams & { unread?: boolean }) => page<AppNotification>(api.get('/notifications', { params })),
  unreadCount: () => data<{ count: number }>(api.get('/notifications/unread-count')),
  markRead: (id: number) => data<AppNotification>(api.post(`/notifications/${id}/read`)),
  markAllRead: () => envelope<{ updated: number }>(api.post('/notifications/read-all')),
};
