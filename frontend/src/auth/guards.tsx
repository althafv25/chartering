import type { ReactNode } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './useAuth';
import { FullPageLoader } from '../components/Feedback';
import ForbiddenPage from '../pages/ForbiddenPage';

export function RequireAuth() {
  const { isAuthenticated, isLoading } = useAuth();
  const location = useLocation();

  if (isLoading) return <FullPageLoader />;
  if (!isAuthenticated) return <Navigate to="/login" replace state={{ from: location }} />;
  return <Outlet />;
}

export function GuestOnly() {
  const { isAuthenticated, isLoading } = useAuth();
  if (isLoading) return <FullPageLoader />;
  return isAuthenticated ? <Navigate to="/" replace /> : <Outlet />;
}

/** Route-level permission gate. Renders the 403 page instead of redirecting. */
export function RequirePermission({ permission, children }: { permission: string | string[]; children?: ReactNode }) {
  const { can } = useAuth();
  if (!can(permission)) return <ForbiddenPage />;
  return children ? <>{children}</> : <Outlet />;
}

/** Inline UI gate for buttons/actions. */
export function Can({ permission, children }: { permission: string | string[]; children: ReactNode }) {
  const { can } = useAuth();
  return can(permission) ? <>{children}</> : null;
}
