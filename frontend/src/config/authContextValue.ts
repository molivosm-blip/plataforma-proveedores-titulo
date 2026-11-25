import { createContext } from 'react';

import type {
  User,
  UserRole,
} from '../domain/auth/AuthEntity';

import type { ApiUserResponse } from '../domain/auth/ApiUserResponse';

/*
|--------------------------------------------------------------------------
| Contexto de autenticación
|--------------------------------------------------------------------------
|
| Se separa de AuthContext.tsx para que ese archivo exporte solo el
| componente AuthProvider (requisito de React Fast Refresh).
|--------------------------------------------------------------------------
*/

export interface AuthContextType {
  user: User | null;

  setUser: (
    user: User | null
  ) => Promise<void>;

  updateUserApiData: (
    apiData: ApiUserResponse
  ) => void;

  logout: () => Promise<void>;

  loginWithMicrosoft: () => Promise<void>;

  loginLocal: (
    user: User
  ) => Promise<void>;

  isAuthenticated: boolean;
  role: UserRole | null;
  area: string | null;
  active: boolean;

  hasRole: (
    roles: UserRole | UserRole[]
  ) => boolean;

  isPmAdmin: boolean;
  isPmUser: boolean;
  isProveedor: boolean;

  isAdminPortal: boolean;
  isAdminGlobal: boolean;
  isAreaAdmin: boolean;
  isAreaUser: boolean;
  isInternalUser: boolean;

  showWelcomePopup: boolean;

  setShowWelcomePopup: (
    show: boolean
  ) => void;
}

export const AuthContext =
  createContext<AuthContextType | null>(
    null
  );
