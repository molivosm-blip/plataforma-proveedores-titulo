import apiClient from './apiClient';
import type { User } from '../../../domain/auth/AuthEntity';

/** Usuario tal como lo entrega el backend (sin token; lo trae la sesión). */
export type ApiAuthUser = Omit<User, 'token' | 'authType'> & {
  authType?: User['authType'];
};

//
// =======================
// TIPOS
// =======================
//

export interface LoginResponse {
  message?: string;
  session: {
    token: string;
    user: ApiAuthUser;
  };
}

//
// =======================
// HELPERS
// =======================
//

const normalizeAuthResponse = (raw: unknown): LoginResponse => {
  const container = raw as { data?: unknown } | null;
  const payload = (container?.data ?? raw) as {
    message?: string;
    token?: string;
    user?: ApiAuthUser;
    session?: { token?: string; user?: ApiAuthUser };
  } | null;

  // Caso esperado nuevo:
  // { session: { token, user } }
  if (payload?.session?.token && payload?.session?.user) {
    return {
      message: payload?.message,
      session: {
        token: payload.session.token,
        user: payload.session.user,
      },
    };
  }

  // Caso actual de tu backend:
  // { token, user }
  if (payload?.token && payload?.user) {
    return {
      message: payload?.message,
      session: {
        token: payload.token,
        user: payload.user,
      },
    };
  }

  throw new Error('Respuesta de autenticación inválida.');
};

//
// =======================
// AUTH SERVICE
// =======================
//

export class AuthService {
  //
  // Login proveedor
  //
  static async login(email: string, password: string): Promise<LoginResponse> {
    const response = await apiClient.post('/auth/login', {
      email,
      password,
    });

    return normalizeAuthResponse(response.data);
  }

  //
  // Login Microsoft / MSAL
  //
  static async loginMsal(
    email: string,
    displayName?: string,
    tokens?: {
      /** Access token emitido por Entra ID para la API del portal. */
      accessToken?: string;
      /** ID token de la sesión Microsoft. */
      idToken?: string;
    }
  ): Promise<LoginResponse> {
    /*
     * En modo local el backend solo usa email y displayName.
     * Cuando se habilite la validación de token en el backend,
     * el Bearer permite verificar la identidad sin confiar en el email.
     */
    const response = await apiClient.post(
      '/auth/msal',
      {
        email,
        displayName,
        idToken: tokens?.idToken,
      },
      tokens?.accessToken
        ? {
            headers: {
              Authorization: `Bearer ${tokens.accessToken}`,
            },
          }
        : undefined
    );

    return normalizeAuthResponse(response.data);
  }
}
