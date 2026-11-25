import { useCallback } from 'react';
import { ApiUserService } from '../infrastructure/auth/ApiUserService';
import {
  getRoleCodesFromApi,
  resolvePortalArea,
  resolvePortalRole,
} from '../config/roleMapping';

/**
 * Hook personalizado para integrar automáticamente los datos de API
 * del usuario con el contexto de autenticación.
 */
export const useUserIntegration = () => {
  const { user, setUser } = useAuth();

  /**
   * Carga y sincroniza los datos de API del usuario con el contexto de auth.
   *
   * @param forceRefresh Si debe forzar la actualización desde API.
   */
  const syncUserApiData = useCallback(
    async (forceRefresh: boolean = false) => {
      if (!user?.email) {
        return null;
      }

      const apiData = await ApiUserService.getUserData(
        user.email,
        forceRefresh
      );

      const resolvedRole = resolvePortalRole(apiData);
      const resolvedArea = resolvePortalArea(apiData);

      const updatedUser = {
        ...user,
        role: resolvedRole ?? user.role,
        area: resolvedArea ?? user.area ?? null,
        active: apiData.active ?? user.active ?? 1,
        displayName:
          apiData.displayName ??
          apiData.display_name ??
          user.displayName ??
          user.email,
        display_name:
          apiData.display_name ??
          apiData.displayName ??
          user.display_name ??
          user.email,
        apiUserData: apiData,
      };

      await setUser(updatedUser);

      return apiData;
    },
    [user, setUser]
  );

  /**
   * Verifica si el usuario actual tiene un rol/derecho específico por código.
   *
   * @param roleCode Código numérico del derecho/rol a verificar.
   */
  const hasUserRole = useCallback(
    (roleCode: number): boolean => {
      if (!user?.apiUserData) {
        return false;
      }

      const roleCodes = getRoleCodesFromApi(user.apiUserData);

      return roleCodes.includes(roleCode);
    },
    [user?.apiUserData]
  );

  /**
   * Obtiene todos los códigos de rol/derechos del usuario actual.
   */
  const getUserRoles = useCallback((): number[] => {
    if (!user?.apiUserData) {
      return [];
    }

    return getRoleCodesFromApi(user.apiUserData);
  }, [user?.apiUserData]);

  /**
   * Obtiene información resumida del rol del usuario.
   */
  const getUserRoleInfo = useCallback(() => {
    if (!user?.apiUserData) {
      return null;
    }

    const permissions = getRoleCodesFromApi(user.apiUserData);
    const resolvedRole = resolvePortalRole(user.apiUserData);
    const resolvedArea = resolvePortalArea(user.apiUserData);

    return {
      roleId: user.apiUserData.id_rol,
      roleDescription:
        user.apiUserData.en_rol_usuario?.rol ??
        user.apiUserData.en_rol_usuario?.descripcion ??
        resolvedRole ??
        user.role,
      resolvedRole: resolvedRole ?? user.role,
      resolvedArea: resolvedArea ?? user.area ?? null,
      permissions,
      roleRights:
        user.apiUserData.en_rol_usuario?.de_rol_usuario?.map(item => ({
          code: item.codder,
          description: `Derecho del rol: ${item.codder}`,
        })) ?? [],
      directRights:
        user.apiUserData.re_usuader?.map(item => ({
          code: item.codder,
          description: item.ma_derecho?.descri ?? `Derecho directo: ${item.codder}`,
        })) ?? [],
    };
  }, [user?.apiUserData, user?.role, user?.area]);

  return {
    syncUserApiData,
    hasUserRole,
    getUserRoles,
    getUserRoleInfo,
    isApiDataLoaded: !!user?.apiUserData,
  };
};
import { useAuth } from '../config/useAuth';
