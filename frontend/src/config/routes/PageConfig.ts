import type { RouteConfig } from '../../types/menu.types';
import { IDROUTES } from './idRoutes';
import { ROUTES } from './routes';

export const PAGE_CONFIGS: Record<string, RouteConfig> = {
  dashboard: {
    id: IDROUTES.DASHBOARD,
    title: 'Dashboard',
    emoji: '📊',
    path: ROUTES.HOME,
    rol: [
      'proveedor',
      'pm_admin',
      'pm_user',
      'admin_portal',
      'admin_area',
      'usuario_area',
    ],
  },

  proveedores: {
    id: IDROUTES.PROVEEDORES,
    title: 'Proveedores',
    emoji: '🏭',
    path: ROUTES.PROVEEDORES.ROOT,
    rol: [
      'proveedor',
      'pm_admin',
      'pm_user',
      'admin_portal',
      'admin_area',
      'usuario_area',
    ],
    children: {
      seguimiento: {
        id: IDROUTES.PROVEEDORES_SEGUIMIENTO,
        title: 'Seguimiento',
        emoji: '🔎',
        path: ROUTES.PROVEEDORES.SEGUIMIENTO,
        rol: [
          'pm_admin',
          'pm_user',
          'admin_portal',
          'admin_area',
          'usuario_area',
        ],
      },

      crear: {
        id: IDROUTES.PROVEEDORES_CREACION,
        title: 'Invitar Proveedor',
        emoji: '➕',
        path: ROUTES.PROVEEDORES.CREAR,
        rol: [
          'pm_admin',
          'pm_user',
          'admin_portal',
          'admin_area',
        ],
      },

      solicitudes: {
        id: IDROUTES.PROVEEDORES_SOLICITUDES,
        title: 'Mis Solicitudes',
        emoji: '📄',
        path: ROUTES.PROVEEDORES.SOLICITUDES,
        rol: [
          'proveedor',
        ],
      },
    },
  },
  admin: {
    id: IDROUTES.ADMIN,
    title: 'Administración',
    emoji: '⚙️',
    path: ROUTES.ADMIN.ROOT,
    rol: ['pm_admin', 'admin_portal', 'admin_area'],
    children: {
      usuarios: {
        id: IDROUTES.ADMIN_USUARIOS,
        title: 'Usuarios internos',
        emoji: '👥',
        path: ROUTES.ADMIN.USUARIOS,
        rol: ['pm_admin', 'admin_portal', 'admin_area'],
      },
    },
  },
};
