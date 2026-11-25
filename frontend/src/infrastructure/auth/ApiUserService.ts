import axios, { AxiosError } from 'axios';
import type { ApiUserResponse } from '../../domain/auth/ApiUserResponse';

// ================= STORAGE KEYS =================

const STORAGE_KEYS = {
  USER_DATA: 'dimerc_user_data',
  USER_EMAIL: 'dimerc_user_email',
  LAST_VALIDATION: 'dimerc_last_validation',
} as const;

// ================= ENVIRONMENT =================

const getHostname = (): string => {
  if (typeof window === 'undefined') {
    return '';
  }

  return window.location.hostname;
};

const hostname = getHostname();

const isDevelopment =
  hostname === 'localhost' ||
  hostname === '127.0.0.1';

// ================= BASE URL =================

// En desarrollo se usa el proxy de Vite (/api/usuarios_data).
const DIMERC_API_BASE_URL =
  isDevelopment
    ? '/api/usuarios_data/dimerc/correo/'
    : 'https://apigw.dimerc.cl:8443/usuarios_data/dimerc/correo/';

// ================= TYPES =================

interface StoredUserData {
  email: string;
  userData: ApiUserResponse;
  timestamp: string;
  source: 'dimerc_api' | 'local_cache';
}

// ================= HELPERS =================

const normalizeEmail = (email?: string | null): string =>
  (email || '').trim().toLowerCase();

const isStoredUserData = (value: unknown): value is StoredUserData => {
  if (!value || typeof value !== 'object') {
    return false;
  }

  const data = value as Partial<StoredUserData>;

  return (
    typeof data.email === 'string' &&
    !!data.email &&
    typeof data.timestamp === 'string' &&
    !!data.userData &&
    typeof data.userData === 'object'
  );
};

const isCacheExpired = (timestamp: string): boolean => {
  const dataAge = Date.now() - new Date(timestamp).getTime();
  const maxAge = 24 * 60 * 60 * 1000;

  return dataAge > maxAge;
};

const normalizeApiUserResponse = (
  email: string,
  data: ApiUserResponse
): ApiUserResponse => {
  const normalizedEmail = normalizeEmail(email);

  return {
    ...data,
    email: typeof data.email === 'string' ? data.email : normalizedEmail,
    mail01: typeof data.mail01 === 'string' ? data.mail01 : normalizedEmail,

    /**
     * Defaults seguros para el portal.
     * Si backend ya devuelve estos campos, se respetan.
     */
    active: data.active ?? 1,
    auth_type: data.auth_type ?? data.authType ?? 'entra',
    display_name:
      data.display_name ??
      data.displayName ??
      data.nombre ??
      normalizedEmail,
  };
};

// ================= AXIOS CLIENT =================

