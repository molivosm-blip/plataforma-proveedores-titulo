import React from 'react';
import {
  createBrowserRouter,
  RouterProvider,
  type RouteObject,
  Navigate,
} from 'react-router-dom';

import RequireAuth from './RequireAuth';
import LoginPage from '../ui/pages/LoginPage/LoginPage';
import AppLayout from './AppLayout';
import { useAuth } from './useAuth';

// Páginas del proyecto
import Dashboard from '../ui/pages/Dashboard/Dashboard';
import SeguimientoProveedores from '../ui/pages/proveedores/seguimiento/SeguimientoProveedores';
import CrearProveedor from '../ui/pages/proveedores/crear/CrearProveedor';
import MisSolicitudesProveedor from '../ui/pages/proveedores/misSolicitudes/MisSolicitudesProveedor';
import ActivarProveedor from '../ui/pages/proveedores/registro/ActivarProveedor';
import UsuariosInternos from '../ui/pages/admin/UsuariosInternos';
import MiCuenta from '../ui/pages/cuenta/MiCuenta';

// Rutas
import { ROUTES } from './routes/routes';
import type { UserRole } from '../domain/auth/AuthEntity';

const INTERNAL_ROLES: UserRole[] = [
  'pm_admin',
  'pm_user',
  'admin_portal',
  'admin_area',
  'usuario_area',
];

const CAN_INVITE_PROVEEDOR_ROLES: UserRole[] = [
  'pm_admin',
  'pm_user',
  'admin_portal',
  'admin_area',
];

const ADMIN_USUARIOS_ROLES: UserRole[] = [
  'pm_admin',
  'admin_portal',
  'admin_area',
];

const MIS_SOLICITUDES_ROLES: UserRole[] = [
  'proveedor',
];

const stripLeadingSlash = (path: string): string => {
  return path.replace(/^\/+/, '');
};

const ProveedoresRedirect: React.FC = () => {
  const { user } = useAuth();
  const role = user?.role;

  if (role === 'proveedor') {
    return <Navigate to={ROUTES.PROVEEDORES.SOLICITUDES} replace />;
  }

  return <Navigate to={ROUTES.PROVEEDORES.SEGUIMIENTO} replace />;
};

const routes: RouteObject[] = [
  {
    path: ROUTES.LOGIN,
    element: <LoginPage />,
  },
  {
    path: ROUTES.PROVEEDOR_PUBLICO.ACTIVAR,
    element: <ActivarProveedor />,
  },
  {
    element: <RequireAuth />,
    children: [
      {
        path: ROUTES.HOME,
        element: <AppLayout />,
        children: [
          {
            index: true,
            element: <Dashboard />,
          },
          {
            path: stripLeadingSlash(ROUTES.PROVEEDORES.ROOT),
            element: <ProveedoresRedirect />,
          },

          /*
          |--------------------------------------------------------------------------
          | Mi cuenta (todos los roles)
          |--------------------------------------------------------------------------
          */
          {
            path: stripLeadingSlash(ROUTES.CUENTA),
            element: <MiCuenta />,
          },

          /*
          |--------------------------------------------------------------------------
          | Seguimiento interno
          |--------------------------------------------------------------------------
          */

          {
            element: <RequireAuth allowedRoles={INTERNAL_ROLES} />,
            children: [
              {
                path: stripLeadingSlash(ROUTES.PROVEEDORES.SEGUIMIENTO),
                element: <SeguimientoProveedores />,
              },
            ],
          },

          /*
          |--------------------------------------------------------------------------
          | Invitación proveedor
          |--------------------------------------------------------------------------
          */

          {
            element: <RequireAuth allowedRoles={CAN_INVITE_PROVEEDOR_ROLES} />,
            children: [
              {
                path: stripLeadingSlash(ROUTES.PROVEEDORES.CREAR),
                element: <CrearProveedor />,
              },
            ],
          },

          /*
          |--------------------------------------------------------------------------
          | Administración de usuarios internos
          |--------------------------------------------------------------------------
          */
          {
            path: stripLeadingSlash(ROUTES.ADMIN.ROOT),
            element: <Navigate to={ROUTES.ADMIN.USUARIOS} replace />,
          },
          {
            element: <RequireAuth allowedRoles={ADMIN_USUARIOS_ROLES} />,
            children: [
              {
                path: stripLeadingSlash(ROUTES.ADMIN.USUARIOS),
                element: <UsuariosInternos />,
              },
            ],
          },
          /*
          |--------------------------------------------------------------------------
          | Solicitudes proveedor
          |--------------------------------------------------------------------------
          */

          {
            element: <RequireAuth allowedRoles={MIS_SOLICITUDES_ROLES} />,
            children: [
              {
                path: stripLeadingSlash(ROUTES.PROVEEDORES.SOLICITUDES),
                element: <MisSolicitudesProveedor />,
              },
            ],
          },
        ],
      },
    ],
  },
  {
    path: '*',
    element: <Navigate to={ROUTES.HOME} replace />,
  },
];

/*
 * El router se crea una sola vez. Crearlo dentro del componente generaba
 * un router nuevo en cada render y reiniciaba la navegación.
 */
const router = createBrowserRouter(routes);

export const AppRouter: React.FC = () => (
  <RouterProvider router={router} />
);
