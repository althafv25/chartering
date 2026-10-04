import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { onUnauthorized, tokenStore } from '../api/client';
import { authApi } from '../api/endpoints';
import type { AuthUser } from '../types/models';
import { AuthContext, type AuthContextValue } from './context';

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(() => !!tokenStore.get());

  useEffect(() => {
    if (!tokenStore.get()) return;
    authApi.me()
      .then(setUser)
      .catch(() => tokenStore.clear())
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => onUnauthorized(() => {
    setUser(null);
    queryClient.clear();
  }), [queryClient]);

  const login = useCallback(async (email: string, password: string) => {
    const result = await authApi.login(email, password);
    tokenStore.set(result.token);
    setUser(result.user);
    return result.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } finally {
      tokenStore.clear();
      setUser(null);
      queryClient.clear();
    }
  }, [queryClient]);

  const can = useCallback((permission: string | string[]) => {
    if (!user) return false;
    if (user.is_super_admin) return true;
    const list = Array.isArray(permission) ? permission : [permission];
    return list.some((p) => user.permissions.includes(p));
  }, [user]);

  const value = useMemo<AuthContextValue>(() => ({
    user, isLoading, isAuthenticated: !!user, login, logout, setUser, can,
  }), [user, isLoading, login, logout, can]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
