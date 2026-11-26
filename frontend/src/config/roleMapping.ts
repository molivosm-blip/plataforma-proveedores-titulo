import type { ApiUserResponse } from '../domain/auth/ApiUserResponse';
import type { UserRole } from '../domain/auth/AuthEntity';
import { isUserRole } from '../domain/auth/AuthEntity';

/**
 * ⚠️ Reemplazar por los codder reales de tu sistema cuando estén definidos.
 *
 * Estos códigos se mantienen como placeholder para compatibilidad legacy.
 */
export const ROLE_PM_ADMIN_CODE = 1001;
export const ROLE_PM_USER_CODE = 1002;
export const ROLE_ADMIN_PORTAL_CODE = 1003;
export const ROLE_ADMIN_AREA_CODE = 1004;
export const ROLE_USUARIO_AREA_CODE = 1005;
export const ROLE_PROVEEDOR_CODE = 1006;

export const ROLE_CODE_MAP: Record<number, UserRole> = {
  [ROLE_PM_ADMIN_CODE]: 'pm_admin',
  [ROLE_PM_USER_CODE]: 'pm_user',
  [ROLE_ADMIN_PORTAL_CODE]: 'admin_portal',
  [ROLE_ADMIN_AREA_CODE]: 'admin_area',
  [ROLE_USUARIO_AREA_CODE]: 'usuario_area',
  [ROLE_PROVEEDOR_CODE]: 'proveedor',
};

const normalizeRoleText = (value?: string | null): string => {
  return (value || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/-/g, '_');
};

export const getRoleCodesFromApi = (apiData?: ApiUserResponse): number[] => {
  if (!apiData) return [];

  const codes = new Set<number>();

  /**
   * id_rol directo legacy.
   */
  const idRol = Number(apiData.id_rol);
  if (!Number.isNaN(idRol)) {
    codes.add(idRol);
  }

  /**
   * Derechos del rol.
   */
  apiData.en_rol_usuario?.de_rol_usuario?.forEach(item => {
    const code = Number(item.codder);

    if (!Number.isNaN(code)) {
      codes.add(code);
    }
  });

  /**
   * Derechos directos del usuario.
   */
  apiData.re_usuader?.forEach(item => {
    const code = Number(item.codder);

    if (!Number.isNaN(code)) {
      codes.add(code);
    }
  });

  return Array.from(codes).sort((a, b) => a - b);
};

export const resolvePortalRoleFromText = (
  roleText?: string | null
): UserRole | null => {
  const normalized = normalizeRoleText(roleText);

  if (!normalized) {
    return null;
  }

  /**
   * Roles exactos del portal.
   */
  if (isUserRole(normalized)) {
    return normalized;
  }

  /**
   * Alias legacy / nombres humanos.
   */
  if (
    normalized === 'pm_admin' ||
    normalized === 'administrador_pm' ||
    normalized === 'admin_pm'
  ) {
    return 'pm_admin';
  }

  if (
    normalized === 'pm_user' ||
    normalized === 'usuario_pm' ||
    normalized === 'pm'
  ) {
    return 'pm_user';
  }

  if (
    normalized === 'admin_portal' ||
    normalized === 'administrador_portal' ||
    normalized === 'portal_admin'
  ) {
    return 'admin_portal';
  }

  if (
    normalized === 'admin_area' ||
    normalized === 'administrador_area' ||
    normalized === 'area_admin'
  ) {
    return 'admin_area';
  }

  if (
    normalized === 'usuario_area' ||
    normalized === 'user_area' ||
    normalized === 'area_user'
  ) {
    return 'usuario_area';
  }

  if (
    normalized === 'auditor' ||
    normalized === 'auditoria'
  ) {
    return 'auditor';
  }

  if (
    normalized === 'proveedor' ||
    normalized === 'supplier'
  ) {
    return 'proveedor';
  }

  return null;
};

export const resolvePortalRoleFromCodes = (
  roleCodes: number[]
): UserRole | null => {
  /**
   * Orden de prioridad:
   * admin_portal > pm_admin > admin_area > usuario_area > pm_user > proveedor
   */
  const priority: UserRole[] = [
    'admin_portal',
    'pm_admin',
    'admin_area',
    'usuario_area',
    'pm_user',
    'proveedor',
  ];

  for (const role of priority) {
    const found = roleCodes.some(code => ROLE_CODE_MAP[code] === role);

    if (found) {
      return role;
    }
  }

  return null;
};

export const resolvePortalRole = (
  apiData?: ApiUserResponse
): UserRole | null => {
  if (!apiData) {
    return null;
  }

  /**
   * 1. Primero confiamos en el campo directo role del backend nuevo.
   */
  const directRole = resolvePortalRoleFromText(apiData.role);

  if (directRole) {
    return directRole;
  }

  /**
   * 2. Luego intentamos auth/rol legacy textual.
   */
  const legacyRole =
    resolvePortalRoleFromText(apiData.en_rol_usuario?.rol) ||
    resolvePortalRoleFromText(apiData.en_rol_usuario?.descripcion);

  if (legacyRole) {
    return legacyRole;
  }

  /**
   * 3. Finalmente intentamos por códigos.
   */
  const roleCodes = getRoleCodesFromApi(apiData);

  return resolvePortalRoleFromCodes(roleCodes);
};

export const resolvePortalArea = (
  apiData?: ApiUserResponse
): string | null => {
  if (!apiData) {
    return null;
  }

  if (typeof apiData.area === 'string' && apiData.area.trim()) {
    return apiData.area.trim();
  }

  /**
   * Compatibilidad por si en API legacy llega con otro nombre.
   */
  const possibleArea =
    apiData.area_code ||
    apiData.codigo_area ||
    apiData.cod_area ||
    apiData.areaUsuario ||
    apiData.area_usuario;

  if (typeof possibleArea === 'string' && possibleArea.trim()) {
    return possibleArea.trim();
  }

  const role = resolvePortalRole(apiData);

  /**
   * Compatibilidad:
   * pm_user se considera área PM.
   */
  if (role === 'pm_user') {
    return 'pm';
  }

  return null;
};

export const roleLabel: Record<UserRole, string> = {
  pm_admin: 'PM Admin',
  pm_user: 'PM Usuario',
  proveedor: 'Proveedor',
  admin_portal: 'Administrador Portal',
  admin_area: 'Administrador de Área',
  usuario_area: 'Usuario de Área',
  auditor: 'Auditor (solo lectura)',
};

export const getRoleLabel = (role?: UserRole | null): string => {
  if (!role) {
    return 'Sin rol';
  }

  return roleLabel[role] || role;
};

export const isAdminGlobalRoleName = (
  role?: UserRole | string | null
): boolean => role === 'pm_admin' || role === 'admin_portal';

export const isAreaRoleName = (
  role?: UserRole | string | null
): boolean => role === 'admin_area' || role === 'usuario_area';

export const isInternalRoleName = (
  role?: UserRole | string | null
): boolean =>
  role === 'pm_admin' ||
  role === 'pm_user' ||
  role === 'admin_portal' ||
  role === 'admin_area' ||
  role === 'usuario_area' ||
  role === 'auditor';

export const canInviteProveedorByRole = (
  role?: UserRole | string | null,
  area?: string | null
): boolean => {
  if (role === 'pm_admin' || role === 'admin_portal') {
    return true;
  }

  if (role === 'pm_user') {
    return true;
  }

  if (role === 'admin_area' && area === 'pm') {
    return true;
  }

  return false;
};