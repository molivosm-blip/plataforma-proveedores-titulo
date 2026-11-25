import type { ApiUserResponse } from './ApiUserResponse';

export type UserRole =
  | 'pm_admin'
  | 'pm_user'
  | 'proveedor'
  | 'admin_portal'
  | 'admin_area'
  | 'usuario_area';

export type AuthType = 'entra' | 'local';

export interface User {
  id: string;
  email: string;
  token: string;
  role: UserRole;

  /**
   * Área funcional del usuario interno.
   *
   * Ejemplos:
   * - pm
   * - contraloria
   * - legal_crediticia
   * - legal_judicial
   * - datos_maestros
   *
   * Para proveedores normalmente viene null o undefined.
   */
  area?: string | null;

  /**
   * Estado del usuario.
   *
   * Backend puede devolver:
   * - 1 / 0
   * - true / false
   */
  active?: number | boolean | null;

  /**
   * entra = autenticado con Microsoft 365 / Entra ID
   * local = autenticado con correo + contraseña local
   */
  authType: AuthType;

  /**
   * Compatibilidad con respuestas backend que usan snake_case.
   */
  auth_type?: AuthType;

  displayName?: string;
  display_name?: string;

  jobTitle?: string;
  photoUrl?: string;

  /**
   * Datos del usuario devueltos por la API interna.
   */
  apiUserData?: ApiUserResponse;
}

export const INTERNAL_ROLES: UserRole[] = [
  'pm_admin',
  'pm_user',
  'admin_portal',
  'admin_area',
  'usuario_area',
];

export const AREA_ROLES: UserRole[] = [
  'admin_area',
  'usuario_area',
];

export const ADMIN_GLOBAL_ROLES: UserRole[] = [
  'pm_admin',
  'admin_portal',
];

export const isUserRole = (value: unknown): value is UserRole =>
  value === 'pm_admin' ||
  value === 'pm_user' ||
  value === 'proveedor' ||
  value === 'admin_portal' ||
  value === 'admin_area' ||
  value === 'usuario_area';

export const isAuthType = (value: unknown): value is AuthType =>
  value === 'entra' || value === 'local';

export const isInternalRole = (role?: string | null): role is UserRole =>
  !!role && INTERNAL_ROLES.includes(role as UserRole);

export const isAdminGlobalRole = (role?: string | null): boolean =>
  role === 'pm_admin' || role === 'admin_portal';

export const isAreaRole = (role?: string | null): boolean =>
  role === 'admin_area' || role === 'usuario_area';

export const isProveedorRole = (role?: string | null): boolean =>
  role === 'proveedor';

export const getUserDisplayName = (user?: User | null): string =>
  user?.displayName ||
  user?.display_name ||
  user?.email ||
  'Usuario';

export const isUserActive = (user?: User | null): boolean => {
  if (!user) return false;

  if (user.active === undefined || user.active === null) {
    return true;
  }

  return user.active === true || user.active === 1;
};
