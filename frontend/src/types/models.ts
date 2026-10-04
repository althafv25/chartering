export type UserStatus = 'active' | 'inactive';

export interface User {
  id: number;
  name: string;
  first_name: string | null;
  last_name: string | null;
  email: string;
  phone: string | null;
  job_title: string | null;
  status: UserStatus;
  timezone: string;
  last_login_at: string | null;
  roles: string[];
  created_at: string | null;
  updated_at: string | null;
}

export interface AuthUser extends User {
  is_super_admin: boolean;
  permissions: string[];
}

export interface Role {
  id: number;
  name: string;
  label: string;
  is_system: boolean;
  is_locked: boolean;
  users_count?: number;
  permissions?: string[];
  created_at: string | null;
}

export interface PermissionGroup {
  module: string;
  permissions: { name: string; action: string }[];
}

export interface AuditLog {
  id: number;
  log_name: string | null;
  event: string | null;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  causer: { id: number; name: string } | null;
  old: Record<string, unknown> | null;
  new: Record<string, unknown> | null;
  ip: string | null;
  user_agent: string | null;
  request_id: string | null;
  created_at: string;
}

export type NotificationType = 'info' | 'success' | 'warning' | 'error';

export interface AppNotification {
  id: number;
  type: NotificationType;
  category: string;
  title: string;
  message: string;
  action_url: string | null;
  entity_type: string | null;
  entity_id: number | null;
  read_at: string | null;
  created_at: string;
}

export type SettingValue = string | number | boolean;
export type SettingsGroups = Record<string, Record<string, { value: SettingValue; type: 'string' | 'int' | 'decimal' | 'bool'; options?: string[] | null; help?: string | null }>>;
