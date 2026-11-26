export const ROUTES = {
  LOGIN: '/login',
  OLVIDE_CLAVE: '/olvide-clave',
  RESTABLECER_CLAVE: '/restablecer-clave',
  HOME: '/',

  PROVEEDOR_PUBLICO: {
    ACTIVAR: '/proveedor/activar',
  },

  DASHBOARD: {
    ROOT: '/',
  },

  CUENTA: '/cuenta',

  PROVEEDORES: {
    ROOT: '/proveedores',
    SEGUIMIENTO: '/proveedores/seguimiento',
    CREAR: '/proveedores/crear',
    SOLICITUDES: '/proveedores/mis-solicitudes',
  },

  ADMIN: {
    ROOT: '/admin',
    USUARIOS: '/admin/usuarios',
    AREAS: '/admin/areas',
  },
} as const;
