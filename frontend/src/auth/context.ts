import { createContext } from 'react';
import type { AuthUser } from '../types/models';

export interface AuthContextValue {
  user: AuthUser | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  login: (email: string, password: string) => Promise<AuthUser>;
  logout: () => Promise<void>;
  setUser: (user: AuthUser) => void;
  can: (permission: string | string[]) => boolean;
}

export const AuthContext = createContext<AuthContextValue | null>(null);
