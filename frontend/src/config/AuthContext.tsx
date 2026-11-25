import React, {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';

import {
  AuthContext,
  type AuthContextType,
} from './authContextValue';

import type {
  User,
  UserRole,
} from '../domain/auth/AuthEntity';

import type { ApiUserResponse } from '../domain/auth/ApiUserResponse';

import {
  isAdminGlobalRole,
  isInternalRole,
  isUserActive,
  isUserRole,
} from '../domain/auth/AuthEntity';

import {
  loginUseCase,
  logoutUseCase,
} from '../config/di';

/*
|--------------------------------------------------------------------------
| Tipos
|--------------------------------------------------------------------------
*/

/** Logs de diagnóstico: solo en desarrollo. */
const devLog = (...args: unknown[]): void => {
  if (import.meta.env.DEV) {
    console.log(...args);
  }
};

/*
|--------------------------------------------------------------------------
| Constantes
|--------------------------------------------------------------------------
*/

const AUTH_STORAGE_KEY = 'auth_user';
const AUTH_TOKEN_KEY = 'auth_token';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const normalizeArea = (
  value: unknown
): string | null => {
  if (typeof value !== 'string') {
    return null;
  }

  const normalized = value
    .trim()
    .toLowerCase();

  return normalized || null;
};

const normalizeActive = (
  value: unknown
): number | boolean => {
  if (typeof value === 'boolean') {
    return value;
  }

  if (typeof value === 'number') {
    return value;
  }

  if (value === '1') {
    return 1;
  }

  if (value === '0') {
    return 0;
  }

  return 1;
};

const decodeJwtPayload = (
  token: string
): Record<string, unknown> | null => {
  const parts = token.split('.');

  if (parts.length !== 3) {
    return null;
  }

  try {
    const normalized = parts[1]
      .replace(/-/g, '+')
      .replace(/_/g, '/');
    const padded = normalized.padEnd(
      Math.ceil(normalized.length / 4) * 4,
      '='
    );
    const decoded: unknown = JSON.parse(
      atob(padded)
    );

    return decoded && typeof decoded === 'object'
      ? (decoded as Record<string, unknown>)
      : null;
  } catch {
    return null;
  }
};

const isBackendTokenValid = (
  token: string
): boolean => {
  const payload = decodeJwtPayload(token);
  const expiration = payload?.exp;

  return typeof expiration === 'number' &&
    expiration * 1000 > Date.now();
};

const clearStoredSession = (): void => {
  localStorage.removeItem(AUTH_STORAGE_KEY);
  localStorage.removeItem(AUTH_TOKEN_KEY);
};

/*
|--------------------------------------------------------------------------
| Normalización del usuario
|--------------------------------------------------------------------------
*/

const normalizeUser = (
  raw: unknown
): User | null => {
  if (
    !raw ||
    typeof raw !== 'object'
  ) {
    return null;
  }

  const data =
    raw as Record<string, unknown>;

  const roleRaw =
    data.role;

  if (!isUserRole(roleRaw)) {
    console.error(
      '[AUTH CONTEXT] Rol no válido:',
      roleRaw
    );

    return null;
  }

  const authTypeRaw =
    data.authType ??
    data.auth_type;

  const authType =
    authTypeRaw === 'local'
      ? 'local'
      : 'entra';

  const rawId =
    data.id ??
    data.sub ??
    data.userid ??
    '';

  const id =
    String(rawId);

  const rawEmail =
    data.email ??
    data.mail01 ??
    '';

  const email =
    typeof rawEmail === 'string'
      ? rawEmail
          .trim()
          .toLowerCase()
      : '';

  if (!email) {
    console.error(
      '[AUTH CONTEXT] El usuario no tiene correo.'
    );

    return null;
  }

  const rawDisplayName =
    data.displayName ??
    data.display_name ??
    data.name ??
    data.nombre ??
    email;

  const displayName =
    typeof rawDisplayName === 'string'
      ? rawDisplayName.trim() || email
      : email;

  const rawToken =
    data.token ??
    localStorage.getItem(AUTH_TOKEN_KEY);

  const token =
    typeof rawToken === 'string'
      ? rawToken.trim()
      : '';

  if (!token || !isBackendTokenValid(token)) {
    console.warn(
      '[AUTH CONTEXT] La sesión no contiene un JWT válido del backend.'
    );

    return null;
  }

  const user: User = {
    id,
    email,
    token,

    /*
     * Estos valores deben provenir del backend.
     */
    role: roleRaw,
    area: normalizeArea(data.area),
    active: normalizeActive(data.active),

    authType,
    auth_type: authType,

    displayName,
    display_name: displayName,

    jobTitle:
      typeof data.jobTitle === 'string'
        ? data.jobTitle
        : undefined,

    photoUrl:
      typeof data.photoUrl === 'string'
        ? data.photoUrl
        : undefined,

    apiUserData:
      data.apiUserData as
        | ApiUserResponse
        | undefined,
  };

  return user;
};

/*
|--------------------------------------------------------------------------
| Proveedor de autenticación
|--------------------------------------------------------------------------
*/

export const AuthProvider: React.FC<{
  children: React.ReactNode;
}> = ({
  children,
}) => {
  const [
    user,
    setUserState,
  ] = useState<User | null>(null);

  const [
    showWelcomePopup,
    setShowWelcomePopup,
  ] = useState(false);

  /*
  |--------------------------------------------------------------------------
  | Carga inicial desde localStorage
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    const stored =
      localStorage.getItem(
        AUTH_STORAGE_KEY
      );

    if (!stored) {
      devLog(
        '[AUTH CONTEXT] No existe una sesión almacenada.'
      );

      return;
    }

    try {
      const parsed: unknown =
        JSON.parse(stored);

      const normalized =
        normalizeUser(parsed);

      devLog(
        '[AUTH CONTEXT] Sesión recuperada:',
        {
          email: normalized?.email,
          role: normalized?.role,
          area: normalized?.area,
          active: normalized?.active,
          authType:
            normalized?.authType,
        }
      );

      if (
        !normalized ||
        !isUserActive(normalized)
      ) {
        clearStoredSession();

        setUserState(null);

        return;
      }

      setUserState(normalized);
    } catch (error) {
      console.warn(
        '[AUTH CONTEXT] No se pudo recuperar la sesión:',
        error
      );

      clearStoredSession();

      setUserState(null);
    }
  }, []);

  /*
  |--------------------------------------------------------------------------
  | Guardar usuario
  |--------------------------------------------------------------------------
  */

  const setUser = useCallback(
    async (
      newUser: User | null
    ): Promise<void> => {
      if (!newUser) {
        devLog(
          '[AUTH CONTEXT] Eliminando sesión.'
        );

        clearStoredSession();

        setUserState(null);

        return;
      }

      const normalized =
        normalizeUser(newUser);

      if (!normalized) {
        console.error(
          '[AUTH CONTEXT] Usuario no válido:',
          newUser
        );

        clearStoredSession();

        setUserState(null);

        return;
      }

      devLog(
        '[AUTH CONTEXT] Usuario que se guardará:',
        {
          email: normalized.email,
          role: normalized.role,
          area: normalized.area,
          active: normalized.active,
          authType:
            normalized.authType,
          hasBackendJwt:
            isBackendTokenValid(normalized.token),
        }
      );

      localStorage.setItem(
        AUTH_STORAGE_KEY,
        JSON.stringify(normalized)
      );
      localStorage.setItem(
        AUTH_TOKEN_KEY,
        normalized.token
      );

      setUserState(normalized);
    },
    []
  );

  /*
  |--------------------------------------------------------------------------
  | Actualizar datos corporativos complementarios
  |--------------------------------------------------------------------------
  |
  | Esta función no puede reemplazar:
  | - role
  | - area
  | - active
  | - token
  | - authType
  |--------------------------------------------------------------------------
  */

  const updateUserApiData =
    useCallback(
      (
        apiData: ApiUserResponse
      ): void => {
        setUserState(currentUser => {
          if (!currentUser) {
            return currentUser;
          }

          const updatedUser: User = {
            ...currentUser,

            apiUserData: apiData,

            /*
             * Mantener autorización oficial
             * proporcionada por el backend.
             */
            role:
              currentUser.role,

            area:
              currentUser.area ?? null,

            active:
              currentUser.active ?? 1,

            token:
              currentUser.token,

            authType:
              currentUser.authType,

            auth_type:
              currentUser.auth_type ??
              currentUser.authType,

            /*
             * La API corporativa solo complementa
             * información de presentación.
             */
            displayName:
              apiData.displayName ??
              apiData.display_name ??
              currentUser.displayName ??
              currentUser.email,

            display_name:
              apiData.display_name ??
              apiData.displayName ??
              currentUser.display_name ??
              currentUser.displayName ??
              currentUser.email,
          };

          const normalized =
            normalizeUser(updatedUser);

          if (!normalized) {
            console.error(
              '[AUTH CONTEXT] No fue posible normalizar los datos corporativos.'
            );

            return currentUser;
          }

          devLog(
            '[AUTH CONTEXT] Usuario después de sincronizar API corporativa:',
            {
              email: normalized.email,
              role: normalized.role,
              area: normalized.area,
              active: normalized.active,
              authType:
                normalized.authType,
            }
          );

          localStorage.setItem(
            AUTH_STORAGE_KEY,
            JSON.stringify(normalized)
          );
      localStorage.setItem(
        AUTH_TOKEN_KEY,
        normalized.token
      );

          return normalized;
        });
      },
      []
    );

  /*
  |--------------------------------------------------------------------------
  | Login Microsoft
  |--------------------------------------------------------------------------
  */

  const loginWithMicrosoft =
    useCallback(
      async (): Promise<void> => {
        try {
          const loggedUser =
            await loginUseCase.execute();

          devLog(
            '[AUTH CONTEXT] Resultado de loginUseCase:',
            {
              email:
                loggedUser?.email,
              role:
                loggedUser?.role,
              area:
                loggedUser?.area,
              active:
                loggedUser?.active,
              authType:
                loggedUser?.authType ??
                loggedUser?.auth_type,
            }
          );

          await setUser(loggedUser);
        } catch (error: unknown) {
          if (
            error instanceof Error &&
            error.message ===
              'REDIRECT_INITIATED'
          ) {
            return;
          }

          console.error(
            '[AUTH CONTEXT] Error en login Microsoft:',
            error
          );

          throw error;
        }
      },
      [setUser]
    );

  /*
  |--------------------------------------------------------------------------
  | Login local
  |--------------------------------------------------------------------------
  */

  const loginLocal =
    useCallback(
      async (
        localUser: User
      ): Promise<void> => {
        if (!isBackendTokenValid(localUser.token)) {
          throw new Error(
            'El backend no entregó una sesión válida.'
          );
        }

        await setUser({
          ...localUser,
          authType: 'local',
          auth_type: 'local',
        });
      },
      [setUser]
    );

  /*
  |--------------------------------------------------------------------------
  | Logout
  |--------------------------------------------------------------------------
  */

  const logout =
    useCallback(
      async (): Promise<void> => {
        try {
          if (user?.authType === 'entra') {
            await logoutUseCase.execute();
          }
        } catch (error) {
          console.error(
            '[AUTH CONTEXT] Error durante el logout:',
            error
          );
        } finally {
          clearStoredSession();

          localStorage.removeItem(
            'dimerc_user_data'
          );

          localStorage.removeItem(
            'dimerc_user_email'
          );

          localStorage.removeItem(
            'dimerc_last_validation'
          );

          sessionStorage.removeItem(
            'hasShownWelcome'
          );

          setUserState(null);
        }
      },
      [user?.authType]
    );

  useEffect(() => {
    if (!user?.token) {
      return;
    }

    const payload = decodeJwtPayload(user.token);
    const expiration = payload?.exp;

    if (typeof expiration !== 'number') {
      clearStoredSession();
      setUserState(null);
      return;
    }

    const remaining = expiration * 1000 - Date.now();

    if (remaining <= 0) {
      clearStoredSession();
      setUserState(null);
      return;
    }

    const timeout = window.setTimeout(() => {
      clearStoredSession();
      setUserState(null);
    }, Math.min(remaining, 2_147_483_647));

    return () => {
      window.clearTimeout(timeout);
    };
  }, [user?.token]);

  /*
  |--------------------------------------------------------------------------
  | Datos derivados
  |--------------------------------------------------------------------------
  */

  const role =
    user?.role ?? null;

  const area =
    user?.area ?? null;

  const active =
    isUserActive(user);

  const hasRole =
    useCallback(
      (
        roles:
          | UserRole
          | UserRole[]
      ): boolean => {
        if (!role) {
          return false;
        }

        if (Array.isArray(roles)) {
          return roles.includes(role);
        }

        return role === roles;
      },
      [role]
    );

  /*
  |--------------------------------------------------------------------------
  | Valor del contexto
  |--------------------------------------------------------------------------
  */

  const value =
    useMemo<AuthContextType>(
      () => ({
        user,

        setUser,

        updateUserApiData,

        logout,

        loginWithMicrosoft,

        loginLocal,

        isAuthenticated:
          Boolean(user) &&
          active,

        role,

        area,

        active,

        hasRole,

        isPmAdmin:
          role === 'pm_admin',

        isPmUser:
          role === 'pm_user',

        isProveedor:
          role === 'proveedor',

        isAdminPortal:
          role === 'admin_portal',

        isAdminGlobal:
          isAdminGlobalRole(role),

        isAreaAdmin:
          role === 'admin_area',

        isAreaUser:
          role === 'usuario_area',

        isInternalUser:
          isInternalRole(role),

        showWelcomePopup,

        setShowWelcomePopup,
      }),
      [
        user,
        setUser,
        updateUserApiData,
        logout,
        loginWithMicrosoft,
        loginLocal,
        active,
        role,
        area,
        hasRole,
        showWelcomePopup,
      ]
    );

  return (
    <AuthContext.Provider
      value={value}
    >
      {children}
    </AuthContext.Provider>
  );
};
