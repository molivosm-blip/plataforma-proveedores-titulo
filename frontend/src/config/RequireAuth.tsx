import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './useAuth';
import type { UserRole } from '../domain/auth/AuthEntity';

interface RequireAuthProps {
  allowedRoles?: UserRole[];
}

const getDefaultRedirectByRole = (role: UserRole | null): string => {
  switch (role) {
    case 'proveedor':
      return '/proveedores/mis-solicitudes';

    case 'pm_admin':
    case 'pm_user':
    case 'admin_portal':
    case 'admin_area':
    case 'usuario_area':
      return '/proveedores/seguimiento';

    default:
      return '/login';
  }
};

const RequireAuth = ({ allowedRoles }: RequireAuthProps) => {
  const location = useLocation();

  const {
    isAuthenticated,
    active,
    role,
    hasRole,
  } = useAuth();

  if (!isAuthenticated || !active) {
    return (
      <Navigate
        to="/login"
        replace
        state={{ from: location.pathname + location.search }}
      />
    );
  }

  if (allowedRoles && allowedRoles.length > 0 && !hasRole(allowedRoles)) {
    return (
      <Navigate
        to={getDefaultRedirectByRole(role)}
        replace
      />
    );
  }

  return <Outlet />;
};

export default RequireAuth;