const apiClient = axios.create({
  baseURL: DIMERC_API_BASE_URL,
  timeout: 10000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

apiClient.interceptors.request.use(
  config => config,
  error => {
    console.error('❌ Error en la configuración de la petición:', error);
    return Promise.reject(error);
  }
);

apiClient.interceptors.response.use(
  response => response,
  (error: AxiosError) => {
    console.group('❌ Error de API');

    if (error.response) {
      console.error(`Status: ${error.response.status}`);
      console.error(`URL: ${error.config?.url}`);
      console.error('Response Data:', error.response.data);
      console.error('Response Headers:', error.response.headers);
    } else if (error.request) {
      console.error('No Response received');
      console.error('Request:', error.request);
      console.error('URL:', error.config?.url);
    } else {
      console.error('Request Setup Error:', error.message);
    }

    console.error('Error Code:', error.code);
    console.error('Full Error:', error);
    console.groupEnd();

    return Promise.reject(error);
  }
);

// ================= SERVICE =================

export class ApiUserService {
  private static saveUserDataToStorage(
    email: string,
    userData: ApiUserResponse
  ): void {
    try {
      const normalizedEmail = normalizeEmail(email);
      const normalizedUserData = normalizeApiUserResponse(
        normalizedEmail,
        userData
      );

      const dataToStore: StoredUserData = {
        email: normalizedEmail,
        userData: normalizedUserData,
        timestamp: new Date().toISOString(),
        source: 'dimerc_api',
      };

      localStorage.setItem(STORAGE_KEYS.USER_DATA, JSON.stringify(dataToStore));
      localStorage.setItem(STORAGE_KEYS.USER_EMAIL, normalizedEmail);
      localStorage.setItem(
        STORAGE_KEYS.LAST_VALIDATION,
        new Date().toISOString()
      );
    } catch (error) {
      console.error('❌ Error al guardar en localStorage:', error);
    }
  }

  static getUserDataFromStorage(
    email?: string
  ): StoredUserData | null {
    try {
      const storedData = localStorage.getItem(STORAGE_KEYS.USER_DATA);

      if (!storedData) {
        return null;
      }

      const parsedData = JSON.parse(storedData);

      if (!isStoredUserData(parsedData)) {
        this.clearUserDataFromStorage();
        return null;
      }

      const normalizedEmail = normalizeEmail(email);

      if (
        normalizedEmail &&
        normalizeEmail(parsedData.email) !== normalizedEmail
      ) {
        return null;
      }

      if (isCacheExpired(parsedData.timestamp)) {
        this.clearUserDataFromStorage();
        return null;
      }

      return {
        ...parsedData,
        email: normalizeEmail(parsedData.email),
        userData: normalizeApiUserResponse(
          parsedData.email,
          parsedData.userData
        ),
      };
    } catch (error) {
      console.error('❌ Error al leer localStorage:', error);
      return null;
    }
  }

  static clearUserDataFromStorage(): void {
    try {
      localStorage.removeItem(STORAGE_KEYS.USER_DATA);
      localStorage.removeItem(STORAGE_KEYS.USER_EMAIL);
      localStorage.removeItem(STORAGE_KEYS.LAST_VALIDATION);
    } catch (error) {
      console.error('❌ Error al limpiar localStorage:', error);
    }
  }

  static hasValidStoredData(email: string): boolean {
    return this.getUserDataFromStorage(email) !== null;
  }

  static async getUserData(
    email: string,
    forceRefresh: boolean = false
  ): Promise<ApiUserResponse> {
    const normalizedEmail = normalizeEmail(email);

    if (!normalizedEmail) {
      throw new Error('Correo de usuario inválido.');
    }

    /*
     * Modo local: la API corporativa de Dimerc no está disponible fuera
     * de la organización. El rol y el área vienen siempre del backend
     * del portal, por lo que esta consulta es solo complementaria.
     */
    if (import.meta.env.VITE_LOCAL_DEMO === 'true') {
      throw new Error(
        'API corporativa deshabilitada en modo local.'
      );
    }

    if (!forceRefresh) {
      const storedData = this.getUserDataFromStorage(normalizedEmail);

      if (storedData) {
        return storedData.userData;
      }
    }

    try {
      return await this.validateUserByEmail(normalizedEmail);
    } catch (error) {
      /**
       * Fallback:
       * Si falla API pero existe cache vigente, devolver cache.
       */
      const storedData = this.getUserDataFromStorage(normalizedEmail);

      if (storedData) {
        console.warn(
          '⚠️ No se pudo consultar API. Se usará la información cacheada del usuario.'
        );

        return storedData.userData;
      }

      throw error;
    }
  }

  static async validateUserByEmail(email: string): Promise<ApiUserResponse> {
    const normalizedEmail = normalizeEmail(email);

    try {
      if (!normalizedEmail) {
        throw new Error('Correo de usuario inválido.');
      }

      const safeEmail = encodeURIComponent(normalizedEmail);

      const response = await apiClient.get<ApiUserResponse>(safeEmail);
      const userData = normalizeApiUserResponse(normalizedEmail, response.data);

      this.saveUserDataToStorage(normalizedEmail, userData);

      return userData;
    } catch (error: unknown) {
      console.group('❌ Error al validar usuario en la API');
      console.error('Email:', email);
      console.error('Error completo:', error);

      if (error instanceof AxiosError) {
        if (error.response) {
          const status = error.response.status;
          let errorMessage = `Error ${status}: ${error.response.statusText}`;

          switch (status) {
            case 400:
              errorMessage = 'Solicitud inválida para validar usuario';
              break;
            case 401:
              errorMessage = 'No autorizado para acceder al sistema';
              break;
            case 403:
              errorMessage = 'Usuario no autorizado';
              break;
            case 404:
              errorMessage = 'Usuario no encontrado en el sistema';
              break;
            case 408:
              errorMessage = 'Tiempo de espera agotado al validar usuario';
              break;
            case 429:
              errorMessage = 'Demasiadas solicitudes. Intenta nuevamente más tarde';
              break;
            case 500:
              errorMessage = 'Error interno del servidor';
              break;
            case 502:
            case 503:
            case 504:
              errorMessage = 'Servidor temporalmente no disponible';
              break;
          }

          console.groupEnd();
          throw new Error(errorMessage);
        }

        if (error.request) {
          console.groupEnd();
          throw new Error(
            'No se pudo conectar con el servidor de validación. Verifica la configuración de red, CORS o proxy.'
          );
        }

        console.groupEnd();
        throw new Error(`Error de configuración: ${error.message}`);
      }

      if (error instanceof Error) {
        console.groupEnd();
        throw new Error(`Error inesperado: ${error.message}`);
      }

      console.groupEnd();
      throw new Error('Error inesperado al validar usuario.');
    }
  }
}
